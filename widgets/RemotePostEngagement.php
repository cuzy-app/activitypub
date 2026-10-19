<?php

namespace humhub\modules\activitypub\widgets;

use humhub\components\Widget;
use Yii;

/** Read-only engagement element for an ingested remote post: real like/boost counts + origin link. */
class RemotePostEngagement extends Widget
{
    public $apId;

    public function run()
    {
        if (!$this->apId) {
            return '';
        }
        $stats = Yii::$app->cache->get('activitypub.poststats.' . md5($this->apId));
        $url = (is_array($stats) && !empty($stats['url'])) ? $stats['url'] : $this->apId;
        return $this->render('remotePostEngagement', [
            'url' => $url,
            'likes' => is_array($stats) ? ($stats['likes'] ?? null) : null,
            'shares' => is_array($stats) ? ($stats['shares'] ?? null) : null,
        ]);
    }
}
