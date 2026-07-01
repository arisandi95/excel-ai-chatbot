<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Service untuk menyediakan retrieval-augmented generation (RAG)
 * dari konten Excel.
 *
 * Service ini memecah konten Excel menjadi chunk kecil, mencari
 * bagian paling relevan berdasarkan pertanyaan, dan hanya mengirimkan
 * konteks yang diperlukan ke model.
 */
class RagService
{
    protected ExcelFileService $excelFileService;
    protected PageIndexService $pageIndexService;
    protected int $chunkSizeRows = 15;
    protected int $chunkMaxLength = 2000;
    protected int $maxRelevantChunks = 5;

    public function __construct(ExcelFileService $excelFileService, PageIndexService $pageIndexService)
    {
        $this->excelFileService = $excelFileService;
        $this->pageIndexService = $pageIndexService;
    }

    /**
     * Dapatkan chunk Excel yang paling relevan untuk pertanyaan.
     *
     * @param string $question
     * @param array $files
     * @param string|null $selectedFile
     * @return array
     */
    public function getRelevantChunks(string $question, array $files, ?string $selectedFile = null): array
    {
        $chunks = $this->excelFileService->loadExcelTextChunks($files, $selectedFile, $this->chunkSizeRows, $this->chunkMaxLength);

        if (empty($chunks)) {
            return [];
        }

        $queryTerms = $this->extractTerms($question);
        // Hanya filter kata yang benar-benar tidak bermakna, pertahankan kata kunci penting seperti kategori, produk
        $queryTerms = array_filter($queryTerms, fn($term) => strlen($term) > 1 && !in_array($term, ['ada', 'apa', 'saja', 'dan', 'di', 'ke', 'yang', 'ini', 'itu', 'dengan', 'untuk', 'pada', 'dari', 'oleh', 'atau', 'saya', 'kami', 'kita', 'anda']));
        $scored = [];

        foreach ($chunks as $chunk) {
            $score = $this->scoreChunk($chunk['text'], $queryTerms);
            $scored[] = [
                'score' => $score,
                'chunk' => $chunk,
            ];
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $topScored = array_slice($scored, 0, $this->maxRelevantChunks);

        if (empty($topScored) || $topScored[0]['score'] === 0) {
            // Jika tidak ada kecocokan sama sekali, jangan kirim chunk yang tidak relevan.
            // Biarkan LLM tahu bahwa tidak ada data yang cocok.
            return [];
        }

        return array_column($topScored, 'chunk');
    }

    /**
     * Dapatkan chunk Excel yang paling relevan menggunakan PageIndex-style tree retrieval.
     *
     * @param string $question
     * @param array $files
     * @param string|null $selectedFile
     * @return array
     */
    public function getRelevantChunksPageIndex(string $question, array $files, ?string $selectedFile = null): array
    {
        $chunks = [];

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->excelFileService->fileExists($file)) {
                continue;
            }

            try {
                $tree = $this->pageIndexService->buildTree($file);
                $chunks = array_merge($chunks, $this->pageIndexService->reasoningRetrieval($question, $tree));
            } catch (\Throwable $e) {
                Log::error('Gagal memproses PageIndex untuk file Excel', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_slice($chunks, 0, $this->maxRelevantChunks);
    }

    /**
     * Skor kesesuaian chunk berdasarkan overlap kata kunci.
     *
     * @param string $text
     * @param array $queryTerms
     * @return int
     */
    protected function scoreChunk(string $text, array $queryTerms): int
    {
        if (empty($queryTerms)) {
            return 0;
        }

        $chunkTerms = $this->extractTerms($text);
        $score = 0;

        foreach ($queryTerms as $term) {
            if (in_array($term, $chunkTerms, true)) {
                $score += 2;
            }
        }

        foreach ($queryTerms as $term) {
            if (str_contains($text, $term)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * Extract terms from text for simple retrieval scoring.
     *
     * @param string $text
     * @return array
     */
    protected function extractTerms(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        $terms = array_filter(array_map('trim', explode(' ', $normalized)));
        $terms = array_unique($terms);
        $terms = array_values($terms);

        return $terms;
    }

    /**
     * Jika pertanyaan dapat dijawab langsung dari Excel, cari jawabannya.
     *
     * @param string $question
     * @param array $files
     * @param string|null $selectedFile
     * @return string|null
     */
    public function getDirectExcelAnswer(string $question, array $files, ?string $selectedFile = null): ?string
    {
        $listResult = $this->excelFileService->listValuesByQuestion($question, $files, $selectedFile);
        if (!empty($listResult)) {
            return 'Dari file Excel, daftar ' . $listResult['header'] . ': ' . implode(', ', $listResult['values']);
        }

        $rows = $this->excelFileService->findRowsMatchingQuestion($question, $files, $selectedFile);

        if (empty($rows)) {
            return null;
        }

        $answers = [];
        foreach ($rows as $row) {
            $filtered = [];
            foreach ($row as $key => $value) {
                if (in_array($key, ['__sheet', '__file', '__score'], true)) {
                    continue;
                }
                $filtered[] = "{$key}: {$value}";
            }
            if (!empty($filtered)) {
                $answers[] = implode(' | ', $filtered);
            }
        }

        if (empty($answers)) {
            return null;
        }

        return 'Dari file Excel, jawaban yang relevan: ' . implode(' || ', array_unique($answers));
    }

    
}
