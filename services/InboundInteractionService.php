<?php

namespace humhub\modules\fediverse\services;

use humhub\models\RecordMap;
use humhub\modules\comment\models\Comment;
use humhub\modules\content\components\ContentAddonActiveRecord;
use humhub\modules\content\interfaces\ContentProvider;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\like\models\Like;
use humhub\modules\user\models\User;
use Yii;

/**
 * Creates native HumHub comments/likes on behalf of a remote (shadow) user in
 * response to inbound activities. Idempotent: a given remote object id is only
 * applied once. `created_by` is set explicitly — HumHub's beforeSave only
 * auto-fills it when empty, so the shadow author sticks in the queue context.
 */
class InboundInteractionService
{
    /** Add a remote reply as a comment on the given content (idempotent by Note id). */
    public function addComment(Content $content, User $author, string $contentHtml, string $noteApId): void
    {
        if ($noteApId === '') {
            Yii::warning('Fediverse inbound comment dropped: Note has no id', 'fediverse');
            return; // an id-less Note cannot be de-duplicated; reject rather than risk duplicates
        }
        if (FederationObject::find()->where(['ap_id' => $noteApId])->exists()) {
            return; // already processed
        }

        $comment = new Comment(['message' => $this->toPlainText($contentHtml)]);
        $comment->content_id = $content->id;
        $comment->created_by = $author->id;
        try {
            if (!$comment->save()) {
                Yii::warning('Fediverse inbound comment save failed: ' . implode(', ', $comment->getErrorSummary(true)), 'fediverse');
                return;
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse inbound comment not saved: ' . $e->getMessage(), 'fediverse');
            return;
        }
        $this->record($noteApId, 'Note', Comment::class, $comment->id);
    }

    /** Add a like by the remote user on the given record — a post or a comment (idempotent). */
    public function addLike(ContentProvider $record, User $author, string $likeApId): void
    {
        if ($likeApId !== '' && FederationObject::find()->where(['ap_id' => $likeApId])->exists()) {
            return;
        }
        // For an addon (e.g. a comment) the like's content_addon_record_id is the
        // addon's RecordMap id, not its primary key; for the content itself it is null.
        $addonRecordId = $record instanceof ContentAddonActiveRecord ? RecordMap::getId($record) : null;
        $exists = Like::find()
            ->where(['content_id' => $record->content->id, 'created_by' => $author->id, 'content_addon_record_id' => $addonRecordId])
            ->exists();
        if ($exists) {
            return;
        }

        $like = new Like();
        $like->content_id = $record->content->id;
        $like->content_addon_record_id = $addonRecordId;
        $like->created_by = $author->id; // explicit: the queue has no logged-in user
        try {
            if (!$like->save()) {
                Yii::warning('Fediverse inbound like save failed: ' . implode(', ', $like->getErrorSummary(true)), 'fediverse');
                return;
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse inbound like not saved: ' . $e->getMessage(), 'fediverse');
            return;
        }
        if ($likeApId !== '') {
            $this->record($likeApId, 'Like', Like::class, $like->id);
        }
    }

    /** Remove the remote user's like from the given record (post or comment). */
    public function removeLike(ContentProvider $record, User $author): void
    {
        $addonRecordId = $record instanceof ContentAddonActiveRecord ? RecordMap::getId($record) : null;
        $like = Like::find()
            ->where(['content_id' => $record->content->id, 'created_by' => $author->id, 'content_addon_record_id' => $addonRecordId])
            ->one();
        if ($like !== null) {
            $like->delete();
        }
    }

    private function toPlainText(string $html): string
    {
        // Mastodon Note content is HTML; store a readable plain-text comment for now.
        $text = preg_replace('#</p>\s*<p>#i', "\n\n", $html);
        if ($text === null) {
            $text = $html;
        }
        $stripped = preg_replace('#<br\s*/?>#i', "\n", $text);
        if ($stripped === null) {
            $stripped = $text;
        }
        return trim(html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function record(string $apId, string $type, string $modelClass, int $modelId): void
    {
        if (FederationObject::find()->where(['ap_id' => $apId])->exists()) {
            return;
        }
        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = $type;
        $obj->model_class = $modelClass;
        $obj->model_id = $modelId;
        if (!$obj->save()) {
            Yii::warning('Fediverse object mapping failed for ' . $apId . ': ' . implode(', ', $obj->getErrorSummary(true)), 'fediverse');
        }
    }
}
