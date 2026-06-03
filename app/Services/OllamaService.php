<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk memanggil Ollama local.
 */
class OllamaService
{
    protected string $apiUrl;
    protected string $model;
    protected bool $enabled;
    protected float $temperature;
    protected int $maxTokens;
    protected bool $useHttp;
    protected int $timeout = 90;

    public function __construct()
    {
        $this->apiUrl = config('services.ollama.api_url', env('OLLAMA_API_URL', 'http://127.0.0.1:11434'));
        $this->model = config('services.ollama.model', env('OLLAMA_MODEL', 'llama2'));
        $this->enabled = filter_var(config('services.ollama.enabled', env('USE_LOCAL_OLLAMA', false)), FILTER_VALIDATE_BOOLEAN);
        $this->temperature = (float) config('services.ollama.temperature', env('OLLAMA_TEMPERATURE', 0.0));
        $this->maxTokens = (int) config('services.ollama.max_tokens', env('OLLAMA_MAX_TOKENS', 2048));
        $this->useHttp = filter_var(config('services.ollama.use_http', env('OLLAMA_USE_HTTP', true)), FILTER_VALIDATE_BOOLEAN);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function ask(string $question, array $fileContexts): array
    {
        if (!$this->enabled) {
            return [
                'success' => false,
                'error' => 'Ollama local belum diaktifkan.',
            ];
        }

        $prompt = $this->buildPrompt($question, $fileContexts);

        if ($this->useHttp) {
            $response = $this->callChatHttp($prompt);
            if ($response['success']) {
                return $response;
            }
            return $this->callHttp($prompt);
        }

        return $this->callCli($prompt);
    }

    protected function buildPrompt(string $question, array $fileContexts): string
    {
        $contextText = '';

        if (!empty($fileContexts) && isset($fileContexts[0]) && is_array($fileContexts[0]) && isset($fileContexts[0]['text'])) {
            foreach ($fileContexts as $index => $chunk) {
                $contextText .= "=== Bagian " . ($index + 1) . " ===\n";
                $contextText .= "File: {$chunk['file']}\n";
                $contextText .= "Sheet: {$chunk['sheet']}\n";
                $contextText .= trim($chunk['text']) . "\n";
                $contextText .= "================\n";
            }
        } else {
            foreach ($fileContexts as $fileName => $content) {
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

    protected function callHttp(string $prompt): array
    {
        try {
            $response = Http::retry(1, 2000)
                ->timeout($this->timeout)
                ->post(rtrim($this->apiUrl, '/') . '/v1/completions', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'temperature' => 0.0,
                    'top_p' => 0.1,
                    'max_tokens' => $this->maxTokens,
                ]);

            if (!$response->successful()) {
                Log::error('Ollama HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => 'Ollama HTTP mengembalikan status ' . $response->status() . '.',
                ];
            }

            $result = $response->json();
            $answer = null;

            if (isset($result['choices'][0]['text'])) {
                $answer = trim($result['choices'][0]['text']);
            }

            if (isset($result['choices'][0]['message']['content'])) {
                $answer = trim($result['choices'][0]['message']['content']);
            }

            if ($answer === null || $answer === '') {
                $chatResponse = $this->callChatHttp($prompt);
                if ($chatResponse['success']) {
                    return $chatResponse;
                }

                return [
                    'success' => false,
                    'error' => 'Ollama mengembalikan hasil kosong.',
                ];
            }

            return [
                'success' => true,
                'answer' => $answer,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Ollama connection timeout atau gagal', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Ollama local tidak merespons dalam waktu yang ditentukan. Pastikan Ollama berjalan dan model sudah dimuat.',
            ];
        } catch (\Exception $e) {
            Log::error('Gagal memanggil Ollama local', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Gagal menghubungi Ollama local: ' . $e->getMessage(),
            ];
        }
    }

    protected function callChatHttp(string $prompt): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post(rtrim($this->apiUrl, '/') . '/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Anda adalah asisten AI yang HANYA boleh menjawab berdasarkan DATA EKSPLISIT dari file Excel yang diberikan di prompt user. JANGAN PERNAH menggunakan pengetahuan umum atau mengarang informasi. Jika data tidak cukup untuk menjawab, katakan "Maaf, saya tidak menemukan informasi tersebut di dalam file Excel yang dipilih". Jangan pernah membuat-buat angka, nama, atau fakta.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.0,
                    'top_p' => 0.1,
                    'max_tokens' => $this->maxTokens,
                    'seed' => 42, // consistent seed for deterministic output
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Ollama chat HTTP mengembalikan status ' . $response->status() . '.',
                ];
            }

            $result = $response->json();
            $answer = null;
            if (isset($result['choices'][0]['message']['content'])) {
                $answer = trim($result['choices'][0]['message']['content']);
            }

            if ($answer === null || $answer === '') {
                return [
                    'success' => false,
                    'error' => 'Ollama chat mengembalikan hasil kosong.',
                ];
            }

            return [
                'success' => true,
                'answer' => $answer,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Gagal memanggil Ollama chat API: ' . $e->getMessage(),
            ];
        }
    }

    protected function callCli(string $prompt): array
    {
        $command = 'ollama generate ' . escapeshellarg($this->model) . ' ' . escapeshellarg($prompt);
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            Log::error('Ollama CLI gagal', ['exit_code' => $exitCode, 'output' => $output]);

            return [
                'success' => false,
                'error' => 'Ollama CLI gagal dijalankan. Pastikan Ollama terpasang dan PATH terkonfigurasi.',
            ];
        }

        $answer = implode("\n", $output);

        return [
            'success' => true,
            'answer' => trim($answer),
        ];
    }
}
