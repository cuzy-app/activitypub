<?php

namespace humhub\modules\activitypub\services;

class NoteSerializer
{
    public const PUBLIC_URI = 'https://www.w3.org/ns/activitystreams#Public';

    /**
     * @param array $attachments each: ['type'=>'Image'|'Document','mediaType'=>..,'url'=>..]
     */
    public function note(string $actorUrl, string $noteId, string $contentHtml, string $publishedIso, string $url, array $attachments): array
    {
        $note = [
            'id' => $noteId,
            'type' => 'Note',
            'attributedTo' => $actorUrl,
            'content' => $contentHtml,
            'published' => $publishedIso,
            'url' => $url,
            'to' => [self::PUBLIC_URI],
            'cc' => [$actorUrl . '/followers'],
        ];
        if ($attachments !== []) {
            $note['attachment'] = array_values($attachments);
        }
        return $note;
    }

    /** Wrap an object in a Create/Update activity. */
    public function wrap(string $type, string $actorUrl, array $object, string $activityId): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => $type,
            'actor' => $actorUrl,
            'published' => $object['published'] ?? null,
            'to' => $object['to'] ?? [self::PUBLIC_URI],
            'cc' => $object['cc'] ?? [$actorUrl . '/followers'],
            'object' => $object,
        ];
    }

    /** Delete activity referencing a Tombstone of the note. */
    public function delete(string $actorUrl, string $noteId, string $activityId): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Delete',
            'actor' => $actorUrl,
            'to' => [self::PUBLIC_URI],
            'cc' => [$actorUrl . '/followers'],
            'object' => ['id' => $noteId, 'type' => 'Tombstone'],
        ];
    }

    /** Build an attachment array from a HumHub File. */
    public function attachment(string $mimeType, string $url): array
    {
        return [
            'type' => str_starts_with($mimeType, 'image/') ? 'Image' : 'Document',
            'mediaType' => $mimeType,
            'url' => $url,
        ];
    }
}
