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

    /**
     * Seconds to wait before each attempt. The first is immediate, and the
     * number of entries is the number of attempts.
     *
     * Two attempts, not three. The real waiting is done by DownloadThrottle,
     * which holds every job back for a minute once a site says it is busy, so
     * the second attempt already lands about a minute after the first — past
     * the roughly 49 seconds Instagram's block was measured to last. A third
     * would put someone past two minutes of staring at a progress bar for a
     * video that is most likely not coming.
     */
    private const RETRY_DELAYS = [0, 2];

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

            $photoPostUrl = null;

            /*
            |--------------------------------------------------------------------------
            | TikTok
            |--------------------------------------------------------------------------
            */

            if (strtolower($this->download->platform) === 'tiktok') {

                Log::info(
                    "TikTok download requested. ID: {$this->download->id}"
                );

                // Photo posts come as the same short links as videos, and
                // yt-dlp cannot download them; they are built here instead.
                $photoPostUrl = TikTokSlideshow::photoPostUrl($url);

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

                /*
                 * No user-agent of our own. An Android one used to be forced
                 * here, left over from when this also served YouTube, and it
                 * sent Facebook to m.facebook.com, whose pages yt-dlp cannot
                 * read — "Cannot parse data" was the single largest cause of
                 * failure on this server until it was removed. yt-dlp picks a
                 * header set that matches whichever browser it is pretending
                 * to be, and getting in the way of that only breaks things.
                 */
                $command =
                    "\"{$ytDlpPath}\" " .
                    "-f \"best[ext=mp4]/best\" " .
                    "--no-playlist " .
                    "-o \"{$outputTemplate}\" " .
                    escapeshellarg($url);
            }

            Log::info(
                "Executing yt-dlp for ID {$this->download->id}: {$command}"
            );

            /*
            |--------------------------------------------------------------------------
            | Run it, and try again when the site was merely busy
            |--------------------------------------------------------------------------
            | A third of all downloads were being recorded as failures, and the
            | largest single cause was Instagram answering a burst of requests
            | from this one server with an empty body. The same link works
            | seconds later. Giving up on the first attempt turned a pause the
            | person would never have noticed into a failure they did.
            |
            | A link that never held a video is not retried: three attempts at
            | a photo post is only three times the wait before the same answer.
            */

            $output = [];
            $resultCode = 0;

            $platform = $this->download->platform ?? 'unknown';

            if ($photoPostUrl) {
                Log::info("ID {$this->download->id} is a TikTok photo post; building a slideshow from {$photoPostUrl}");

                DownloadThrottle::before($platform);
                TikTokSlideshow::make($photoPostUrl, $ytDlpPath, "{$outputPath}/{$this->download->id}.mp4");
                DownloadThrottle::answered($platform);
            } else {
                foreach (self::RETRY_DELAYS as $attempt => $delay) {
                    $output = [];
                    $resultCode = 0;

                    // Shared across every job, so one busy answer quiets them all.
                    DownloadThrottle::before($platform);

                    exec($command . " 2>&1", $output, $resultCode);

                    $text = implode("\n", $output);

                    Log::info(
                        "yt-dlp attempt " . ($attempt + 1) . " for ID {$this->download->id} " .
                        "exited {$resultCode}: {$text}"
                    );

                    if ($resultCode === 0) {
                        DownloadThrottle::answered($platform);
                        break;
                    }

                    if (!DownloadFailure::isTransient($text)) {
                        DownloadThrottle::answered($platform);
                        break;
                    }

                    // The site is turning requests away: every job now waits,
                    // not just this one.
                    DownloadThrottle::busy($platform);

                    if ($attempt === count(self::RETRY_DELAYS) - 1) {
                        break;
                    }

                    $wait = self::RETRY_DELAYS[$attempt + 1];

                    Log::info(
                        "ID {$this->download->id}: the site was busy, waiting {$wait}s " .
                        "before attempt " . ($attempt + 2)
                    );

                    // A leftover part-file would otherwise have yt-dlp resume a
                    // download that never properly started.
                    foreach (glob($outputPath . '/' . $this->download->id . '.*') as $leftover) {
                        @unlink($leftover);
                    }

                    sleep($wait);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | A post with pictures and no video
            |--------------------------------------------------------------------------
            | Only for app versions that can put pictures in the gallery; older
            | ones would save a JPEG as a video, so they keep the old message.
            */

            if (
                $resultCode !== 0
                && $this->download->wants_photos
                && PhotoPost::supports($platform)
                && PhotoPost::isPhotoOnly(implode("
", $output))
            ) {
                Log::info("ID {$this->download->id} is a photo post; saving its pictures");

                DownloadThrottle::before($platform);
                $names = PhotoPost::save($url, $ytDlpPath, $outputPath, $this->download->id);
                DownloadThrottle::answered($platform);

                $photoUrls = array_map(fn ($n) => asset('storage/downloads/' . $n), $names);

                $this->download->update([
                    'status' => 'completed',
                    'progress' => 100,
                    'media_type' => 'photos',
                    'photo_urls' => $photoUrls,
                    'video_url' => $photoUrls[0],
                ]);

                Log::info("Saved " . count($names) . " pictures for ID {$this->download->id}");

                return;
            }

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
                        "This video could not be saved. Please try again."
                    );
                }

            } else {

                throw new \Exception(
                    DownloadFailure::explain(implode("\n", $output))
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