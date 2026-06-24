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
}
