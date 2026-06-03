<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Manajemen Excel</title>
    <style>
        body {
            font-family: Inter, system-ui, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .container {
            width: min(1100px, calc(100% - 32px));
            margin: 24px auto;
            padding: 24px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }
        h1 {
            margin-bottom: 8px;
            font-size: 28px;
        }
        p.lead {
            margin: 0 0 20px;
            color: #4b5563;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        .alert-success {
            background: #ecfdf5;
            color: #166534;
            border-color: #d1fae5;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        form {
            display: grid;
            gap: 14px;
        }
        .card {
            background: #f8fafc;
            border-radius: 18px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0.9rem 1.3rem;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .button-primary {
            background: #2563eb;
            color: #ffffff;
        }
        .button-danger {
            background: #ef4444;
            color: #ffffff;
        }
        .button-secondary {
            background: #e5e7eb;
            color: #111827;
        }
        input[type=file] {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        th, td {
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            color: #374151;
            font-weight: 700;
        }
        tbody tr:hover {
            background: #f8fafc;
        }
        .file-name {
            word-break: break-all;
        }
        .note {
            color: #6b7280;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Admin Excel uploads</h1>
        <p class="lead">Kelola file Excel di folder <code>/uploads</code>. Upload file baru atau hapus file yang sudah tidak diperlukan.</p>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('error') || isset($errorMessage))
            <div class="alert alert-error">{{ session('error') ?? $errorMessage }}</div>
        @endif

        <div class="card">
            <form action="{{ route('admin.upload') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <label for="excel_file">Upload file Excel (.xlsx, .xls, .csv)</label>
                <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls,.csv" required>
                @error('excel_file')
                    <div class="alert alert-error">{{ $message }}</div>
                @enderror
                <button type="submit" class="button button-primary">Unggah File</button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div>
                    <strong>Daftar file Excel</strong>
                    <p class="note">Folder upload: <code>/uploads</code></p>
                </div>
            </div>

            @if(count($files) === 0)
                <p class="note">Tidak ada file Excel.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Nama File</th>
                            <th>Jenis</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($files as $file)
                            <tr>
                                <td class="file-name">{{ $file }}</td>
                                <td>{{ strtoupper(pathinfo($file, PATHINFO_EXTENSION)) }}</td>
                                <td>
                                    <form action="{{ route('admin.delete') }}" method="POST" style="display:inline-block;">
                                        @csrf
                                        <input type="hidden" name="filename" value="{{ $file }}">
                                        <button type="submit" class="button button-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</body>
</html>
