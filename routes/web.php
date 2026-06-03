<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Route untuk aplikasi Chat Excel dengan n8n.
|
| GET  /chat       -> Menampilkan halaman chat
| POST /chat/send  -> Mengirim pertanyaan user ke n8n
| GET  /admin      -> Halaman admin untuk mengelola file Excel
|
*/

Route::get('/', function () {
    return redirect('/chat');
});

Route::prefix('chat')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/files', [ChatController::class, 'files'])->name('chat.files');
    Route::post('/send', [ChatController::class, 'send'])->name('chat.send');
});

Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    Route::post('/upload', [AdminController::class, 'upload'])->name('admin.upload');
    Route::post('/delete', [AdminController::class, 'delete'])->name('admin.delete');
});