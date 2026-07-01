<?php

namespace humhub\modules\fediverse\services;

use humhub\models\RecordMap;
use humhub\modules\comment\models\Comment;
use humhub\modules\content\components\ContentActiveRecord;
use humhub\modules\content\components\ContentAddonActiveRecord;
use humhub\modules\content\interfaces\ContentProvider;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\file\models\File;
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
    /**
     * Add a remote Note as a comment on the given content (idempotent by Note id).
     * Used both for replies to our own content and for the continuation parts of
     * a remote multi-part post (self-reply thread), so each part keeps its own AP
     * id mapping — that mapping is what lets a later Update/Delete of the part
     * resolve back to this comment.
     */
    public function addRemoteComment(Content $content, User $author, array $note): void
    {
        $noteApId = (string) ($note['id'] ?? '');
        if ($noteApId === '') {
            Yii::warning('Fediverse inbound comment dropped: Note has no id', 'fediverse');
            return; // an id-less Note cannot be de-duplicated; reject rather than risk duplicates
        }
        if (FederationObject::find()->where(['ap_id' => $noteApId])->exists()) {
            return; // already processed
        }

        $message = FederatedHtml::applyCustomEmoji($this->toPlainText((string) ($note['content'] ?? '')), $note['tag'] ?? []);
        $comment = new Comment(['message' => $message]);
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
        $this->attachRemoteImages($comment, $note);
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

    private const MAX_ATTACHMENTS = 8;

    /**
     * Download a remote Note's image attachments and attach them to the ingested
     * record (a Post or a Comment — both expose ->fileManager and ->content).
     */
    public function attachRemoteImages(ContentActiveRecord|ContentAddonActiveRecord $record, array $note): void
    {
        $attachments = $note['attachment'] ?? null;
        if (!is_array($attachments)) {
            return;
        }
        $fetcher = new RemoteMediaFetcher();
        $count = 0;
        foreach ($attachments as $att) {
            if ($count >= self::MAX_ATTACHMENTS) {
                break;
            }
            $url = is_array($att) ? ($att['url'] ?? null) : null;
            if (!is_string($url)) {
                continue;
            }
            $media = $fetcher->fetchImage($url);
            if ($media === null) {
                continue; // non-image / unsafe / too big / failed
            }
            try {
                $file = new File();
                $file->file_name = $fetcher->filenameFor($url, $media['mime']);
                $file->mime_type = $media['mime'];
                $file->size = strlen($media['content']);
                $file->created_by = (int) $record->content->created_by; // shadow user; queue has no logged-in user
                if (!$file->save()) {
                    Yii::warning('Fediverse attachment File save failed: ' . implode(', ', $file->getErrorSummary(true)), 'fediverse');
                    continue;
                }
                $file->setStoredFileContent($media['content']);
                $record->fileManager->attach($file);
                $count++;
            } catch (\Throwable $e) {
                Yii::warning('Fediverse attachment error for ' . $url . ': ' . $e->getMessage(), 'fediverse');
            }
        }
    }

    private function toPlainText(string $html): string
    {
        // Mastodon Note content is HTML; store readable plain text (block breaks + decoded entities).
        return FederatedHtml::toText($html);
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
