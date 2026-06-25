<?php

namespace humhub\modules\fediverse\models;

use humhub\modules\user\helpers\AuthHelper;
use humhub\modules\user\models\Group;
use Yii;
use yii\base\Model;
use yii\helpers\ArrayHelper;

class ConfigureForm extends Model
{
    /** @var bool global on/off switch for all federation */
    public $enabled = false;

    /** @var bool opt-in debug log of inbound/outbound activities */
    public $activityLogEnabled = false;

    /** @var int|null group federated (remote) users are added to; 0/null = none */
    public $remoteUserGroupId;

    public function rules()
    {
        return [
            [['enabled', 'activityLogEnabled'], 'boolean'],
            [['remoteUserGroupId'], 'integer'],
            [['remoteUserGroupId'], 'default', 'value' => null],
        ];
    }

    public function attributeLabels()
    {
        return [
            'enabled' => Yii::t('FediverseModule.base', 'Enable federation for this site'),
            'activityLogEnabled' => Yii::t('FediverseModule.base', 'Log federation activity (debug)'),
            'remoteUserGroupId' => Yii::t('FediverseModule.base', 'Group for federated (remote) users'),
        ];
    }

    public function loadSettings(): self
    {
        $this->enabled = (bool) $this->getModule()->settings->get('enabled', false);
        $this->activityLogEnabled = (bool) $this->getModule()->settings->get('activityLogEnabled', false);
        $this->remoteUserGroupId = ((int) $this->getModule()->settings->get('remoteUserGroupId', 0)) ?: null;
        return $this;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->getModule()->settings->set('enabled', (bool) $this->enabled);
        $this->getModule()->settings->set('activityLogEnabled', (bool) $this->activityLogEnabled);
        $this->getModule()->settings->set('remoteUserGroupId', (int) $this->remoteUserGroupId);
        return true;
    }

    /** Group options (id => name) for the picker; excludes the admin group. */
    public function getGroupOptions(): array
    {
        return ArrayHelper::map(
            Group::find()->where(['is_admin_group' => 0])->orderBy('name')->all(),
            'id',
            'name'
        );
    }

    /**
     * Federation can only ever publish guest-visible content, so guest access
     * must be enabled site-wide (spec §5). Used by the view to warn the admin.
     */
    public function isGuestAccessEnabled(): bool
    {
        return AuthHelper::isGuestAccessEnabled();
    }

    private function getModule(): \humhub\components\Module
    {
        return Yii::$app->getModule('fediverse');
    }
}
