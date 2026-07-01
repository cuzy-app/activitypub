<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use humhub\modules\space\models\Space;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Couples a HumHub Space to a remote Lemmy-family community (a Group actor).
 * One row per federated Space; carries the community's AP identity and the
 * delivery targets we send Follow/interactions to.
 *
 * @property int $id
 * @property int $space_id
 * @property string $community_ap_id
 * @property string|null $handle
 * @property string|null $preferred_username
 * @property string|null $name
 * @property string|null $summary
 * @property string|null $icon_url
 * @property string|null $image_url
 * @property string $inbox_url
 * @property string|null $shared_inbox_url
 * @property string|null $followers_url
 * @property string|null $outbox_url
 * @property string $follow_state
 * @property string|null $follow_activity_ap_id
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class SpaceCommunity extends ActiveRecord
{
    public const FOLLOW_STATE_PENDING = 'pending';
    public const FOLLOW_STATE_ACCEPTED = 'accepted';
    public const FOLLOW_STATE_REJECTED = 'rejected';

    public static function tableName()
    {
        return 'fediverse_space_community';
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
            [['space_id', 'community_ap_id', 'inbox_url'], 'required'],
            [['space_id'], 'integer'],
            [['summary'], 'string'],
            [['community_ap_id', 'handle', 'preferred_username', 'name', 'icon_url', 'image_url',
              'inbox_url', 'shared_inbox_url', 'followers_url', 'outbox_url', 'follow_activity_ap_id'], 'string', 'max' => 255],
            [['follow_state'], 'string', 'max' => 16],
            [['follow_state'], 'default', 'value' => self::FOLLOW_STATE_PENDING],
            [['space_id'], 'unique'],
            [['community_ap_id'], 'unique'],
        ];
    }

    public function getSpace()
    {
        return $this->hasOne(Space::class, ['id' => 'space_id']);
    }
}
