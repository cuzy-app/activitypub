<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property string $ap_id
 * @property string $direction
 * @property string $object_type
 * @property string|null $model_class
 * @property int|null $model_id
 * @property string|null $in_reply_to_ap_id
 * @property string|null $raw_json
 * @property string|null $created_at
 */
class FederationObject extends ActiveRecord
{
    public const DIRECTION_LOCAL = 'local';
    public const DIRECTION_REMOTE = 'remote';

    public static function tableName()
    {
        return 'fediverse_object';
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
            [['ap_id', 'direction', 'object_type'], 'required'],
            [['model_id'], 'integer'],
            [['raw_json'], 'string'],
            [['direction'], 'string', 'max' => 16],
            [['direction'], 'in', 'range' => [self::DIRECTION_LOCAL, self::DIRECTION_REMOTE]],
            [['object_type'], 'string', 'max' => 32],
            [['ap_id', 'model_class', 'in_reply_to_ap_id'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
        ];
    }
}
