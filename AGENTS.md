# AI Chatbot Excel - Agent Documentation

## Project Overview

A **Laravel 12**-based AI chatbot that answers user questions by reading **Microsoft Excel files** (.xlsx, .xls, .csv). It supports three AI backends with automatic fallback: **Ollama Cloud** → **Ollama Local** → **n8n Workflow**.

## Architecture

### Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 12 (PHP 8.3+) |
| Frontend | Blade Templates, Tailwind CSS, Alpine.js / jQuery |
| Excel Parsing | PhpSpreadsheet (`maatwebsite/excel`) |
| HTTP Client | Laravel `Http` facade |
| AI Backends | Ollama Cloud API, Ollama Local (HTTP/CLI), n8n Webhook |

### Directory Structure

```
ai_chatbot/
├── app/
│   ├── Http/Controllers/
│   │   ├── ChatController.php      # Main chat handler
│   │   └── AdminController.php     # File management (upload/delete)
│   └── Services/
│       ├── ExcelFileService.php    # Excel reading & chunking & search
│       ├── N8nService.php          # n8n webhook communication
│       ├── OllamaService.php       # Ollama local (HTTP + CLI fallback)
│       ├── OllamaCloudService.php  # Ollama Cloud API (api.ollama.com)
│       └── RagService.php         # RAG scoring & relevance matching
├── config/
│   └── services.php                # n8n, Ollama, Ollama Cloud config keys
├── routes/
│   └── web.php                     # Route definitions
├── resources/views/                # Blade templates
├── uploads/                        # Excel files directory
└── .env.example                    # Environment configuration template
```

## API Endpoints

| Method | Path | Controller Method | Description |
|--------|------|-------------------|-------------|
| GET | `/` | — | Redirects to `/chat` |
| GET | `/chat` | `ChatController@index` | Main chat page |
| GET | `/chat/files` | `ChatController@files` | List available Excel files |
| POST | `/chat/send` | `ChatController@send` | Send user question, get AI answer |
| GET | `/admin` | `AdminController@index` | Admin page — manage Excel files |
| POST | `/admin/upload` | `AdminController@upload` | Upload Excel file (max 50MB) |
| POST | `/admin/delete` | `AdminController@delete` | Delete Excel file |

## Data Flow

```
User Question (POST /chat/send)
       │
       ▼
ChatController@send
       │
       ├── ExcelFileService::getExcelFiles()
       │     └── SCAN uploads/ folder for .xlsx/.xls/.csv
       │
       ├── ExcelFileService::loadExcelContents()
       │     └── PARSE selected files into JSON/text
       │
       ├── RagService::getRelevantChunks()
       │     └── CHUNK Excel rows → SCORE relevance → RETURN top chunks
       │
       └── AI Backend (priority order):
            1. OllamaCloudService::ask()   [if enabled & API key set]
            2. OllamaService::ask()         [if enabled]
            3. N8nService::sendQuestion()   [fallback]
                 │
                 ▼
            Response { success, answer }
```

## AI Backend Priority

The system uses a strict fallback chain in `ChatController@send`:

1. **Ollama Cloud** — `OllamaCloudService`
   - Endpoint: `https://api.ollama.com/api/chat`
   - Model: `minimax-m3:cloud` (configurable)
   - Auth: Bearer token via `OLLAMA_CLOUD_API_KEY`
   - Config:
     - `USE_OLLAMA_CLOUD=true`
     - `OLLAMA_CLOUD_API_KEY=your-key`
     - `OLLAMA_CLOUD_MODEL=your-model`

2. **Ollama Local** — `OllamaService`
   - Method: HTTP (`/v1/chat/completions` or `/v1/completions`) → CLI fallback (`ollama generate`)
   - Default endpoint: `http://127.0.0.1:11434`
   - Default model: `llama2`
   - Config:
     - `USE_LOCAL_OLLAMA=true`
     - `OLLAMA_API_URL=http://127.0.0.1:11434`
     - `OLLAMA_MODEL=llama2`

3. **n8n** — `N8nService`
   - Method: HTTP POST to n8n webhook
   - Default: `http://localhost:5678/webhook/excel-chat`
   - Payload: `{ question, upload_path, files, file_chunks, files_data, selected_file }`
   - Config:
     - `N8N_WEBHOOK_URL=http://localhost:5678/webhook/excel-chat`
     - `UPLOAD_EXCEL_PATH=uploads`

## Service Details

### 1. ExcelFileService
**File:** `app/Services/ExcelFileService.php`

Core service for reading, parsing, and searching Excel files.

**Key Methods:**
- `getExcelFiles()` — Scan `uploads/` directory for `.xlsx`, `.xls`, `.csv` files.
- `loadExcelContents($files, $selectedFile)` — Parse Excel files into JSON string.
- `loadExcelTextChunks($files, $selectedFile, $chunkSizeRows, $chunkMaxLength)` — Parse Excel into text chunks for RAG. Default: 20 rows per chunk, 2000 max chars.
- `fileExists($filename)` — Check if file exists in uploads.
- `findProductsByCategory($category, $files, $selectedFile)` — Search products by category name.
- `findRowsMatchingQuestion($question, $files, $selectedFile, $limit)` — Score-based row search with term matching.
- `listValuesByQuestion($question, $files, $selectedFile, $limit)` — Natural language list questions (e.g., "apa saja produk yang dijual?").

**Key Features:**
- Supports multi-sheet Excel files
- Header-aware column mapping (detects first row as headers)
- Unicode normalization (`mb_strtolower`, `preg_replace` for non-alphanumeric)
- Scoring system: exact match = higher weight, partial match = lower weight

### 2. N8nService
**File:** `app/Services/N8nService.php`

HTTP client for n8n webhook integration.

**Key Methods:**
- `sendQuestion($question, $uploadPath, $files, $fileChunks, $fileContents, $selectedFile)` — POST to n8n with full payload.
- `testConnection()` — GET to verify n8n is reachable.

**Payload Structure:**
```json
{
  "question": "Apa total penjualan bulan Januari?",
  "upload_path": "/var/www/uploads",
  "files": ["sales.xlsx"],
  "file_chunks": [
    { "file": "sales.xlsx", "sheet": "Sheet1", "chunk_id": 1, "text": "..." }
  ],
  "files_data": { "sales.xlsx": "[{\"Sheet1\": [[...], [...]]}]" },
  "selected_file": "sales.xlsx"
}
```

**Expected Response:**
```json
{ "answer": "Total penjualan bulan Januari adalah Rp 50.000.000" }
```

### 3. OllamaService
**File:** `app/Services/OllamaService.php`

Local Ollama integration with HTTP and CLI fallback.

**Key Methods:**
- `ask($question, $fileContexts)` — Send question with Excel context to local Ollama.
- `isEnabled()` — Check if local Ollama is enabled.

**Communication Modes:**
1. **`callChatHttp`** — POST to `/v1/chat/completions` (OpenAI-compatible chat endpoint)
2. **`callHttp`** — POST to `/v1/completions` (fallback completions endpoint)
3. **`callCli`** — Executes `ollama generate` command (last resort)

**Prompt Structure:**
Built by `buildPrompt()` — strict instruction system:
- Must answer ONLY from provided Excel data
- Must NOT use general knowledge or fabricate information
- If data is insufficient, reply: *"Maaf, saya tidak menemukan informasi tersebut di dalam file Excel yang dipilih."*
- Greetings/small talk are handled naturally in Indonesian

### 4. OllamaCloudService
**File:** `app/Services/OllamaCloudService.php`

Cloud-based Ollama integration via `api.ollama.com`.

**Key Methods:**
- `ask($question, $fileChunks)` — Send question with Excel chunks to Ollama Cloud.
- `isEnabled()` — Check if enabled AND API key is configured.

**API Details:**
- Endpoint: `{apiUrl}/api/chat`
- Method: POST
- Headers: `Authorization: Bearer {apiKey}`, `Content-Type: application/json`
- Body:
  ```json
  {
    "model": "minimax-m3:cloud",
    "messages": [
      { "role": "system", "content": "..." },
      { "role": "user", "content": "..." }
    ],
    "stream": false,
    "options": { "temperature": 0.0, "num_predict": 4096, "seed": 42 }
  }
  ```

**Error Handling:**
- `401` → Invalid API key
- `429` → Rate limited
- `5xx` → Server error
- `ConnectionException` → Network/timeout

### 5. RagService
**File:** `app/Services/RagService.php`

Retrieval-Augmented Generation service that selects the most relevant Excel chunks.

**Key Methods:**
- `getRelevantChunks($question, $files, $selectedFile)` — Get top-N relevant chunks.
- `getDirectExcelAnswer($question, $files, $selectedFile)` — Try to answer directly from Excel without calling AI.

**Scoring:**
- Rows are chunked into configurable sizes (default: 15 rows, 2000 chars)
- Query terms extracted from question (filters common stop words)
- Scoring: exact term match = 2 points, substring match = 1 point
- Top 5 chunks returned max; if best score is 0, returns empty (LLM will say "data not found")

## Configuration (.env)

Required environment variables:

```env
# n8n Webhook
N8N_WEBHOOK_URL=http://localhost:5678/webhook/excel-chat
UPLOAD_EXCEL_PATH=uploads

# Ollama Local
USE_LOCAL_OLLAMA=false
OLLAMA_API_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama2
OLLAMA_TEMPERATURE=0.0
OLLAMA_MAX_TOKENS=2048
OLLAMA_USE_HTTP=true

# Ollama Cloud
USE_OLLAMA_CLOUD=true
OLLAMA_CLOUD_API_URL=https://api.ollama.com
OLLAMA_CLOUD_API_KEY=your-api-key-here
OLLAMA_CLOUD_MODEL=minimax-m3:cloud
OLLAMA_CLOUD_TEMPERATURE=0.0
OLLAMA_CLOUD_MAX_TOKENS=4096
```

## RAG Strategy

The system uses a simple keyword-based retrieval approach:

1. **Chunking:** Excel rows are grouped into text chunks (default: 15 rows, 2000 chars max per chunk).
2. **Query Expansion:** User question is tokenized, cleaned, and stop words removed.
3. **Scoring:** Each chunk is scored based on term overlap (exact match = 2× weight, substring = 1× weight).
4. **Selection:** Top 5 best-scoring chunks are sent to the AI model.
5. **Fallback:** If no chunks score > 0, the full file content is sent as context.

Additionally, the system can answer certain list-type questions directly from Excel without calling the AI (via `getDirectExcelAnswer`), such as "Apa saja produk yang dijual?".

## Potential Improvements

- Upgrade RAG from keyword matching to embeddings-based vector search (e.g., using Ollama's embedding models or a vector database).
- Add multi-turn conversation memory.
- Implement streaming responses for real-time answer display.
- Add authentication middleware for admin routes.
- Support more file formats (PDF, DOCX, etc.).
- Add rate limiting for chat endpoints.

## Development Commands

```bash
# Start Laravel dev server
php artisan serve

# Install dependencies
composer install

# Clear cache
php artisan optimize:clear

# Show routes
php artisan route:list