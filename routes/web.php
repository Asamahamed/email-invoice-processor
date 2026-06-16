<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PnlController;
use App\Http\Controllers\CreditController;
use App\Services\ClientManager;
use App\Http\Controllers\ReportController;

// ========== INVOICE ROUTES ==========
Route::get('/', [InvoiceController::class, 'index'])->name('index');
Route::get('/non-credit', [InvoiceController::class, 'nonCredit'])->name('non-credit');
Route::get('/credit', [InvoiceController::class, 'credit'])->name('credit');
Route::post('/process', [InvoiceController::class, 'processNow'])->name('process');
Route::post('/generate-invoice', [InvoiceController::class, 'generateAndViewInvoice'])->name('generate.and.view.invoice');
Route::post('/regenerate-invoice', [InvoiceController::class, 'regenerateInvoice'])->name('regenerate.invoice');
Route::get('/invoice/view/{id}', [InvoiceController::class, 'viewInvoice'])->name('invoice.view');
Route::get('/invoice/download/{id}', [InvoiceController::class, 'downloadInvoice'])->name('invoice.download');
Route::get('/email/view', [InvoiceController::class, 'viewEmail'])->name('email.view');

Route::get('/check-invoice-exists', [InvoiceController::class, 'checkInvoiceExists'])->name('check.invoice.exists');
Route::get('/get-email-invoice-number', [InvoiceController::class, 'getEmailInvoiceNumber'])->name('get.email.invoice.number');
Route::get('/check-invoice-by-number', [InvoiceController::class, 'checkInvoiceByNumber'])->name('check.invoice.by.number');

// ========== PNL ROUTES ==========
Route::prefix('pnl')->name('pnl.')->group(function () {
    Route::get('/', [PnlController::class, 'index'])->name('index');
    Route::post('/fetch', [PnlController::class, 'fetchEmails'])->name('fetch');
    Route::post('/update-status/{id}', [PnlController::class, 'updateStatus'])->name('update-status');
    Route::post('/mark-read/{id}', [PnlController::class, 'markAsRead'])->name('mark-read');
    Route::get('/view-email/{id}', [PnlController::class, 'viewEmail'])->name('view-email');
    Route::get('/items/{id}', [PnlController::class, 'viewItems'])->name('items');
    Route::get('/export', [PnlController::class, 'exportToExcel'])->name('export');  // Overall Export
    Route::get('/export-country/{country}', [PnlController::class, 'exportByCountry'])->name('export-country');  // Country-based Export
    Route::post('/update-excel', [PnlController::class, 'updateExcel'])->name('update-excel');
    Route::get('/view-excel/{country}', [PnlController::class, 'viewExcel'])->name('view-excel');
    Route::get('/export-country-approved/{country}', [PnlController::class, 'exportByCountryApproved'])->name('export-country-approved');
    Route::post('/export-selected', [PnlController::class, 'exportSelected'])->name('export.selected');
});

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

Route::post('/generate-revised-invoice', [InvoiceController::class, 'generateRevisedInvoice'])->name('generate.revised.invoice');


// ========== REPORT ROUTES ==========
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/month-wise', [ReportController::class, 'monthWise'])->name('month-wise');
    Route::get('/date-wise', [ReportController::class, 'dateWise'])->name('date-wise');
    Route::get('/export/month-wise', [ReportController::class, 'exportMonthWise'])->name('export.month-wise');
    Route::get('/export/date-wise', [ReportController::class, 'exportDateWise'])->name('export.date-wise');
});