<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\activitypub\models\FederationObject;
use humhub\modules\activitypub\models\RemoteActor;

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

    /**
     * Resolve a remote-origin COMMENT (by its local Comment id) to the original
     * remote Note ap_id and the commenter's RemoteActor. Null for local comments.
     *
     * @return array{noteApId:string,remote:RemoteActor}|null
     */
    public function resolveComment(int $commentId): ?array
    {
        $comment = Comment::findOne($commentId);
        if ($comment === null) {
            return null;
        }
        $obj = FederationObject::findOne([
            'model_class' => Comment::class,
            'model_id' => $comment->id,
            'direction' => FederationObject::DIRECTION_REMOTE,
            'object_type' => 'Note',
        ]);
        if ($obj === null) {
            return null; // not a remote-origin comment
        }
        $remote = RemoteActor::findOne(['user_id' => $comment->created_by]);
        if ($remote === null || empty($remote->inbox_url)) {
            return null;
        }
        return ['noteApId' => $obj->ap_id, 'remote' => $remote];
    }
}
