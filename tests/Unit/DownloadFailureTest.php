<?php

namespace Tests\Unit;

use App\Jobs\DownloadFailure;
use PHPUnit\Framework\TestCase;

/**
 * The strings below are real yt-dlp output, taken from the failures this
 * server recorded between 8 and 13 October 2026. They are the whole point of
 * the class: a sentence a person can act on, and a decision about whether
 * trying again would do any good.
 */
class DownloadFailureTest extends TestCase
{
    /** The single largest cause of failure, and the one retrying fixes. */
    public function test_instagram_rate_limit_is_worth_retrying(): void
    {
        $output = '[Instagram] DeaWouDoM8V: Downloading webpage' . "\n"
            . 'ERROR: [Instagram] DeaWouDoM8V: Instagram sent an empty media '
            . 'response. Check if this post is accessible in your browser '
            . 'without being logged-in.';

        $this->assertTrue(DownloadFailure::isTransient($output));
        $this->assertSame(
            'The site was busy and would not answer. Please try again in a minute.',
            DownloadFailure::explain($output),
        );
    }

    public function test_tiktok_read_timeout_is_worth_retrying(): void
    {
        $output = "ERROR: [vm.tiktok] ZSfXkQ: Unable to download webpage: "
            . "HTTPSConnectionPool(host='vt.tiktok.com', port=443): Read timed out. "
            . "(read timeout=20.0)";

        $this->assertTrue(DownloadFailure::isTransient($output));
    }

    /** Retrying a post that holds photos only wastes the person's time. */
    public function test_a_post_without_a_video_is_not_retried(): void
    {
        $output = 'ERROR: [Instagram] Dd9FKXuRqiO: There is no video in this post';

        $this->assertFalse(DownloadFailure::isTransient($output));
        $this->assertSame(
            'There is no video in this post — only photos or text.',
            DownloadFailure::explain($output),
        );
    }

    public function test_a_private_post_is_explained_not_retried(): void
    {
        $output = 'ERROR: [TikTok] 7695986134798634247: You do not have permission '
            . 'to view this post. Log into an account that has access. Use --cookies.';

        $this->assertFalse(DownloadFailure::isTransient($output));
        $this->assertSame(
            'This post can only be seen by someone logged in, so it cannot be saved.',
            DownloadFailure::explain($output),
        );
    }

    public function test_tiktok_status_10231_is_explained_not_retried(): void
    {
        $output = 'ERROR: [TikTok] 7662347062721137940: Video not available, '
            . 'status code 10231; please report this issue on https://github.com/';

        $this->assertFalse(DownloadFailure::isTransient($output));
        $this->assertStringContainsString(
            'not available',
            DownloadFailure::explain($output),
        );
    }

    /** An extractor that has fallen behind the site. Nothing to retry. */
    public function test_a_broken_extractor_is_not_retried(): void
    {
        $output = 'ERROR: [facebook] 1470602334953983: Cannot parse data; '
            . 'please report this issue on https://github.com/yt-dlp/yt-dlp/issues';

        $this->assertFalse(DownloadFailure::isTransient($output));
        $this->assertSame(
            'This video could not be saved. The site may have changed how it works.',
            DownloadFailure::explain($output),
        );
    }

    /**
     * "Downloading webpage" appears in almost every run and contains the
     * letters "age". Matching that loosely would call every failure
     * age-restricted.
     */
    public function test_downloading_webpage_is_not_mistaken_for_age_restriction(): void
    {
        $output = "[Instagram] Dd9FKXuRqiO: Downloading webpage\n"
            . 'ERROR: [Instagram] Dd9FKXuRqiO: Cannot parse data';

        $this->assertStringNotContainsString(
            'age-restricted',
            DownloadFailure::explain($output),
        );
    }

    /**
     * Instagram words its rate limit as a login redirect. Matching on "login"
     * told people a public post was private (21 Oct 2026).
     */
    public function test_a_rate_limit_login_redirect_is_busy_not_private(): void
    {
        $output = 'ERROR: [Instagram] Dc8IQPzAPl7: The webpage request was redirected '
            . 'to the login page. You have exceeded the rate-limit for accessing '
            . 'posts anonymously. Use --cookies for the authentication.';

        $this->assertTrue(DownloadFailure::isTransient($output));
        $this->assertSame(
            'The site was busy and would not answer. Please try again in a minute.',
            DownloadFailure::explain($output),
        );
    }

    /** A private post that also timed out is still private. */
    public function test_a_permanent_reason_wins_over_a_transient_one(): void
    {
        $output = "ERROR: Read timed out.\n"
            . 'ERROR: [Instagram] X: This post is private';

        $this->assertFalse(DownloadFailure::isTransient($output));
    }

    /** The app refuses to show anything longer, and falls back to its own text. */
    public function test_every_explanation_fits_in_the_app(): void
    {
        $samples = [
            'ERROR: [Instagram] X: There is no video in this post',
            'ERROR: [facebook] X: Cannot parse data',
            'ERROR: [Instagram] X: Instagram sent an empty media response.',
            'ERROR: [TikTok] X: Unsupported URL: https://example.com/x',
            'ERROR: [vimeo] X: The web client only works when logged in.',
            'ERROR: something nobody has seen before',
        ];

        foreach ($samples as $output) {
            $explanation = DownloadFailure::explain($output);

            $this->assertLessThan(
                160,
                strlen($explanation),
                "Too long to display: {$explanation}",
            );
            $this->assertStringNotContainsString('http', $explanation);
            $this->assertStringNotContainsString('yt-dlp', $explanation);
        }
    }
}
