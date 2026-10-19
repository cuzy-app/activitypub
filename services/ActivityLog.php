<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\activitypub\models\ActivityLogEntry;
use Yii;

/** Opt-in, capped logging of federation activities for debugging. Never throws. */
class ActivityLog
{
    public const MAX_ENTRIES = 1000;

    public static function isEnabled(): bool
    {
        return (bool) Yii::$app->getModule('activitypub')->settings->get('activityLogEnabled', false);
    }

    public static function record(string $direction, ?string $type, ?string $actor, ?string $body, string $status, ?string $note = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $e = new ActivityLogEntry();
            $e->direction = $direction;
            $e->activity_type = $type !== null ? mb_substr($type, 0, 64) : null;
            $e->actor_uri = $actor !== null ? mb_substr($actor, 0, 255) : null;
            $e->status = mb_substr($status, 0, 32);
            $e->note = $note;
            $e->body = $body;
            $e->created_at = date('Y-m-d H:i:s');
            $e->save(false);
            // Amortised retention: trim to the most recent MAX_ENTRIES roughly every 50 inserts.
            if ($e->id !== null && ($e->id % 50) === 0) {
                ActivityLogEntry::deleteAll(['<', 'id', $e->id - self::MAX_ENTRIES]);
            }
        } catch (\Throwable $ex) {
            Yii::warning('ActivityPub activity log failed: ' . $ex->getMessage(), 'activitypub');
        }
    }
}
