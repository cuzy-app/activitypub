<?php

namespace humhub\modules\fediverse;

use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\components\ContentContainerModule;
use humhub\modules\user\models\User;
use Yii;
use yii\helpers\Url;

class Module extends ContentContainerModule
{
    /** @inheritdoc */
    public function getConfigUrl()
    {
        return Url::to(['/fediverse/admin/index']);
    }

    /** @inheritdoc — federation applies to user profiles in this phase (Spaces in Phase 3). */
    public function getContentContainerTypes()
    {
        return [User::class];
    }

    /** @inheritdoc */
    public function getContentContainerName(ContentContainerActiveRecord $container)
    {
        return Yii::t('FediverseModule.base', 'Fediverse');
    }

    /** @inheritdoc — per-profile "Configure" link. */
    public function getContentContainerConfigUrl(ContentContainerActiveRecord $container)
    {
        if ($container instanceof User) {
            return $container->createUrl('/fediverse/profile-config/index');
        }
        return '';
    }
}
