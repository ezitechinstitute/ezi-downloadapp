<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDownloadJob;
use App\Models\Download;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
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