<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\N8nService;
use App\Services\ExcelFileService;
use App\Services\OllamaService;
use App\Services\OllamaCloudService;
use App\Services\RagService;

/**
 * Controller untuk menangani halaman chat dan komunikasi dengan n8n atau Ollama lokal.
 * 
 * Endpoint:
 * - GET /chat      : Menampilkan halaman chat
 * - GET /chat/files: Mendapatkan daftar file Excel tersedia
 * - POST /chat/send: Menerima pertanyaan user dan mengirim ke n8n atau Ollama
 */
class ChatController extends Controller
{
    protected N8nService $n8nService;
    protected ExcelFileService $excelFileService;
    protected OllamaService $ollamaService;
    protected OllamaCloudService $ollamaCloudService;
    protected RagService $ragService;

    public function __construct(N8nService $n8nService, ExcelFileService $excelFileService, OllamaService $ollamaService, OllamaCloudService $ollamaCloudService, RagService $ragService)
    {
        $this->n8nService = $n8nService;
        $this->excelFileService = $excelFileService;
        $this->ollamaService = $ollamaService;
        $this->ollamaCloudService = $ollamaCloudService;
        $this->ragService = $ragService;
    }

    /**
     * Tampilkan halaman utama chat.
     * 
     * GET /chat
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('chat');
    }

    /**
     * Ambil daftar file Excel yang tersedia.
     *
     * GET /chat/files
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function files()
    {
        $excelData = $this->excelFileService->getExcelFiles();

        if (!$excelData['success']) {
            return response()->json([
                'success' => false,
                'files' => [],
                'error' => $excelData['error'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'files' => $excelData['files'],
            'selected_file' => count($excelData['files']) ? $excelData['files'][0] : null,
            'error' => null,
        ], 200);
    }

    /**
     * Terima pertanyaan user, baca file Excel, lalu kirim ke n8n.
     * 
     * POST /chat/send
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function send(Request $request)
    {
        // =====================
        // Validasi input
        // =====================
        $request->validate([
            'question' => 'required|string|min:2|max:1000',
        ], [
            'question.required' => 'Pertanyaan tidak boleh kosong.',
            'question.min'      => 'Pertanyaan minimal 2 karakter.',
            'question.max'      => 'Pertanyaan maksimal 1000 karakter.',
        ]);

        $question = trim($request->input('question'));
        $selectedFile = $request->input('selected_file');

        // =====================
        // Baca daftar file Excel di folder uploads
        // =====================
        $excelData = $this->excelFileService->getExcelFiles();

        if (!$excelData['success']) {
            return response()->json([
                'success' => false,
                'answer'  => null,
                'error'   => $excelData['error'],
            ], 200);
        }

        $files = $excelData['files'];
        $uploadPath = $this->excelFileService->getUploadPath();

        if ($selectedFile !== null && !empty($selectedFile) && !$this->excelFileService->fileExists($selectedFile)) {
            return response()->json([
                'success' => false,
                'answer' => null,
                'error' => 'File yang dipilih tidak ditemukan di folder uploads.',
            ], 200);
        }

        $fileContents = $this->excelFileService->loadExcelContents($files, $selectedFile);

        $ragMethod = config('services.rag.method', env('RAG_METHOD', 'RAG_KEYWORD'));
        if ($ragMethod === 'RAG_PAGEINDEX') {
            $fileChunks = $this->ragService->getRelevantChunksPageIndex($question, $files, $selectedFile);
        } else {
            $fileChunks = $this->ragService->getRelevantChunks($question, $files, $selectedFile);
        }

        // Jika chunk yang relevan tidak ditemukan, coba kirim full konten file agar AI tetap punya konteks
        if (empty($fileChunks) && !empty($fileContents)) {
            // Konversi full konten ke format chunk agar bisa diproses prompt
            $fullContext = [];
            foreach ($fileContents as $fileName => $content) {
                $fullContext[] = [
                    'file' => $fileName,
                    'sheet' => 'All',
                    'chunk_id' => 1,
                    'text' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
                ];
            }
            $fileChunks = $fullContext;
        }

        // Priority: Ollama Cloud > Ollama Local > n8n
        if ($this->ollamaCloudService->isEnabled()) {
            $response = $this->ollamaCloudService->ask($question, $fileChunks);
        } elseif ($this->ollamaService->isEnabled()) {
            $response = $this->ollamaService->ask($question, $fileChunks);
        } else {
            $response = $this->n8nService->sendQuestion($question, $uploadPath, $files, $fileChunks, $fileContents, $selectedFile);
        }

        if (!$response['success']) {
            return response()->json([
                'success' => false,
                'answer'  => null,
                'error'   => $response['error'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'answer'  => $response['answer'],
            'error'   => null,
        ]);
    }
}
