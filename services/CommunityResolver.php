<?php

namespace humhub\modules\fediverse\services;

use RuntimeException;

/**
 * Resolves a Lemmy-family community handle (!name@host / name@host / actor URL)
 * to its verified Group actor document. Reuses RemoteActorResolver's
 * SSRF-guarded, signed WebFinger + actor fetch. Does NOT create any shadow
 * user — a community has no local user; its data is stored on SpaceCommunity.
 */
class CommunityResolver
{
    /** Split a community handle into [name, host]; null if malformed. Strips a leading '!' or '@'. */
    public static function parseHandle(string $handle): ?array
    {
        $handle = ltrim(trim($handle), '!@');
        if (!preg_match('/^([^@\s]+)@([^@\s]+\.[^@\s]+)$/', $handle, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /** WebFinger query URL for a community handle (acct: WITHOUT the '!'); '' if malformed. */
    public static function webfingerUrl(string $handle): string
    {
        $parts = self::parseHandle($handle);
        if ($parts === null) {
            return '';
        }
        [$name, $host] = $parts;
        return 'https://' . $host . '/.well-known/webfinger?resource=acct:' . $name . '@' . $host;
    }

    /** A usable community actor doc is a Group with an inbox + id. */
    public static function isCommunityDoc(array $doc): bool
    {
        $type = $doc['type'] ?? '';
        return $type === 'Group'
            && !empty($doc['id'])
            && !empty($doc['inbox']);
    }

    /**
     * Resolve a handle to its Group actor document (WebFinger → actor fetch).
     * Throws on unsafe/unreachable/non-Group input.
     */
    public function resolveByHandle(string $handle): array
    {
        $wf = self::webfingerUrl($handle);
        if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
            throw new RuntimeException('Invalid or unsafe community handle: ' . $handle);
        }
        $resolver = new RemoteActorResolver();
        $actorUrl = $resolver->webfingerActorUrl($wf);
        $doc = $resolver->fetchActorDocument($actorUrl); // SSRF-guarded, signed GET
        if (!self::isCommunityDoc($doc)) {
            throw new RuntimeException('Not a Lemmy-family community (Group) actor: ' . $handle);
        }
        return $doc;
    }
}
