<?php

namespace humhub\modules\fediverse\services;

use Yii;
use yii\helpers\Url;

/**
 * Central builder for all federation-facing URLs and the WebFinger handle.
 */
class FederationUrl
{
    /**
     * Normalise an arbitrary HumHub username into a WebFinger/ActivityPub
     * preferredUsername: ASCII letters, digits and underscore only.
     */
    public static function normalizeHandle(string $username): string
    {
        // Cut at '@' so an email-like username keeps only the local part.
        $local = explode('@', $username)[0];
        // Replace any run of non [A-Za-z0-9_] with a single underscore, trim, lowercase.
        $handle = preg_replace('/[^A-Za-z0-9_]+/', '_', $local);
        $handle = trim((string) $handle, '_');
        return strtolower($handle);
    }

    /**
     * The bare host of this installation (no scheme, no trailing slash),
     * used as the right-hand side of a WebFinger handle.
     */
    public static function domain(): string
    {
        return (string) parse_url(self::base(), PHP_URL_HOST);
    }

    /** Absolute base URL of the site, no trailing slash. */
    public static function base(): string
    {
        $base = (string) Yii::$app->settings->get('baseUrl');
        if ($base === '') {
            $base = Url::base(true);
        }
        return rtrim($base, '/');
    }

    /** Absolute ActivityPub actor id/URL for a user GUID. */
    public static function actorUrl(string $guid): string
    {
        return self::base() . '/fediverse/actor/' . $guid;
    }

    /** Absolute ActivityPub actor id/URL for a Space's Group actor. */
    public static function spaceActorUrl(string $guid): string
    {
        return self::base() . '/fediverse/actor/space/' . $guid;
    }

    /** Absolute ActivityPub actor id/URL for the instance (Application) actor. */
    public static function instanceActorUrl(): string
    {
        return self::base() . '/fediverse/instance-actor';
    }

    /** The shared inbox URL advertised by every actor. */
    public static function sharedInboxUrl(): string
    {
        return self::base() . '/fediverse/inbox';
    }
}
