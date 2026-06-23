<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\interfaces\ContentProvider;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;

/**
 * Resolves an ActivityPub object id we previously published (a local Note)
 * back to its HumHub Content, via the fediverse_object mapping.
 */
class LocalObjectResolver
{
    public function resolveContent(string $apId): ?Content
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne([
            'ap_id' => $apId,
            'direction' => FederationObject::DIRECTION_LOCAL,
        ]);
        if ($obj === null || $obj->model_class === null || $obj->model_id === null) {
            return null;
        }
        if (!class_exists($obj->model_class) || !method_exists($obj->model_class, 'findOne')) {
            return null;
        }
        $record = $obj->model_class::findOne($obj->model_id);
        // The mapped records are ContentActiveRecords (e.g. Post) exposing ->content.
        return ($record !== null && isset($record->content)) ? $record->content : null;
    }

    /**
     * Resolve an ActivityPub object id we published back to the actual likeable
     * record (a Post or a Comment) — NOT collapsed to its parent Content.
     */
    public function resolveLikeable(string $apId): ?ContentProvider
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne([
            'ap_id' => $apId,
            'direction' => FederationObject::DIRECTION_LOCAL,
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
}
