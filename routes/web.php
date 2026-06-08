<?php
// routes/web.php

use App\Http\Controllers\InvoiceController;

// routes/web.php

use App\Http\Controllers\PnlController;



Route::get('/', [InvoiceController::class, 'index'])->name('index');
Route::get('/non-credit', [InvoiceController::class, 'nonCredit'])->name('non-credit');
Route::get('/credit', [InvoiceController::class, 'credit'])->name('credit');
Route::post('/process', [InvoiceController::class, 'processNow'])->name('process');
Route::post('/generate-invoice', [InvoiceController::class, 'generateInvoice'])->name('generate.invoice');
Route::post('/bulk-generate', [InvoiceController::class, 'bulkGenerate'])->name('bulk.generate');
Route::get('/download/{id}', [InvoiceController::class, 'downloadInvoice'])->name('download');
// Add this route
Route::get('/email/view', [InvoiceController::class, 'viewEmail'])->name('email.view');
Route::get('/test-mail', function () {
    $cm = new ClientManager();
    $client = $cm->make([
        'host'          => env('IMAP_HOST'),
        'port'          => env('IMAP_PORT'),
        'encryption'    => env('IMAP_ENCRYPTION'),
        'validate_cert' => false,
        'username'      => env('IMAP_USERNAME'),
        'password'      => env('IMAP_PASSWORD'),
        'protocol'      => 'imap'
    ]);
    $client->connect();
    return 'Connected Successfully';
});
// routes/web.php
Route::get('/pnl', [PnlController::class, 'index'])->name('pnl.index');
Route::post('/pnl/fetch', [PnlController::class, 'fetchEmails'])->name('pnl.fetch');
Route::post('/pnl/update-status/{id}', [PnlController::class, 'updateStatus'])->name('pnl.update-status');
Route::post('/pnl/mark-read/{id}', [PnlController::class, 'markAsRead'])->name('pnl.mark-read');
Route::get('/pnl/view-email/{id}', [PnlController::class, 'viewEmail'])->name('pnl.view-email');
Route::get('/pnl/items/{id}', [PnlController::class, 'viewItems'])->name('pnl.items');
Route::get('/pnl/export', [PnlController::class, 'exportToExcel'])->name('pnl.export');
Route::post('/pnl/update-excel', [PnlController::class, 'updateExcel'])->name('pnl.update-excel');