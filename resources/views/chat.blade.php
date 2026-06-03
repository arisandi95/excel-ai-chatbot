<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Excel AI Chatbot</title>

    {{-- Google Fonts & Font Awesome --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ===== Container Utama ===== */
        #app {
            width: 100%;
            max-width: 800px;
            height: 100vh;
            max-height: 700px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin: 20px;
        }

        /* ===== Header ===== */
        .chat-header {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            padding: 20px 28px;
            flex-shrink: 0;
        }

        .chat-header h1 {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-header h1 i {
            font-size: 24px;
        }

        .chat-header p {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 4px;
            font-weight: 400;
        }

        /* ===== Area Chat Messages ===== */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: #fafbfc;
        }

        /* Styling scrollbar */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        .chat-messages::-webkit-scrollbar-track {
            background: transparent;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 3px;
        }

        /* ===== Bubble Chat ===== */
        .message {
            max-width: 80%;
            padding: 14px 18px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.6;
            animation: fadeIn 0.3s ease;
            word-wrap: break-word;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Bubble kiri (bot) */
        .message-bot {
            align-self: flex-start;
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }

        .message-bot .sender {
            font-size: 11px;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Bubble kanan (user) */
        .message-user {
            align-self: flex-end;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            border-bottom-right-radius: 4px;
        }

        /* ===== Error message (bot) ===== */
        .message-error {
            align-self: flex-start;
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-bottom-left-radius: 4px;
        }

        .message-error .sender {
            color: #dc2626;
        }

        /* ===== Typing indicator ===== */
        .typing-indicator {
            align-self: flex-start;
            display: none;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 14px 22px;
            border-radius: 18px;
            border-bottom-left-radius: 4px;
        }

        .typing-indicator.active {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .typing-indicator span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #94a3b8;
            display: inline-block;
            animation: typing 1.4s infinite ease-in-out both;
        }

        .typing-indicator span:nth-child(1) { animation-delay: 0s; }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* ===== Input Area ===== */
        .chat-input {
            padding: 16px 28px 20px;
            background: white;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .chat-input form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .chat-input .input-wrapper {
            flex: 1;
            position: relative;
        }

        .chat-input textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            resize: none;
            outline: none;
            transition: border-color 0.2s;
            min-height: 48px;
            max-height: 120px;
            line-height: 1.5;
        }

        .chat-input textarea:focus {
            border-color: #2563eb;
        }

        .chat-input textarea::placeholder {
            color: #94a3b8;
        }

        .chat-input button {
            width: 48px;
            height: 48px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .chat-input button:hover {
            opacity: 0.9;
        }

        .chat-input button:active {
            transform: scale(0.95);
        }

        .chat-input button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== Available Files Info ===== */
        .file-info {
            font-size: 12px;
            color: #64748b;
            padding: 6px 0 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .file-info i {
            font-size: 13px;
        }

        /* ===== Responsive ===== */
        @media (max-width: 640px) {
            #app {
                max-height: 100vh;
                margin: 0;
                border-radius: 0;
            }

            .chat-messages {
                padding: 16px;
            }

            .chat-input {
                padding: 12px 16px 16px;
            }

            .message {
                max-width: 90%;
            }
        }
    </style>
</head>
<body>

    <div id="app">

        {{-- Header --}}
        <div class="chat-header">
            <h1>
                <i class="fas fa-file-excel"></i>
                Excel AI Chatbot
            </h1>
            <p>Tanyakan apapun tentang data file Excel Anda</p>
        </div>

        {{-- Messages Area --}}
        <div class="chat-messages" id="chatMessages">

            {{-- Welcome message --}}
            <div class="message message-bot">
                <div class="sender">🤖 AI Assistant</div>
                Halo! Saya adalah asisten AI untuk file Excel Anda.<br>
                Silakan upload file <strong>.xlsx</strong>, <strong>.xls</strong>, atau <strong>.csv</strong> ke folder <code>uploads</code>, lalu tanyakan data apa pun yang Anda perlukan.
            </div>

            {{-- Typing indicator --}}
            <div class="typing-indicator" id="typingIndicator">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>

        {{-- Input Area --}}
        <div class="chat-input">
            <form id="chatForm" autocomplete="off">
                @csrf
                <div class="input-wrapper">
                    <textarea
                        id="questionInput"
                        rows="1"
                        placeholder="Ketik pertanyaan Anda..."
                        required
                        minlength="2"
                        maxlength="1000"
                    ></textarea>
                    <div class="file-info" id="fileInfo">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Memeriksa file Excel...</span>
                    </div>
                    <div class="file-select" id="fileSelectWrapper" style="display:none; margin-top: 10px;">
                        <label for="fileSelect" style="font-size: 12px; color: #64748b; display: block; margin-bottom: 6px;">Pilih file Excel:</label>
                        <select id="fileSelect" name="selected_file" style="width: 100%; padding: 10px 14px; border-radius: 14px; border: 1px solid #e2e8f0; background: #ffffff; font-family: 'Inter', sans-serif; font-size: 14px; color: #0f172a;"></select>
                    </div>
                </div>
                <button type="submit" id="sendButton" disabled>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // =====================
            // DOM Elements
            // =====================
            const chatMessages = document.getElementById('chatMessages');
            const chatForm     = document.getElementById('chatForm');
            const questionInput = document.getElementById('questionInput');
            const sendButton   = document.getElementById('sendButton');
            const typingIndicator = document.getElementById('typingIndicator');
            const fileInfo     = document.getElementById('fileInfo');
            const fileSelect   = document.getElementById('fileSelect');
            const fileSelectWrapper = document.getElementById('fileSelectWrapper');

            // =====================
            // Auto-resize textarea
            // =====================
            questionInput.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                updateSendState();
            });

            fileSelect.addEventListener('change', updateSendState);

            // =====================
            // Cek file Excel di awal
            // =====================
            loadExcelFiles();

            async function loadExcelFiles() {
                try {
                    const response = await fetch('{{ route("chat.files") }}', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                        },
                    });

                    const data = await response.json();

                    if (!data.success || !Array.isArray(data.files) || data.files.length === 0) {
                        fileInfo.innerHTML = `
                            <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i>
                            <span style="color: #d97706;">${data.error || 'Tidak ada file Excel tersedia di folder uploads.'}</span>
                        `;
                        sendButton.disabled = true;
                        return;
                    }

                    fileInfo.innerHTML = `
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i>
                        <span style="color: #16a34a;">File Excel tersedia: ${data.files.length} file</span>
                    `;

                    fileSelectWrapper.style.display = 'block';
                    fileSelect.innerHTML = '';

                    data.files.forEach(function (filename, index) {
                        const option = document.createElement('option');
                        option.value = filename;
                        option.textContent = filename;
                        fileSelect.appendChild(option);
                    });

                    if (data.selected_file) {
                        fileSelect.value = data.selected_file;
                    }

                    updateSendState();
                } catch (err) {
                    fileInfo.innerHTML = `
                        <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                        <span style="color: #dc2626;">Gagal memeriksa file Excel</span>
                    `;
                    sendButton.disabled = true;
                }
            }

            function updateSendState() {
                const trimmed = questionInput.value.trim();
                sendButton.disabled = trimmed.length < 2 || !fileSelect.value;
            }

            // =====================
            // Kirim pertanyaan
            // =====================
            chatForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const question = questionInput.value.trim();
                if (question.length < 2) return;

                // Disable form
                sendButton.disabled = true;
                questionInput.disabled = true;

                // Tambahkan bubble user
                appendMessage('user', question);
                questionInput.value = '';
                questionInput.style.height = 'auto';

                // Tampilkan typing indicator
                typingIndicator.classList.add('active');
                scrollToBottom();

                try {
                    const response = await fetch('{{ route("chat.send") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
body: JSON.stringify({
                        question: question,
                        selected_file: fileSelect.value,
                    }),
                    });

                    const data = await response.json();

                    // Sembunyikan typing indicator
                    typingIndicator.classList.remove('active');

                    if (data.success) {
                        // Tampilkan jawaban dari n8n
                        appendMessage('bot', data.answer);
                    } else {
                        // Tampilkan pesan error
                        appendMessage('error', data.error || 'Terjadi kesalahan yang tidak diketahui.');
                    }

                } catch (err) {
                    typingIndicator.classList.remove('active');
                    appendMessage('error', 'Gagal terhubung ke server. Pastikan aplikasi berjalan dengan benar.');
                }

                // Enable form
                sendButton.disabled = false;
                questionInput.disabled = false;
                questionInput.focus();

                scrollToBottom();
            });

            /**
             * Tambahkan bubble chat ke area pesan.
             *
             * @param {'user'|'bot'|'error'} type
             * @param {string} text
             */
            function appendMessage(type, text) {
                const div = document.createElement('div');

                if (type === 'user') {
                    div.className = 'message message-user';
                    div.textContent = text;
                } else if (type === 'error') {
                    div.className = 'message message-error';
                    div.innerHTML = `<div class="sender">⚠️ Error</div>${escapeHtml(text)}`;
                } else {
                    div.className = 'message message-bot';
                    div.innerHTML = `<div class="sender">🤖 AI Assistant</div>${escapeHtml(text)}`;
                }

                // Sisipkan sebelum typing indicator
                chatMessages.insertBefore(div, typingIndicator);
                scrollToBottom();
            }

            /**
             * Escape HTML untuk keamanan XSS.
             */
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.replace(/\n/g, '<br>');
            }

            /**
             * Scroll ke bagian paling bawah chat.
             */
            function scrollToBottom() {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            // =====================
            // Kirim dengan Enter (tanpa Shift)
            // =====================
            questionInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatForm.dispatchEvent(new Event('submit'));
                }
            });

        });
    </script>

</body>
</html>