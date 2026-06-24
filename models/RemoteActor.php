<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int $user_id
 * @property string $ap_id
 * @property string $type
 * @property string|null $preferred_username
 * @property string|null $handle
 * @property string $domain
 * @property string $inbox_url
 * @property string|null $shared_inbox_url
 * @property string|null $outbox_url
 * @property string|null $public_key
 * @property string|null $icon_url
 * @property string|null $profile_url
 * @property int|null $followers_count
 * @property int|null $following_count
 * @property string|null $last_refreshed_at
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class RemoteActor extends ActiveRecord
{
    public static function tableName()
    {
        return 'fediverse_remote_actor';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['user_id', 'ap_id', 'type', 'domain', 'inbox_url'], 'required'],
            [['user_id'], 'integer'],
            [['public_key'], 'string'],
            [['type'], 'string', 'max' => 32],
            [['ap_id', 'preferred_username', 'handle', 'domain', 'inbox_url', 'shared_inbox_url', 'outbox_url', 'icon_url'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
            [['user_id'], 'unique'],
        ];
    }
}
