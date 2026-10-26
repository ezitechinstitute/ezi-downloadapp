<?php

namespace App\Jobs;

/**
 * Saves the pictures of an Instagram or X post that has no video.
 *
 * yt-dlp downloads videos only and stops with "There is no video in this
 * post". On 26 Oct 2026 one person tried the same Instagram photo post ten
 * times in ninety seconds, so people clearly expect the pictures.
 *
 * yt-dlp still fetches the post's data before giving up, and --write-pages
 * keeps what it fetched; the picture links are read from that. Going through
 * yt-dlp rather than asking the sites directly keeps the guest tokens and
 * headers it already gets right.
 */
class PhotoPost
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const MAX_PICTURES = 20;

    /** Whether yt-dlp's answer means "this post only has pictures". */
    public static function isPhotoOnly(string $ytDlpOutput): bool
    {
        $text = strtolower($ytDlpOutput);

        return str_contains($text, 'there is no video in this post')
            || str_contains($text, 'no video could be found in this tweet');
    }

    public static function supports(string $platform): bool
    {
        $p = strtolower($platform);

        return str_contains($p, 'instagram') || str_contains($p, 'twitter') || $p === 'x';
    }

    /**
     * Downloads the post's pictures into $dir as "{id}_{n}.jpg".
     *
     * @return string[] the saved file names, in the post's order
     */
    public static function save(string $url, string $ytDlpPath, string $dir, int $id): array
    {
        $work = storage_path('app/tmp/photos-' . bin2hex(random_bytes(6)));
        mkdir($work, 0777, true);

        try {
            exec(sprintf(
                'cd %s && %s --skip-download --write-pages --no-playlist -o page %s 2>&1',
                escapeshellarg($work),
                escapeshellarg($ytDlpPath),
                escapeshellarg($url)
            ));

            $links = [];
            foreach (glob("{$work}/*.dump") as $dump) {
                $json = self::jsonIn(file_get_contents($dump));
                if ($json === null) {
                    continue;
                }
                $links = array_merge($links, self::instagramPictures($json, $url), self::xPictures($json, $url));
            }

            $links = array_slice(array_values(array_unique($links)), 0, self::MAX_PICTURES);

            if (empty($links)) {
                throw new \Exception('There is no video or picture in this post that can be saved.');
            }

            $saved = [];
            foreach ($links as $n => $link) {
                $name = "{$id}_" . ($n + 1) . '.jpg';
                if (self::fetch($link, "{$dir}/{$name}")) {
                    $saved[] = $name;
                }
            }

            if (empty($saved)) {
                throw new \Exception('The pictures in this post could not be downloaded. Please try again.');
            }

            return $saved;
        } finally {
            foreach (glob("{$work}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }

    /** The JSON in a dump file, which may have HTTP text before it. */
    private static function jsonIn(string $text): ?array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $data = json_decode(substr($text, $start), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Pictures of the Instagram post whose shortcode is in $url.
     *
     * The response also lists the author's other posts, so only the node
     * carrying this post's code is read.
     */
    private static function instagramPictures(array $json, string $url): array
    {
        if (!preg_match('#instagram\.com/(?:[^/]+/)?(?:p|reel|tv)/([\w-]+)#i', $url, $m)) {
            return [];
        }
        $code = $m[1];

        $post = self::find($json, fn ($n) => ($n['code'] ?? $n['shortcode'] ?? null) === $code
            && (isset($n['image_versions2']) || isset($n['carousel_media']) || isset($n['display_url'])));

        if ($post === null) {
            return [];
        }

        $items = $post['carousel_media']
            ?? array_column($post['edge_sidecar_to_children']['edges'] ?? [], 'node')
            ?: [$post];

        $links = [];
        foreach ($items as $item) {
            if (!empty($item['video_versions']) || ($item['is_video'] ?? false)) {
                continue;
            }
            // Candidates are largest first.
            $link = $item['image_versions2']['candidates'][0]['url'] ?? $item['display_url'] ?? null;
            if ($link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /** Pictures of the tweet whose id is in $url, at full size. */
    private static function xPictures(array $json, string $url): array
    {
        if (!preg_match('#(?:x|twitter)\.com/[^/]+/status/(\d+)#i', $url, $m)) {
            return [];
        }
        $id = $m[1];

        $tweet = self::find($json, fn ($n) => ($n['rest_id'] ?? null) === $id && isset($n['legacy']));
        $media = $tweet['legacy']['extended_entities']['media'] ?? $tweet['legacy']['entities']['media'] ?? [];

        $links = [];
        foreach ($media as $item) {
            if (($item['type'] ?? '') === 'photo' && !empty($item['media_url_https'])) {
                // format=jpg: the gallery is told these are JPEGs.
                $links[] = $item['media_url_https'] . '?format=jpg&name=orig';
            }
        }

        return $links;
    }

    /** The first array anywhere inside $data that $match accepts. */
    private static function find(array $data, callable $match): ?array
    {
        if ($match($data)) {
            return $data;
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = self::find($value, $match)) !== null) {
                return $found;
            }
        }

        return null;
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
        ]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        fclose($fh);

        if (!$ok || filesize($file) < 1000) {
            @unlink($file);
            return false;
        }

        return true;
    }
}
