<?php

namespace humhub\modules\fediverse\models;

use humhub\modules\user\helpers\AuthHelper;
use Yii;
use yii\base\Model;

class ConfigureForm extends Model
{
    /** @var bool global on/off switch for all federation */
    public $enabled = false;

    public function rules()
    {
        return [
            [['enabled'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'enabled' => Yii::t('FediverseModule.base', 'Enable federation for this site'),
        ];
    }

    public function loadSettings(): self
    {
        $this->enabled = (bool) $this->getModule()->settings->get('enabled', false);
        return $this;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->getModule()->settings->set('enabled', (bool) $this->enabled);
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
