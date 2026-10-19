<?php

namespace humhub\modules\activitypub;

use yii\helpers\Url;

class Module extends \humhub\components\Module
{
    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/activitypub/admin/index']);
    }
}
