<?php

namespace App\Jobs;

/**
 * Turns a TikTok photo post (a slideshow of pictures over a song) into an MP4.
 *
 * yt-dlp only knows TikTok videos: a /photo/ link fails as "Unsupported URL",
 * and asked for the same post as /video/ it finds nothing but the song. In
 * October 2026 these were the most common TikTok failure, and they reach the
 * app as short vt.tiktok.com links, so nobody could tell a photo post from a
 * broken video.
 *
 * The page yt-dlp fetches still describes the whole post, so this lets yt-dlp
 * fetch it (it gets past TikTok's bot checks, which plain requests do not),
 * reads the picture and song links out of it, and has ffmpeg put the pictures
 * one after another over the song in a 1080x1920 video.
 */
class TikTokSlideshow
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** How long each picture stays on screen, in seconds. */
    private const MIN_SECONDS = 2.5;
    private const MAX_SECONDS = 5.0;

    /** A post with one picture runs as long as its song, within these. */
    private const SINGLE_MIN = 5.0;
    private const SINGLE_MAX = 60.0;

    private const MAX_PICTURES = 35;

    /**
     * The /video/ form of a TikTok link when it is a photo post, else null.
     *
     * Short links (vm./vt.tiktok.com) are followed one hop to see where they
     * lead; that is the only way to know before asking yt-dlp.
     */
    public static function photoPostUrl(string $url): ?string
    {
        $target = $url;

        if (preg_match('#^https?://(vm|vt)\.tiktok\.com/#i', $url)) {
            $target = self::redirectTarget($url) ?? $url;
        }

        if (!preg_match('#^https?://(?:www\.)?tiktok\.com/(@[^/]+)/photo/(\d+)#i', $target, $m)) {
            return null;
        }

        return "https://www.tiktok.com/{$m[1]}/video/{$m[2]}";
    }

    /**
     * Builds the video at $outFile. Throws with a message fit for the app.
     */
    public static function make(string $videoUrl, string $ytDlpPath, string $outFile): void
    {
        $work = storage_path('app/tmp/slideshow-' . bin2hex(random_bytes(6)));
        mkdir($work, 0777, true);

        try {
            $post = self::readPost($videoUrl, $ytDlpPath, $work);

            $pictures = [];
            foreach (array_slice($post['pictures'], 0, self::MAX_PICTURES) as $i => $link) {
                $raw = "{$work}/raw{$i}";
                if (!self::fetch($link, $raw)) {
                    continue;
                }

                // Every picture to the same 1080x1920 frame first: a mix of
                // sizes inside one ffmpeg concat makes it stop or stretch.
                $fitted = sprintf('%s/p%03d.jpg', $work, $i);
                self::run(sprintf(
                    'ffmpeg -nostdin -loglevel error -y -i %s -vf %s -q:v 3 %s',
                    escapeshellarg($raw),
                    escapeshellarg('scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1'),
                    escapeshellarg($fitted)
                ));

                if (is_file($fitted)) {
                    $pictures[] = $fitted;
                }
            }

            if (empty($pictures)) {
                throw new \Exception('The pictures in this TikTok post could not be downloaded. Please try again.');
            }

            $song = null;
            if ($post['song'] && self::fetch($post['song'], "{$work}/song")) {
                $song = "{$work}/song";
            }

            $count = count($pictures);
            $songSeconds = $post['songSeconds'];

            if ($count === 1) {
                $each = min(self::SINGLE_MAX, max(self::SINGLE_MIN, $songSeconds ?: self::SINGLE_MIN));
            } else {
                $each = $songSeconds
                    ? min(self::MAX_SECONDS, max(self::MIN_SECONDS, $songSeconds / $count))
                    : 3.0;
            }

            $total = round($each * $count, 2);

            // The concat list repeats the last picture without a duration, or
            // ffmpeg drops its time.
            $list = '';
            foreach ($pictures as $p) {
                $list .= "file '" . basename($p) . "'\nduration {$each}\n";
            }
            $list .= "file '" . basename(end($pictures)) . "'\n";
            file_put_contents("{$work}/list.txt", $list);

            $audio = $song
                ? '-stream_loop -1 -i ' . escapeshellarg($song) . ' -map 0:v -map 1:a -c:a aac -b:a 128k'
                : '-map 0:v';

            // -threads 1 keeps memory down on this 512 MB server; still
            // pictures encode quickly regardless.
            self::run(sprintf(
                'cd %s && ffmpeg -nostdin -loglevel error -y -f concat -safe 0 -i list.txt %s ' .
                '-vf fps=10,format=yuv420p -c:v libx264 -preset veryfast -tune stillimage -crf 23 ' .
                '-threads 1 -t %s -movflags +faststart %s',
                escapeshellarg($work),
                $audio,
                $total,
                escapeshellarg($outFile)
            ));

            if (!is_file($outFile) || filesize($outFile) < 10000) {
                @unlink($outFile);
                throw new \Exception('This TikTok photo post could not be turned into a video. Please try again.');
            }
        } finally {
            self::removeDir($work);
        }
    }

    /**
     * Picture links, song link and song length from the post's page.
     *
     * @return array{pictures: string[], song: ?string, songSeconds: float}
     */
    private static function readPost(string $videoUrl, string $ytDlpPath, string $work): array
    {
        // --write-pages saves what yt-dlp downloaded into the working
        // directory; the download itself is skipped.
        $output = self::run(sprintf(
            'cd %s && %s --skip-download --write-pages --no-playlist -o page %s',
            escapeshellarg($work),
            escapeshellarg($ytDlpPath),
            escapeshellarg($videoUrl)
        ), false);

        $item = null;
        foreach (glob("{$work}/*.dump") as $dump) {
            $html = file_get_contents($dump);
            if (!preg_match('#<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)</script>#s', $html, $m)) {
                continue;
            }
            $data = json_decode($m[1], true);
            $item = $data['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;
            if ($item) {
                break;
            }
        }

        if (!$item) {
            throw new \Exception(DownloadFailure::explain($output ?: 'Unable to extract'));
        }

        $pictures = [];
        foreach ($item['imagePost']['images'] ?? [] as $image) {
            $link = $image['imageURL']['urlList'][0] ?? null;
            if ($link) {
                $pictures[] = $link;
            }
        }

        if (empty($pictures)) {
            throw new \Exception('This TikTok post has no video or pictures to save.');
        }

        return [
            'pictures' => $pictures,
            'song' => ($item['music']['playUrl'] ?? '') ?: null,
            'songSeconds' => (float) ($item['music']['duration'] ?? 0),
        ];
    }

    private static function redirectTarget(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => self::UA,
        ]);
        curl_exec($ch);
        $target = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        curl_close($ch);

        return $target;
    }

    private static function fetch(string $url, string $file): bool
    {
        $fh = fopen($file, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_REFERER => 'https://www.tiktok.com/',
        ]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        fclose($fh);

        if (!$ok || filesize($file) === 0) {
            @unlink($file);
            return false;
        }

        return true;
    }

    /** Runs a command; throws on failure unless told not to. Returns its output. */
    private static function run(string $command, bool $mustSucceed = true): string
    {
        exec($command . ' 2>&1', $output, $code);
        $text = implode("\n", $output);

        if ($mustSucceed && $code !== 0) {
            \Illuminate\Support\Facades\Log::warning("Slideshow step failed ({$code}): {$command}\n{$text}");
            throw new \Exception('This TikTok photo post could not be turned into a video. Please try again.');
        }

        return $text;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
