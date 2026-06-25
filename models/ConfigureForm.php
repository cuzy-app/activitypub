<?php

namespace humhub\modules\fediverse\models;

use humhub\modules\user\helpers\AuthHelper;
use Yii;
use yii\base\Model;

class ConfigureForm extends Model
{
    /** @var bool global on/off switch for all federation */
    public $enabled = false;

    /** @var bool opt-in debug log of inbound/outbound activities */
    public $activityLogEnabled = false;

    public function rules()
    {
        return [
            [['enabled', 'activityLogEnabled'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'enabled' => Yii::t('FediverseModule.base', 'Enable federation for this site'),
            'activityLogEnabled' => Yii::t('FediverseModule.base', 'Log federation activity (debug)'),
        ];
    }

    public function loadSettings(): self
    {
        $this->enabled = (bool) $this->getModule()->settings->get('enabled', false);
        $this->activityLogEnabled = (bool) $this->getModule()->settings->get('activityLogEnabled', false);
        return $this;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->getModule()->settings->set('enabled', (bool) $this->enabled);
        $this->getModule()->settings->set('activityLogEnabled', (bool) $this->activityLogEnabled);
        return true;
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
