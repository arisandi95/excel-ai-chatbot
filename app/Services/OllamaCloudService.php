<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk memanggil Ollama Cloud API (native Ollama API endpoint).
 * 
 * Menggunakan model "minimax-m3:cloud" via API key dari ollama.com.
 * Endpoint: https://api.ollama.com/api/chat
 */
class OllamaCloudService
{
    protected string $apiUrl;
    protected string $model;
    protected string $apiKey;
    protected bool $enabled;
    protected float $temperature;
    protected int $maxTokens;
    protected int $timeout = 120;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.ollama_cloud.api_url', env('OLLAMA_CLOUD_API_URL', 'https://api.ollama.com')), '/');
        $this->model = config('services.ollama_cloud.model', env('OLLAMA_CLOUD_MODEL', 'minimax-m3:cloud'));
        $this->apiKey = config('services.ollama_cloud.api_key', env('OLLAMA_CLOUD_API_KEY', ''));
        $this->enabled = filter_var(
            config('services.ollama_cloud.enabled', env('USE_OLLAMA_CLOUD', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        $this->temperature = (float) config('services.ollama_cloud.temperature', env('OLLAMA_CLOUD_TEMPERATURE', 0.0));
        $this->maxTokens = (int) config('services.ollama_cloud.max_tokens', env('OLLAMA_CLOUD_MAX_TOKENS', 4096));
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    /**
     * Kirim pertanyaan ke Ollama Cloud API.
     *
     * @param string $question
     * @param array $fileChunks - Array of chunk data dari file Excel
     * @return array ['success' => bool, 'answer' => ?string, 'error' => ?string]
     */
    public function ask(string $question, array $fileChunks): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'error' => 'Ollama Cloud belum diaktifkan atau API key belum dikonfigurasi.',
            ];
        }

        $systemPrompt = 'Anda adalah asisten AI yang HANYA boleh menjawab berdasarkan DATA EKSPLISIT dari file Excel yang diberikan di prompt user. JANGAN PERNAH menggunakan pengetahuan umum atau mengarang informasi. Jika data tidak cukup untuk menjawab, katakan "Maaf, saya tidak menemukan informasi tersebut di dalam file Excel yang dipilih". Jangan pernah membuat-buat angka, nama, atau fakta.';
        $userPrompt = $this->buildPrompt($question, $fileChunks);

        return $this->callChatApi($systemPrompt, $userPrompt);
    }

    /**
     * Bangun prompt dari pertanyaan dan konteks file Excel.
     */
    protected function buildPrompt(string $question, array $fileChunks): string
    {
        $contextText = '';

        if (!empty($fileChunks) && isset($fileChunks[0]) && is_array($fileChunks[0]) && isset($fileChunks[0]['text'])) {
            foreach ($fileChunks as $index => $chunk) {
                $contextText .= "=== Bagian " . ($index + 1) . " ===\n";
                $contextText .= "File: {$chunk['file']}\n";
                $contextText .= "Sheet: {$chunk['sheet']}\n";
                $contextText .= trim($chunk['text']) . "\n";
                $contextText .= "================\n";
            }
        } else {
            foreach ($fileChunks as $fileName => $content) {
                $contextText .= "=== File: {$fileName} ===\n";
                $contextText .= trim($content) . "\n\n";
            }
        }

        $hasContext = $contextText !== '';
        $dataSection = $hasContext ? $contextText : "TIDAK ADA DATA yang cocok dengan pertanyaan.\n\n";

        return trim(
            "Kamu adalah asisten AI yang HARUS menjawab berdasarkan DATA EKSPLISIT di bawah ini.\n" .
            "JANGAN PERNAH menggunakan pengetahuan umum atau data dari luar yang tidak ada di bagian DATA.\n" .
            "JANGAN PERNAH menebak, mengarang, atau menambahkan informasi apapun.\n\n" .
            "=== DATA DARI FILE EXCEL ===\n" .
            $dataSection .
            "=== AKHIR DATA ===\n\n" .
            "INSTRUKSI KETAT (ikuti persis):\n" .
            "1. Jika DATA di atas mengandung informasi untuk menjawab pertanyaan, jawab berdasarkan data tersebut secara detail dan presisi, gunakan bahasa Indonesia.\n" .
            "2. Jika DATA di atas KOSONG atau tidak mengandung informasi untuk menjawab pertanyaan, jawab: \"Maaf, saya tidak menemukan informasi tersebut di dalam file Excel yang dipilih.\" — TIDAK BOLEH menjawab dengan pengetahuan sendiri.\n" .
            "3. Jika pertanyaan adalah sapaan (halo, hai, apa kabar, terima kasih, dll), balas secara singkat dan natural dalam bahasa Indonesia.\n" .
            "4. JANGAN PERNAH membuat-buat angka, nama, tanggal, atau fakta yang tidak ada di DATA di atas.\n" .
            "5. JANGAN PERNAH mengatakan \"berdasarkan data...\" lalu menyebutkan sesuatu yang TIDAK ADA di DATA.\n" .
            "6. Jika ragu, lebih baik menjawab \"Maaf, saya tidak menemukan informasi itu dalam file Excel\" daripada mengarang.\n\n" .
            "Pertanyaan: {$question}\n"
        );
    }

    /**
     * Panggil Ollama Cloud API via /api/chat endpoint (native Ollama format).
     * 
     * Ref: https://github.com/ollama/ollama-js
     * Endpoint: POST https://api.ollama.com/api/chat
     */
    protected function callChatApi(string $systemPrompt, string $userPrompt): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl . '/api/chat', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                    'stream' => false,
                    'options' => [
                        'temperature' => $this->temperature,
                        'num_predict' => $this->maxTokens,
                        'seed' => 42,
                    ],
                ]);

            if (!$response->successful()) {
                $status = $response->status();
                $body = $response->body();
                Log::error('Ollama Cloud API error', [
                    'status' => $status,
                    'body' => $body,
                ]);

                $errorMsg = 'Ollama Cloud API mengembalikan status ' . $status . '.';
                if ($status === 401) {
                    $errorMsg = 'API key Ollama Cloud tidak valid. Periksa konfigurasi API key.';
                } elseif ($status === 429) {
                    $errorMsg = 'Rate limit Ollama Cloud tercapai. Silakan coba lagi nanti.';
                } elseif ($status >= 500) {
                    $errorMsg = 'Ollama Cloud server error (' . $status . '). Silakan coba lagi.';
                }

                return [
                    'success' => false,
                    'error' => $errorMsg,
                ];
            }

            $result = $response->json();
            $answer = null;

            // Ollama API response format: { "message": { "role": "assistant", "content": "..." }, "done": true }
            if (isset($result['message']['content'])) {
                $answer = trim($result['message']['content']);
            }

            // Fallback: jika ada thinking field, keluarkan juga (tapi prioritaskan content)
            if (($answer === null || $answer === '') && isset($result['message']['thinking'])) {
                $answer = trim($result['message']['thinking']);
            }

            if ($answer === null || $answer === '') {
                return [
                    'success' => false,
                    'error' => 'Ollama Cloud mengembalikan hasil kosong.',
                ];
            }

            return [
                'success' => true,
                'answer' => $answer,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Ollama Cloud connection timeout', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Tidak dapat terhubung ke Ollama Cloud. Periksa koneksi internet.',
            ];
        } catch (\Exception $e) {
            Log::error('Gagal memanggil Ollama Cloud', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Gagal menghubungi Ollama Cloud: ' . $e->getMessage(),
            ];
        }
    }
}
