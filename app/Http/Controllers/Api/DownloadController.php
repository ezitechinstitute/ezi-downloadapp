<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDownloadJob;
use App\Models\Download;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
    /**
     * The phone's account of what happened after it fetched the file.
     *
     * Without this, every failure on the phone looked like a success here.
     * Nothing about the person is sent: the device model and Android version
     * only, so a failure can be traced to the phones it happens on.
     */
    public function phoneReport(Request $request, Download $download)
    {
        $validated = $request->validate([
            'saved' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:500'],
            'device' => ['nullable', 'string', 'max:200'],
        ]);

        $download->update([
            'phone_status' => $validated['saved'] ? 'saved' : 'failed',
            'phone_error' => $validated['error'] ?? null,
            'phone_device' => $validated['device'] ?? null,
            'phone_reported_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Create a new download request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
            'platform' => ['required', 'string', 'max:50'],
            'quality' => ['required', 'in:1080p,720p,480p'],
        ]);

        $download = Download::create([
            'url' => $validated['url'],
            'platform' => $validated['platform'],
            'quality' => $validated['quality'],
            'status' => 'pending',
            'progress' => 0,
        ]);

        // Dispatch the background job to process yt-dlp download
        ProcessDownloadJob::dispatch($download);

        return response()->json([
            'success' => true,
            'message' => 'Download request created successfully.',
            'data' => $download,
        ], 201);
    }

    /**
     * Get a download request.
     */
    public function show(Download $download)
    {
        return response()->json([
            'success' => true,
            'data' => $download,
        ]);
    }

    /**
     * Get all download requests.
     */
    public function index()
    {
        $downloads = Download::latest()->get();

        return response()->json([
            'success' => true,
            'data' => $downloads,
        ]);
    }
}