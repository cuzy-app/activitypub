<?php

namespace humhub\modules\fediverse;

use yii\helpers\Url;

class Module extends \humhub\components\Module
{
    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/fediverse/admin/index']);
    }
}
