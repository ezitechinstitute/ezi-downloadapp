<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DownloadController;

Route::post('/downloads', [DownloadController::class, 'store']);

// No route lists all downloads: it handed every user's links to anyone.

Route::get('/downloads/{download}', [DownloadController::class, 'show']);

// The phone reports whether the video reached its gallery.
Route::post('/downloads/{download}/phone-report', [DownloadController::class, 'phoneReport']);

// What the app checks at launch: whether its version must update.
Route::get('/app-config', fn () => response()->json([
    'min_version_code' => config('app.min_version_code'),
]));
