<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk berkomunikasi dengan webhook n8n.
 * 
 * Service ini bertanggung jawab mengirim pertanyaan user
 * beserta konteks file Excel ke n8n untuk diproses oleh AI workflow.
 */
class N8nService
{
    /**
     * URL webhook n8n yang akan dipanggil.
     */
    protected string $webhookUrl;

    /**
     * Timeout request dalam detik.
     */
    protected int $timeout = 120;

    public function __construct()
    {
        // Ambil URL webhook dari konfigurasi .env
        $this->webhookUrl = config('services.n8n.webhook_url', env('N8N_WEBHOOK_URL', 'http://localhost:5678/webhook/excel-chat'));
    }

    /**
     * Kirim pertanyaan user ke webhook n8n.
     *
     * @param string $question Pertanyaan dari user
     * @param string $uploadPath Path absolut folder uploads
     * @param array $files Daftar nama file Excel yang tersedia
     * @param array $fileContents Konten file Excel yang telah diparsing
     * @param string|null $selectedFile Nama file yang dipilih user
     * @return array Response dari n8n berisi answer
     */
    public function sendQuestion(string $question, string $uploadPath, array $files, array $fileChunks = [], array $fileContents = [], ?string $selectedFile = null): array
    {
        // Payload yang dikirim ke n8n sesuai format yang diminta
        $payload = [
            'question'     => $question,
            'upload_path'  => $uploadPath,
            'files'        => $files,
            'file_chunks'  => $fileChunks,
            'files_data'   => $fileContents,
            'selected_file'=> $selectedFile,
        ];

        Log::info('Mengirim pertanyaan ke n8n', [
            'url'     => $this->webhookUrl,
            'payload' => $payload,
        ]);

        try {
            // Kirim POST request ke webhook n8n
            $response = Http::timeout($this->timeout)
                ->post($this->webhookUrl, $payload);

            // Cek apakah response sukses (status 2xx)
            if ($response->successful()) {
                $result = $response->json();

                Log::info('Response dari n8n berhasil diterima', ['result' => $result]);

                // Validasi struktur response
                if (!isset($result['answer'])) {
                    return [
                        'success' => false,
                        'error'   => 'Response dari n8n tidak mengandung field "answer".',
                    ];
                }

                return [
                    'success' => true,
                    'answer'  => $result['answer'],
                ];
            }

            // Jika response gagal (4xx/5xx)
            Log::error('n8n mengembalikan status error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'error'   => "n8n mengembalikan status HTTP {$response->status()}.",
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Error koneksi (n8n tidak aktif atau unreachable)
            Log::error('Gagal terhubung ke n8n', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error'   => 'Gagal terhubung ke n8n. Pastikan n8n sedang berjalan di ' . $this->webhookUrl . '.',
            ];
        } catch (\Exception $e) {
            // Error umum lainnya
            Log::error('Error saat komunikasi dengan n8n', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error'   => 'Terjadi kesalahan saat menghubungi n8n: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Uji koneksi ke webhook n8n (GET request).
     *
     * @return array Status koneksi
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout(5)->get($this->webhookUrl);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Koneksi ke n8n berhasil.'];
            }

            return ['success' => false, 'message' => "n8n mengembalikan status {$response->status()}."];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Gagal terhubung ke n8n: ' . $e->getMessage()];
        }
    }
}