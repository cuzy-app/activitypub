<?php

namespace humhub\modules\activitypub\tests\codeception\fixtures;

use yii\test\DbFixture;

/**
 * Empties the module's own tables before each test. The core fixtures only reset
 * core tables, and their auto-increment resets implicitly commit the test
 * transaction, so rows written by one test would otherwise leak into the next.
 */
class ActivityPubFixture extends DbFixture
{
    public const TABLES = [
        'activitypub_activity_log',
        'activitypub_delivery_log',
        'activitypub_follow',
        'activitypub_object',
        'activitypub_space_community',
        'activitypub_local_actor',
        'activitypub_remote_actor',
    ];

    public function load()
    {
        foreach (self::TABLES as $table) {
            $this->db->createCommand()->delete($table)->execute();
        }
    }
}
