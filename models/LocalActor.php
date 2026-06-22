<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string $preferred_username
 * @property string $ap_id
 * @property string|null $public_key
 * @property string|null $private_key
 * @property bool $federation_enabled
 * @property int $followers_count
 * @property int $following_count
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class LocalActor extends ActiveRecord
{
    public const TYPE_PERSON = 'Person';
    public const TYPE_APPLICATION = 'Application';

    public static function tableName()
    {
        return 'fediverse_local_actor';
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
            [['type', 'preferred_username', 'ap_id'], 'required'],
            [['user_id', 'followers_count', 'following_count'], 'integer'],
            [['federation_enabled'], 'boolean'],
            [['public_key', 'private_key'], 'string'],
            [['type'], 'string', 'max' => 32],
            [['preferred_username', 'ap_id'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
            [['preferred_username'], 'unique'],
            [['user_id'], 'unique'],
        ];
    }
}
