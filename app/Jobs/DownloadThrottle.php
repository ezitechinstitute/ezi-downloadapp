<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps this one server from talking to a site faster than the site will
 * tolerate, across every job rather than within one.
 *
 * Instagram answers a burst of requests from a single address with an empty
 * body, and goes on doing so until the address falls quiet for roughly a
 * minute. Retrying inside a job cannot fix that on its own: the worker runs
 * jobs back to back, so the next job's first attempt lands in the silence the
 * previous job was trying to create. Measured on 13 October 2026, four jobs
 * in a row failed every attempt for four straight minutes that way, while a
 * single quiet gap of 45 seconds was enough to restore service.
 *
 * So the wait has to be shared. One job meeting a busy site makes every job
 * after it hold off, which is the only thing the site is actually asking for.
 */
class DownloadThrottle
{
    /** Ordinary spacing between two requests to the same site, in seconds. */
    private const SPACING = 3;

    /** How long to leave a site alone after it says it is busy, in seconds. */
    private const COOLDOWN = 60;

    /**
     * Never hold a person's download longer than this in total, in seconds.
     * Past it, trying and failing is kinder than a progress bar that never
     * moves.
     */
    private const MAX_WAIT = 75;

    /** Waits until this server is allowed to talk to [$platform] again. */
    public static function before(string $platform): void
    {
        $readyAt = (int) Cache::get(self::key($platform), 0);
        $wait = min($readyAt - time(), self::MAX_WAIT);

        if ($wait <= 0) {
            return;
        }

        Log::info("Holding off {$platform} for {$wait}s — it asked to be left alone");

        sleep($wait);
    }

    /** Records that [$platform] answered, so the next request is spaced out. */
    public static function answered(string $platform): void
    {
        self::readyIn($platform, self::SPACING);
    }

    /** Records that [$platform] is busy, so every job leaves it alone. */
    public static function busy(string $platform): void
    {
        self::readyIn($platform, self::COOLDOWN);
    }

    private static function readyIn(string $platform, int $seconds): void
    {
        Cache::put(
            self::key($platform),
            time() + $seconds,
            // Outliving the wait itself would hold back a later request for
            // no reason, so the entry expires with it.
            $seconds + 5,
        );
    }

    private static function key(string $platform): string
    {
        return 'download-throttle:' . strtolower(trim($platform));
    }
}
