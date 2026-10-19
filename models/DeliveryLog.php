<?php

namespace humhub\modules\activitypub\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int|null $object_id
 * @property string $target_inbox
 * @property string $status
 * @property int|null $http_code
 * @property int $attempts
 * @property string|null $last_attempt_at
 * @property string|null $created_at
 */
class DeliveryLog extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public static function tableName()
    {
        return 'activitypub_delivery_log';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['target_inbox'], 'required'],
            [['object_id', 'http_code', 'attempts'], 'integer'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_DELIVERED, self::STATUS_FAILED]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['attempts'], 'default', 'value' => 0],
            [['target_inbox'], 'string', 'max' => 255],
        ];
    }
}
