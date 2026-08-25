<?php

namespace App\Jobs;

use App\Models\Download;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessDownloadJob implements ShouldQueue
{
    use Queueable;

    protected $download;

    public function __construct(Download $download)
    {
        $this->download = $download;
    }

    public function handle(): void
    {
        try {
            $this->download->update([
                'status' => 'processing',
                'progress' => 10,
            ]);

            $url = trim($this->download->url);

            $outputPath = storage_path('app/public/downloads');

            if (!file_exists($outputPath)) {
                mkdir($outputPath, 0777, true);
            }

            $ytDlpPath = storage_path('app/bin/yt-dlp.exe');

            $outputTemplate = $outputPath . '/' . $this->download->id . '.%(ext)s';

            /*
            |--------------------------------------------------------------------------
            | TikTok
            |--------------------------------------------------------------------------
            */

            if (strtolower($this->download->platform) === 'tiktok') {

                Log::info(
                    "TikTok download requested. ID: {$this->download->id}"
                );

                /*
                 * TikTok short URLs such as vm.tiktok.com/... redirect
                 * to the actual TikTok video URL.
                 *
                 * We let yt-dlp handle the URL directly first.
                 */

                $command =
                    "\"{$ytDlpPath}\" " .
                    "--no-playlist " .
                    "--user-agent \"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36\" " .
                    "-f \"best[ext=mp4]/best\" " .
                    "-o \"{$outputTemplate}\" " .
                    escapeshellarg($url);

            } else {

                /*
                |--------------------------------------------------------------------------
                | EXISTING FLOW
                |--------------------------------------------------------------------------
                | YouTube / Google and other currently working platforms
                | continue using the existing command.
                */

                $command =
                    "\"{$ytDlpPath}\" " .
                    "--extractor-args \"youtube:player-client=android\" " .
                    "--user-agent \"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 " .
                    "(KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36\" " .
                    "-f \"best[ext=mp4]/best\" " .
                    "--no-playlist " .
                    "-o \"{$outputTemplate}\" " .
                    escapeshellarg($url);
            }

            Log::info(
                "Executing yt-dlp for ID {$this->download->id}: {$command}"
            );

            $output = [];
            $resultCode = 0;

            exec($command . " 2>&1", $output, $resultCode);

            Log::info(
                "yt-dlp output for ID {$this->download->id}: " .
                implode("\n", $output)
            );

            Log::info(
                "yt-dlp exit code: {$resultCode}"
            );

            /*
            |--------------------------------------------------------------------------
            | Download successful
            |--------------------------------------------------------------------------
            */

            if ($resultCode === 0) {

                $files = glob(
                    $outputPath . '/' . $this->download->id . '.*'
                );

                if (!empty($files)) {

                    $fileName = basename($files[0]);

                    $videoUrl = asset(
                        'storage/downloads/' . $fileName
                    );

                    $this->download->update([
                        'status' => 'completed',
                        'progress' => 100,
                        'video_url' => $videoUrl,
                    ]);

                    Log::info(
                        "Download completed successfully. ID: {$this->download->id}"
                    );

                } else {

                    throw new \Exception(
                        "Downloaded file not found in directory."
                    );
                }

            } else {

                throw new \Exception(
                    "yt-dlp failed with code {$resultCode}: " .
                    implode("\n", $output)
                );
            }

        } catch (\Exception $e) {

            Log::error(
                "Download failed for ID {$this->download->id}: " .
                $e->getMessage()
            );

            $this->download->update([
                'status' => 'failed',
                'progress' => 0,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}