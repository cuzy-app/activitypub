<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\RemoteActor;

/**
 * For a given Content id, resolves the original remote ActivityPub object id and
 * its RemoteActor — i.e. "this post came from a remote account". Returns null
 * for local-origin or unknown content.
 */
class RemoteTargetResolver
{
    /** @return array{noteApId:string,remote:RemoteActor}|null */
    public function resolve(int $contentId): ?array
    {
        $content = Content::findOne($contentId);
        if ($content === null) {
            return null;
        }
        $record = $content->getPolymorphicRelation();
        if ($record === null) {
            return null;
        }

        $obj = FederationObject::findOne([
            'model_class' => get_class($record),
            'model_id' => $record->id,
            'direction' => FederationObject::DIRECTION_REMOTE,
            'object_type' => 'Note',
        ]);
        if ($obj === null) {
            return null; // local-origin (or not a federated note)
        }

        $container = $content->getContainer();
        if ($container === null) {
            return null;
        }
        $remote = RemoteActor::findOne(['user_id' => $container->id]);
        if ($remote === null || empty($remote->inbox_url)) {
            return null;
        }
        return ['noteApId' => $obj->ap_id, 'remote' => $remote];
    }
}
