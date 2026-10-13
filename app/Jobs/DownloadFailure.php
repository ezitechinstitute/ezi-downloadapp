<?php

namespace App\Jobs;

/**
 * Reads yt-dlp's output and decides two things: whether trying again could
 * possibly help, and what to tell the person waiting on their phone.
 *
 * Both matter. A third of all downloads were being recorded as failures, and
 * most of those were either a site catching its breath — where a second
 * attempt a few seconds later succeeds — or a link that never held a video,
 * where no number of attempts will help and the honest answer is to say so.
 */
class DownloadFailure
{
    /**
     * Failures that a later attempt can still get past.
     *
     * Instagram answers a burst of requests from one server with an empty
     * body rather than an error, and TikTok's short-link host sometimes just
     * stops replying. Both clear on their own within seconds.
     */
    private const TRANSIENT = [
        'empty media response',
        'read timed out',
        'connection reset',
        'connection timed out',
        'temporary failure',
        'temporarily unavailable',
        'http error 429',
        'http error 500',
        'http error 502',
        'http error 503',
        'http error 504',
        'unable to download webpage',
        'giving up after',
    ];

    /**
     * What to say, in the order the checks should run — the first match wins,
     * so the more specific reasons come before the general ones.
     *
     * These are shown to the person as they are. They stay under 160
     * characters, which is the longest message the app will display, and they
     * say what the person can do rather than what the extractor did.
     */
    private const EXPLANATIONS = [
        ['there is no video in this post', 'There is no video in this post — only photos or text.'],
        ['no video could be found', 'There is no video in this post — only photos or text.'],
        ['unsupported url', 'This link is not from a site Video Saver supports.'],
        ['is not a valid url', 'That does not look like a complete link. Copy it again from the app.'],
        ['private', 'This post is private. Only its followers can open it.'],
        ['login required', 'This post can only be seen by someone logged in, so it cannot be saved.'],
        ['log in for access', 'This post can only be seen by someone logged in, so it cannot be saved.'],
        ['log into an account', 'This post can only be seen by someone logged in, so it cannot be saved.'],
        ['redirected to the login page', 'This post can only be seen by someone logged in, so it cannot be saved.'],
        ['only works when logged', 'This video can only be seen by someone logged in, so it cannot be saved.'],
        ['account cookies', 'This post can only be seen by someone logged in, so it cannot be saved.'],
        ['video unavailable', 'This video is no longer available.'],
        // TikTok's own code for a post it will not serve here.
        ['status code 10231', 'This video is not available — it may be private or blocked in some countries.'],
        ['video not available', 'This video is no longer available.'],
        ['has been removed', 'This video is no longer available.'],
        ['no longer available', 'This video is no longer available.'],
        ['not available in your country', 'This video is blocked in the country the server sits in.'],
        ["can't be seen by certain", 'The person who posted this limited who can see it.'],
        // Spelled out: a bare "age" also matches "Downloading webpage".
        ['age-restricted', 'This video is age-restricted, so it cannot be saved.'],
        ['confirm your age', 'This video is age-restricted, so it cannot be saved.'],
        ['copyright', 'This video was taken down over copyright.'],
    ];

    /** Whether another attempt at [$output] could still succeed. */
    public static function isTransient(string $output): bool
    {
        $haystack = strtolower($output);

        foreach (self::EXPLANATIONS as [$needle, $_]) {
            // A permanent reason wins even when a transient phrase also
            // appears: a private post can time out and still be private.
            if (str_contains($haystack, $needle)) {
                return false;
            }
        }

        foreach (self::TRANSIENT as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sentence for the person waiting, from yt-dlp's [$output].
     *
     * Falls back to a plain apology. The full output stays in the log, where
     * it belongs: nobody waiting on a video wants to read a stack of URLs
     * and issue-tracker links, and the app refuses to show them anyway.
     */
    public static function explain(string $output): string
    {
        $haystack = strtolower($output);

        foreach (self::EXPLANATIONS as [$needle, $explanation]) {
            if (str_contains($haystack, $needle)) {
                return $explanation;
            }
        }

        if (self::isTransient($output)) {
            return 'The site was busy and would not answer. Please try again in a minute.';
        }

        return 'This video could not be saved. The site may have changed how it works.';
    }
}
