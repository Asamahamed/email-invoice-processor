<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PnlController;

Route::prefix('pnl')->group(function () {
    Route::get('/headers', [PnlController::class, 'apiHeaders']);
    Route::get('/items/{id}', [PnlController::class, 'apiItems']);
});