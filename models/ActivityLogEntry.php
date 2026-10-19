<?php

namespace humhub\modules\activitypub\models;

use yii\db\ActiveRecord;

/**
 * One recorded federation activity (debug log).
 *
 * @property int $id
 * @property string $direction
 * @property string|null $activity_type
 * @property string|null $actor_uri
 * @property string $status
 * @property string|null $note
 * @property string|null $body
 * @property string $created_at
 */
class ActivityLogEntry extends ActiveRecord
{
    public static function tableName()
    {
        return 'activitypub_activity_log';
    }
}
