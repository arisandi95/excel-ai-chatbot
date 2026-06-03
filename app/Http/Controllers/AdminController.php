<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Services\ExcelFileService;

/**
 * Controller untuk halaman admin yang mengelola file Excel di folder uploads.
 */
class AdminController extends Controller
{
    protected ExcelFileService $excelFileService;

    public function __construct(ExcelFileService $excelFileService)
    {
        $this->excelFileService = $excelFileService;
    }

    /**
     * Tampilkan halaman admin untuk manajemen file Excel.
     */
    public function index()
    {
        $excelData = $this->excelFileService->getExcelFiles();

        return view('admin', [
            'files' => $excelData['success'] ? $excelData['files'] : [],
            'errorMessage' => $excelData['success'] ? null : $excelData['error'],
        ]);
    }

    /**
     * Upload file Excel ke folder uploads.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:51200',
        ], [
            'excel_file.required' => 'Pilih file Excel terlebih dahulu.',
            'excel_file.mimes' => 'Hanya file Excel (.xlsx, .xls, .csv) yang diperbolehkan.',
            'excel_file.max' => 'Ukuran file maksimal 50MB.',
        ]);

        $file = $request->file('excel_file');
        $filename = $file->getClientOriginalName();
        $targetPath = $this->excelFileService->getUploadPath();

        if (!File::isDirectory($targetPath)) {
            File::makeDirectory($targetPath, 0755, true);
        }

        $file->move($targetPath, $filename);

        return redirect()->route('admin.index')->with('success', 'File Excel berhasil diunggah.');
    }

    /**
     * Hapus file Excel dari folder uploads.
     */
    public function delete(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
        ], [
            'filename.required' => 'File tidak dipilih.',
        ]);

        $filename = basename($request->input('filename'));
        $filePath = $this->excelFileService->getUploadPath() . DIRECTORY_SEPARATOR . $filename;

        if (!File::exists($filePath)) {
            return redirect()->route('admin.index')->with('error', 'File tidak ditemukan.');
        }

        File::delete($filePath);

        return redirect()->route('admin.index')->with('success', 'File Excel berhasil dihapus.');
    }
}
