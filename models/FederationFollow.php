<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int $local_user_id
 * @property int $remote_actor_id
 * @property string $direction
 * @property string $state
 * @property string|null $activity_ap_id
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class FederationFollow extends ActiveRecord
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATE_PENDING = 'pending';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REJECTED = 'rejected';

    public static function tableName()
    {
        return 'fediverse_follow';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['local_user_id', 'remote_actor_id', 'direction'], 'required'],
            [['local_user_id', 'remote_actor_id'], 'integer'],
            [['direction'], 'in', 'range' => [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND]],
            [['state'], 'in', 'range' => [self::STATE_PENDING, self::STATE_ACCEPTED, self::STATE_REJECTED]],
            [['state'], 'default', 'value' => self::STATE_PENDING],
            [['activity_ap_id'], 'string', 'max' => 255],
        ];
    }
}
