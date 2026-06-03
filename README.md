# Excel AI Chatbot

Aplikasi Laravel ini menyediakan antarmuka chat untuk menanyakan data dari file Excel yang diunggah ke folder `uploads`.

Aplikasi ini mendukung:
- Upload file Excel di halaman admin (`.xlsx`, `.xls`, `.csv`)
- Daftar file Excel tersedia otomatis di halaman chat
- Pemrosesan file Excel menjadi konteks teks untuk AI
- Pengiriman pertanyaan ke webhook `n8n` atau ke Ollama local jika diaktifkan
- Retrieval-Augmented Generation (RAG) untuk memilih chunk Excel yang paling relevan

## Fitur Utama

- Halaman `GET /chat` untuk bertanya pada AI menggunakan data Excel
- Endpoint `GET /chat/files` untuk mengambil daftar file Excel yang tersedia
- Endpoint `POST /chat/send` untuk mengirim pertanyaan dan konteks file ke n8n/Ollama
- Halaman `GET /admin` untuk upload dan hapus file Excel
- Menggunakan `app/Services/ExcelFileService.php` untuk parsing dan pembagian data Excel
- Menggunakan `app/Services/RagService.php` untuk memilih konten Excel paling relevan
- Menggunakan `app/Services/N8nService.php` dan `app/Services/OllamaService.php` untuk integrasi AI

## Struktur Halaman

- `/chat` -> halaman chat utama
- `/admin` -> halaman manajemen upload Excel

## Instalasi

1. Clone repository:
   ```bash
   git clone <repo-url>
   cd n8n_chatbot
   ```
2. Install dependensi PHP:
   ```bash
   composer install
   ```
3. Salin file lingkungan dan buat key aplikasi:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. Buat folder `uploads` jika belum ada:
   ```bash
   mkdir uploads
   ```
5. Konfigurasikan environment di `.env`:
   ```env
   N8N_WEBHOOK_URL=http://localhost:5678/webhook/excel-chat
   UPLOAD_EXCEL_PATH=uploads
   USE_LOCAL_OLLAMA=false
   OLLAMA_API_URL=http://127.0.0.1:11434
   OLLAMA_MODEL=llama2
   OLLAMA_TEMPERATURE=0.0
   OLLAMA_MAX_TOKENS=2048
   OLLAMA_USE_HTTP=true
   ```
6. Jalankan aplikasi lokal:
   ```bash
   php artisan serve
   ```

## Konfigurasi

Aplikasi ini membaca pengaturan dari `config/services.php`:

- `n8n.webhook_url` -> URL webhook n8n
- `n8n.upload_path` -> lokasi folder uploads
- `ollama.enabled` -> aktifkan Ollama local
- `ollama.api_url` -> alamat API Ollama
- `ollama.model` -> nama model Ollama
- `ollama.temperature` -> nilai temperatur AI
- `ollama.max_tokens` -> jumlah token maksimum
- `ollama.use_http` -> gunakan HTTP API Ollama atau CLI

Jika `USE_LOCAL_OLLAMA=true`, aplikasi akan mencoba menggunakan Ollama local.
Jika tidak, aplikasi akan mengirim pertanyaan ke webhook n8n.

## Cara Pakai

1. Buka `http://127.0.0.1:8000/admin`
2. Unggah file Excel (`.xlsx`, `.xls`, `.csv`) maksimal 50MB
3. Buka `http://127.0.0.1:8000/chat`
4. Pilih file Excel yang tersedia, ketik pertanyaan, dan kirim

> Chat bot membaca data dari file Excel yang tersedia dan mengirim konteks ke n8n/Ollama.

## Pengelolaan File Excel

Halaman admin mendukung:
- upload file Excel
- melihat daftar file yang ada di folder `uploads`
- menghapus file Excel

File yang valid hanya:
- `.xlsx`
- `.xls`
- `.csv`

## Perilaku AI

- `ChatController` memuat daftar file Excel melalui `ExcelFileService`
- `RagService` memilih potongan data Excel yang paling relevan berdasarkan pertanyaan
- `N8nService` mengirim pertanyaan bersama konteks ke webhook n8n
- `OllamaService` dapat dijalankan sebagai alternatif lokal jika diaktifkan

## Catatan

- Pastikan folder `uploads` dapat ditulisi oleh server web
- Pastikan n8n berjalan pada `N8N_WEBHOOK_URL` jika tidak menggunakan Ollama
- Jika menggunakan Ollama, pastikan service berjalan dan model sudah dimuat

## Lisensi

Proyek ini mengikuti lisensi MIT.
