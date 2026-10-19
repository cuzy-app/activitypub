<?php

namespace humhub\modules\activitypub\jobs;

use humhub\modules\activitypub\services\RemoteActorResolver;
use humhub\modules\queue\ActiveJob;
use Yii;

/** Best-effort: fetch a remote Note and cache its like/boost counts + origin url (6h TTL). */
class RefreshRemotePost extends ActiveJob
{
    public string $apId = '';

    public function run()
    {
        if ($this->apId === '') {
            return;
        }
        try {
            $doc = (new RemoteActorResolver())->fetchActorDocument($this->apId);
        } catch (\Throwable $e) {
            Yii::warning('ActivityPub post-stats refresh failed for ' . $this->apId . ': ' . $e->getMessage(), 'activitypub');
            return;
        }
        $likes = isset($doc['likes']['totalItems']) && is_numeric($doc['likes']['totalItems']) ? (int) $doc['likes']['totalItems'] : null;
        $shares = isset($doc['shares']['totalItems']) && is_numeric($doc['shares']['totalItems']) ? (int) $doc['shares']['totalItems'] : null;
        $url = is_string($doc['url'] ?? null) ? $doc['url'] : null;
        Yii::$app->cache->set('activitypub.poststats.' . md5($this->apId), [
            'likes' => $likes,
            'shares' => $shares,
            'url' => $url,
        ], 21600);
    }
}
