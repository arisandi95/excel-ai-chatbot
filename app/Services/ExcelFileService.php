<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Service untuk membaca dan mengelola file Excel di folder uploads.
 * 
 * Service ini mendeteksi file Excel (.xlsx, .xls, .csv) yang tersedia
 * di folder uploads dan mengubahnya menjadi JSON/text untuk konteks AI.
 */
class ExcelFileService
{
    /**
     * Path absolut ke folder uploads.
     */
    protected string $uploadPath;

    /**
     * Ekstensi file Excel yang didukung.
     */
    protected array $allowedExtensions = ['xlsx', 'xls', 'csv'];

    public function __construct()
    {
        // Path folder uploads dari konfigurasi, default ke 'uploads'
        $relativePath = config('services.n8n.upload_path', env('UPLOAD_EXCEL_PATH', 'uploads'));
        $this->uploadPath = base_path($relativePath);
    }

    /**
     * Dapatkan path absolut folder uploads.
     *
     * @return string
     */
    public function getUploadPath(): string
    {
        return $this->uploadPath;
    }

    /**
     * Ambil daftar semua file Excel yang tersedia di folder uploads.
     *
     * @return array Daftar nama file (string) atau array error
     */
    public function getExcelFiles(): array
    {
        // Cek apakah folder uploads ada
        if (!File::isDirectory($this->uploadPath)) {
            Log::warning('Folder uploads tidak ditemukan', ['path' => $this->uploadPath]);
            return [
                'success' => false,
                'error'   => 'Folder uploads tidak ditemukan. Buat folder "' . $this->uploadPath . '" dan letakkan file Excel di dalamnya.',
                'files'   => [],
            ];
        }

        // Ambil semua file di folder uploads
        $allFiles = File::files($this->uploadPath);

        if (empty($allFiles)) {
            return [
                'success' => false,
                'error'   => 'Folder uploads kosong. Silakan upload file Excel (.xlsx, .xls, .csv) ke folder "' . $this->uploadPath . '".',
                'files'   => [],
            ];
        }

        // Filter hanya file Excel berdasarkan ekstensi
        $excelFiles = [];
        foreach ($allFiles as $file) {
            $extension = strtolower($file->getExtension());
            if (in_array($extension, $this->allowedExtensions)) {
                $excelFiles[] = $file->getFilename();
            }
        }

        if (empty($excelFiles)) {
            return [
                'success' => false,
                'error'   => 'Tidak ada file Excel (.xlsx, .xls, .csv) yang ditemukan di folder uploads.',
                'files'   => [],
            ];
        }

        Log::info('File Excel ditemukan', ['files' => $excelFiles]);

        return [
            'success' => true,
            'error'   => null,
            'files'   => $excelFiles,
        ];
    }

    /**
     * Ambil isi file Excel sebagai string JSON/text.
     *
     * @param array $files
     * @param string|null $selectedFile
     * @return array
     */
    public function loadExcelContents(array $files, ?string $selectedFile = null): array
    {
        $contents = [];

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->fileExists($file)) {
                continue;
            }

            try {
                $contents[$file] = $this->parseFile($file);
            } catch (\Throwable $e) {
                Log::error('Gagal mem-parsing file Excel', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                $contents[$file] = 'Gagal memuat konten file Excel: ' . $e->getMessage();
            }
        }

        return $contents;
    }

    /**
     * Ambil chunk teks dari file Excel untuk RAG.
     *
     * @param array $files
     * @param string|null $selectedFile
     * @param int $chunkSizeRows
     * @param int $chunkMaxLength
     * @return array
     */
    public function loadExcelTextChunks(array $files, ?string $selectedFile = null, int $chunkSizeRows = 20, int $chunkMaxLength = 2000): array
    {
        $chunks = [];

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->fileExists($file)) {
                continue;
            }

            try {
                $fileChunks = $this->parseFileChunks($file, $chunkSizeRows, $chunkMaxLength);
                $chunks = array_merge($chunks, $fileChunks);
            } catch (\Throwable $e) {
                Log::error('Gagal mem-parsing file Excel untuk chunking', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $chunks;
    }

    /**
     * Parse file Excel menjadi daftar chunk teks kecil.
     *
     * @param string $filename
     * @param int $chunkSizeRows
     * @param int $chunkMaxLength
     * @return array
     */
    protected function parseFileChunks(string $filename, int $chunkSizeRows, int $chunkMaxLength): array
    {
        $filePath = $this->uploadPath . DIRECTORY_SEPARATOR . $filename;
        $spreadsheet = IOFactory::load($filePath);
        $chunks = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $rowIterator = $sheet->getRowIterator();
            $chunkRows = [];
            $chunkTextLength = 0;
            $chunkIndex = 1;
                $headers = [];
                $isHeaderRow = true;

                foreach ($rowIterator as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);

                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $rowData[] = trim((string) $cell->getCalculatedValue());
                    }

                    if ($isHeaderRow) {
                        $headers = array_map(fn($value) => $value === '' ? 'kolom' : $value, $rowData);
                        $isHeaderRow = false;
                        continue;
                    }

                    if (empty(array_filter($rowData, fn ($value) => $value !== ''))) {
                        continue;
                    }

                    $rowTextParts = [];
                    foreach ($rowData as $index => $value) {
                        $columnName = $headers[$index] ?? 'kolom_' . ($index + 1);
                        if ($value !== '') {
                            $rowTextParts[] = "{$columnName}: {$value}";
                        }
                    }

                    $rowText = implode(' | ', $rowTextParts);

                if (count($chunkRows) >= $chunkSizeRows || $chunkTextLength >= $chunkMaxLength) {
                    $chunks[] = [
                        'file' => $filename,
                        'sheet' => $sheetTitle,
                        'chunk_id' => $chunkIndex,
                        'text' => implode("\n", $chunkRows),
                    ];

                    $chunkIndex++;
                    $chunkRows = [];
                    $chunkTextLength = 0;
                }
            }

            if (!empty($chunkRows)) {
                $chunks[] = [
                    'file' => $filename,
                    'sheet' => $sheetTitle,
                    'chunk_id' => $chunkIndex,
                    'text' => implode("\n", $chunkRows),
                ];
            }
        }

        return $chunks;
    }

    /**
     * Parse file Excel menjadi string JSON.
     *
     * @param string $filename
     * @return string
     */
    protected function parseFile(string $filename): string
    {
        $filePath = $this->uploadPath . DIRECTORY_SEPARATOR . $filename;

        $spreadsheet = IOFactory::load($filePath);
        $result = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = (string) $cell->getCalculatedValue();
                }

                $rows[] = $rowData;
            }

            $result[$sheetTitle] = $rows;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Cek apakah file tertentu ada di folder uploads.
     *
     * @param string $filename Nama file yang dicek
     * @return bool
     */
    public function fileExists(string $filename): bool
    {
        $filePath = $this->uploadPath . DIRECTORY_SEPARATOR . $filename;
        return File::exists($filePath) && !File::isDirectory($filePath);
    }

    /**
     * Ambil semua row Excel dengan key header.
     *
     * @param string $filename
     * @return array<int, array<string, string>>
     */
    protected function parseFileRows(string $filename): array
    {
        $filePath = $this->uploadPath . DIRECTORY_SEPARATOR . $filename;
        $spreadsheet = IOFactory::load($filePath);
        $rows = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $rowIterator = $sheet->getRowIterator();
            $headers = [];
            $isHeaderRow = true;

            foreach ($rowIterator as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = trim((string) $cell->getCalculatedValue());
                }

                if ($isHeaderRow) {
                    $headers = array_map(fn($value) => $value === '' ? 'kolom' : $value, $rowData);
                    $isHeaderRow = false;
                    continue;
                }

                if (empty(array_filter($rowData, fn ($value) => $value !== ''))) {
                    continue;
                }

                $rowMap = ['__sheet' => $sheetTitle, '__file' => $filename];
                foreach ($rowData as $index => $value) {
                    $key = $headers[$index] ?? 'kolom_' . ($index + 1);
                    $rowMap[$key] = $value;
                }

                $rows[] = $rowMap;
            }
        }

        return $rows;
    }

    /**
     * Temukan produk berdasarkan nama kategori.
     *
     * @param string $category
     * @param array $files
     * @param string|null $selectedFile
     * @return array<string>
     */
    public function findProductsByCategory(string $category, array $files, ?string $selectedFile = null): array
    {
        $products = [];
        $category = mb_strtolower(trim($category));

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->fileExists($file)) {
                continue;
            }

            try {
                $rows = $this->parseFileRows($file);
            } catch (\Throwable $e) {
                Log::error('Gagal mem-parsing file Excel untuk pencarian kategori', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($rows as $row) {
                $categoryColumn = $this->findColumnKey($row, ['kategori', 'category', 'jenis']);
                $productColumn = $this->findColumnKey($row, ['produk', 'product', 'nama', 'item', 'barang']);

                if ($categoryColumn === null || $productColumn === null) {
                    continue;
                }

                $rowCategory = mb_strtolower(trim($row[$categoryColumn] ?? ''));
                $rowProduct = trim($row[$productColumn] ?? '');

                if ($rowProduct === '' || $rowCategory === '') {
                    continue;
                }

                if ($rowCategory === $category) {
                    $products[] = $rowProduct;
                }
            }
        }

        return array_values(array_unique($products));
    }

    /**
     * Temukan baris Excel yang relevan dengan pertanyaan.
     *
     * @param string $question
     * @param array $files
     * @param string|null $selectedFile
     * @param int $limit
     * @return array<int, array<string, string>>
     */
    public function findRowsMatchingQuestion(string $question, array $files, ?string $selectedFile = null, int $limit = 5): array
    {
        $questionTerms = $this->tokenizeText($question);
        $nonFieldTerms = $this->extractNonFieldTerms($questionTerms);
        $candidateRows = [];

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->fileExists($file)) {
                continue;
            }

            try {
                $rows = $this->parseFileRows($file);
            } catch (\Throwable $e) {
                Log::error('Gagal mem-parsing file Excel untuk pencarian umum', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($rows as $row) {
                $score = 0;
                $hasNonFieldMatch = false;

                foreach ($row as $key => $value) {
                    if (in_array($key, ['__sheet', '__file'], true)) {
                        continue;
                    }

                    $headerScore = $this->scoreTextMatch($key, $questionTerms, 2, 4);
                    $valueScore = $this->scoreTextMatch($value, $questionTerms, 3, 5);
                    $score += $headerScore + $valueScore;

                    if (!$hasNonFieldMatch && !empty($nonFieldTerms)) {
                        if ($this->hasTermMatch($key, $nonFieldTerms) || $this->hasTermMatch($value, $nonFieldTerms)) {
                            $hasNonFieldMatch = true;
                        }
                    }
                }

                if (!empty($nonFieldTerms) && !$hasNonFieldMatch) {
                    continue;
                }

                if ($score > 0) {
                    $row['__score'] = $score;
                    $candidateRows[] = $row;
                }
            }
        }

        usort($candidateRows, fn($a, $b) => $b['__score'] <=> $a['__score']);
        $candidateRows = array_slice($candidateRows, 0, $limit);

        return array_map(fn($row) => array_diff_key($row, array_flip(['__score'])), $candidateRows);
    }

    protected function tokenizeText(string $text): array
    {
        $normalized = $this->normalizeText($text);
        return array_filter(array_map('trim', explode(' ', $normalized)), fn($term) => $term !== '');
    }

    protected function scoreTextMatch(string $text, array $questionTerms, int $partialWeight, int $exactWeight): int
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return 0;
        }

        $tokens = $this->tokenizeText($normalized);
        $score = 0;

        foreach ($questionTerms as $term) {
            foreach ($tokens as $token) {
                if ($term === $token) {
                    $score += $exactWeight;
                    continue;
                }
                if ($term !== '' && (str_contains($token, $term) || str_contains($term, $token))) {
                    $score += $partialWeight;
                }
            }
        }

        return $score;
    }

    protected function extractNonFieldTerms(array $questionTerms): array
    {
        $fieldTerms = [
            'harga', 'total', 'qty', 'quantity', 'jumlah', 'produk', 'product', 'kategori', 'category', 'invoice', 'nomor', 'no', 'tanggal', 'date', 'sales', 'penjualan', 'item', 'barang', 'stok', 'tersedia', 'satuan', 'nama'
        ];

        return array_values(array_filter($questionTerms, fn($term) => $term !== '' && !in_array($term, $fieldTerms, true)));
    }

    protected function hasTermMatch(string $text, array $terms): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        $tokens = $this->tokenizeText($normalized);
        foreach ($terms as $term) {
            foreach ($tokens as $token) {
                if ($term === $token || str_contains($token, $term) || str_contains($term, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function listValuesByQuestion(string $question, array $files, ?string $selectedFile = null, int $limit = 20): array
    {
        if (!$this->isListQuestion($question)) {
            return [];
        }

        $questionNormalized = $this->normalizeText($question);
        $questionTerms = array_filter(array_map('trim', explode(' ', $questionNormalized)));

        $headers = [];
        $rows = [];

        foreach ($files as $file) {
            if ($selectedFile !== null && $file !== $selectedFile) {
                continue;
            }

            if (!$this->fileExists($file)) {
                continue;
            }

            try {
                $fileRows = $this->parseFileRows($file);
            } catch (\Throwable $e) {
                Log::error('Gagal mem-parsing file Excel untuk pencarian daftar kolom', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($fileRows as $row) {
                $rows[] = $row;
                foreach ($row as $key => $value) {
                    if (in_array($key, ['__sheet', '__file'], true)) {
                        continue;
                    }
                    $headers[$key] = true;
                }
            }
        }

        if (empty($headers)) {
            return [];
        }

        $headerScores = [];
        foreach (array_keys($headers) as $header) {
            $headerScores[$header] = $this->scoreHeaderAgainstQuestion($header, $questionNormalized, $questionTerms);
        }

        arsort($headerScores);
        $bestHeader = array_key_first($headerScores);
        if ($headerScores[$bestHeader] <= 0) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            if (!isset($row[$bestHeader])) {
                continue;
            }
            $value = trim((string) $row[$bestHeader]);
            if ($value === '') {
                continue;
            }
            $normalizedValue = $this->normalizeText($value);
            if ($normalizedValue === '') {
                continue;
            }
            $values[$normalizedValue] = $value;
            if (count($values) >= $limit) {
                break;
            }
        }

        if (empty($values)) {
            return [];
        }

        return [
            'header' => $bestHeader,
            'values' => array_values($values),
        ];
    }

    protected function isListQuestion(string $question): bool
    {
        $normalized = $this->normalizeText($question);
        return preg_match('/\b(apa saja|daftar|list|semua|yang dijual|yang tersedia|yang ada)\b/u', $normalized) === 1;
    }

    protected function scoreHeaderAgainstQuestion(string $header, string $questionNormalized, array $questionTerms): int
    {
        $headerNormalized = $this->normalizeText($header);
        if ($headerNormalized === '') {
            return 0;
        }

        $score = 0;
        if (str_contains($questionNormalized, $headerNormalized)) {
            $score += 5;
        }

        foreach ($questionTerms as $term) {
            if ($term === '') {
                continue;
            }
            if (str_contains($headerNormalized, $term)) {
                $score += 2;
            }
        }

        return $score;
    }

    protected function normalizeText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return trim($normalized);
    }

    protected function findColumnKey(array $row, array $candidates): ?string
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['__sheet', '__file'], true)) {
                continue;
            }

            $normalized = mb_strtolower($key);
            foreach ($candidates as $candidate) {
                if (str_contains($normalized, mb_strtolower($candidate))) {
                    return $key;
                }
            }
        }

        return null;
    }
}