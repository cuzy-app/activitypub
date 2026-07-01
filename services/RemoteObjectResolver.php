<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\interfaces\ContentProvider;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;

/**
 * Resolves an inbound ActivityPub object id we previously ingested
 * (DIRECTION_REMOTE) back to its native HumHub record (a Post or Comment), or
 * to the root Content it belongs to. The mirror of {@see LocalObjectResolver},
 * which resolves objects WE published.
 */
class RemoteObjectResolver
{
    /** The ingested record (Post or Comment) for a remote AP id, or null. */
    public function resolveRecord(string $apId): ?ContentProvider
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne([
            'ap_id' => $apId,
            'direction' => FederationObject::DIRECTION_REMOTE,
        ]);
        if ($obj === null || $obj->model_class === null || $obj->model_id === null) {
            return null;
        }
        if (!class_exists($obj->model_class) || !method_exists($obj->model_class, 'findOne')) {
            return null;
        }
        $record = $obj->model_class::findOne($obj->model_id);
        return $record instanceof ContentProvider ? $record : null;
    }

    /**
     * The root Content an ingested remote object belongs to, or null. For a
     * thread continuation this is the Content of the original post — replying to
     * either the root post or an earlier continuation comment resolves to the
     * same root, so multi-part posts stay flat under one entry.
     */
    public function resolveRootContent(string $apId): ?Content
    {
        $record = $this->resolveRecord($apId);
        return ($record !== null && isset($record->content)) ? $record->content : null;
    }
}
