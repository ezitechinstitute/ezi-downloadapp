<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DownloadController;

Route::post('/downloads', [DownloadController::class, 'store']);

Route::get('/downloads', [DownloadController::class, 'index']);

Route::get('/downloads/{download}', [DownloadController::class, 'show']);