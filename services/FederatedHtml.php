<?php

namespace humhub\modules\fediverse\services;

/**
 * Converts remote ActivityPub HTML content (Mastodon Note/summary markup) into
 * readable plain text for a HumHub message/bio: block boundaries become line
 * breaks and HTML entities are decoded, instead of naive strip_tags() that runs
 * paragraphs together and leaves entities like &amp; visible.
 */
class FederatedHtml
{
    public static function toText(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }
        // Preserve block structure as newlines before stripping tags.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Tidy whitespace: drop trailing spaces per line, collapse blank runs.
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Split a trailing block of hashtag-only lines off a post body (the common
     * Mastodon style where #tags are appended at the end). Returns
     * ['text' => bodyWithoutTrailingTags, 'tags' => [names...]]. Inline hashtags
     * (lines that also contain normal words, e.g. "I am #blue") are left untouched.
     * If the whole body is hashtags, nothing is extracted (body kept intact).
     *
     * @return array{text:string,tags:string[]}
     */
    public static function extractTrailingHashtags(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];
        $collected = [];
        while ($lines !== []) {
            $line = trim((string) end($lines));
            if ($line === '') {
                array_pop($lines); // skip trailing blank line
                continue;
            }
            $tokens = preg_split('/\s+/', $line) ?: [];
            $lineTags = [];
            $allTags = true;
            foreach ($tokens as $tok) {
                if (preg_match('/^#([\p{L}\p{N}_]+)$/u', $tok, $m)) {
                    $lineTags[] = $m[1];
                } else {
                    $allTags = false;
                    break;
                }
            }
            if (!$allTags || $lineTags === []) {
                break; // first non-hashtag line → stop
            }
            $collected = array_merge($lineTags, $collected); // prepend (scanning upward)
            array_pop($lines);
        }

        // De-duplicate case-insensitively, keep first spelling.
        $seen = [];
        $tags = [];
        foreach ($collected as $t) {
            $k = mb_strtolower($t);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $tags[] = $t;
            }
        }

        $cleaned = rtrim(implode("\n", $lines));
        if ($cleaned === '') {
            // Whole body was hashtags — keep it as-is rather than emptying the post.
            return ['text' => trim($text), 'tags' => []];
        }
        return ['text' => $cleaned, 'tags' => $tags];
    }
}
