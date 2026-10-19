<?php

namespace humhub\modules\activitypub\services;

use Yii;

/**
 * Downloads remote media (images) for ingest. SSRF-guarded, HTTPS-only (via
 * FederationHttpClient), image-only by Content-Type, and size-capped. Returns
 * raw bytes + mime, or null on any rejection/failure (never throws).
 */
class RemoteMediaFetcher
{
    public const MAX_BYTES = 10485760; // 10 MB

    /** @return array{content:string,mime:string}|null */
    public function fetchImage(?string $url): ?array
    {
        if (!is_string($url) || $url === '' || !UrlSafety::isSafePublicUrl($url)) {
            return null;
        }
        try {
            $response = (new FederationHttpClient())->createRequest()
                ->setMethod('GET')
                ->setUrl($url)
                ->send();
            if (!$response->getIsOk()) {
                return null;
            }
            $declared = (int) $response->getHeaders()->get('content-length', 0);
            if ($declared > self::MAX_BYTES) {
                return null;
            }
            $mime = strtolower(trim(explode(';', (string) $response->getHeaders()->get('content-type', ''))[0]));
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return null; // safe raster image types only (excludes SVG / polyglots, and what AssetImage handles)
            }
            $content = (string) $response->getContent();
            if ($content === '' || strlen($content) > self::MAX_BYTES) {
                return null;
            }
            return ['content' => $content, 'mime' => $mime];
        } catch (\Throwable $e) {
            Yii::warning('ActivityPub media fetch failed for ' . $url . ': ' . $e->getMessage(), 'activitypub');
            return null;
        }
    }

    /** A safe filename (with a sensible extension) for a fetched image. */
    public function filenameFor(string $url, string $mime): string
    {
        $base = basename((string) parse_url($url, PHP_URL_PATH));
        if ($base === '' || $base === '/') {
            $base = 'image';
        }
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $base)) {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'img',
            };
            $base .= '.' . $ext;
        }
        return mb_substr(preg_replace('/[^\w.\-]/', '_', $base), 0, 200);
    }
}
