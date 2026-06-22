<?php

namespace humhub\modules\fediverse;

use humhub\modules\content\events\ContentStateEvent;
use humhub\modules\content\models\Content;
use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\fediverse\jobs\DeliverActivity;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\NoteSerializer;
use humhub\modules\post\models\Post;
use Yii;

class Events
{
    /** Content published → Create{Note} to the author's followers. */
    public static function onContentStateChanged(ContentStateEvent $event): void
    {
        if ((int) $event->newState !== Content::STATE_PUBLISHED) {
            return;
        }
        $content = $event->content;
        $record = $content->getPolymorphicRelation();
        if (!$record instanceof Post || !FederationContentPolicy::mayFederate($content)) {
            return;
        }

        $author = $content->createdBy;
        if ($author === null) {
            return;
        }
        $actor = (new KeyManager())->getOrCreateForUser($author);

        $noteId = $actor->ap_id . '/notes/' . $content->id;
        $serializer = new NoteSerializer();
        $note = $serializer->note(
            $actor->ap_id,
            $noteId,
            RichText::output((string) $record->message),
            date('c', strtotime((string) $content->created_at)),
            self::absoluteUrl($content),
            self::attachments($record, $serializer)
        );
        $activity = $serializer->wrap('Create', $actor->ap_id, $note, $noteId . '/activity');

        // Record the mapping so the outbox and later Update/Delete can resolve it.
        self::recordObject($noteId, 'Note', Post::class, $record->id);

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => $author->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Post soft-deleted → Delete{Note}. */
    public static function onContentDeleted($event): void
    {
        $record = $event->sender;
        if (!$record instanceof Post) {
            return;
        }
        $noteId = self::existingNoteId(Post::class, $record->id);
        if ($noteId === null) {
            return; // was never federated
        }
        $content = $record->content;
        $author = $content?->createdBy;
        if ($author === null) {
            return;
        }
        $actor = (new KeyManager())->getOrCreateForUser($author);
        $activity = (new NoteSerializer())->delete($actor->ap_id, $noteId, $noteId . '/delete');

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => $author->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    private static function attachments(Post $record, NoteSerializer $serializer): array
    {
        $out = [];
        foreach ($record->fileManager->findAll() as $file) {
            $out[] = $serializer->attachment((string) $file->mime_type, $file->getUrl([], true));
        }
        return $out;
    }

    private static function recordObject(string $apId, string $type, string $modelClass, int $modelId): void
    {
        if (FederationObject::find()->where(['ap_id' => $apId])->exists()) {
            return;
        }
        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_LOCAL;
        $obj->object_type = $type;
        $obj->model_class = $modelClass;
        $obj->model_id = $modelId;
        $obj->save();
    }

    private static function existingNoteId(string $modelClass, int $modelId): ?string
    {
        $obj = FederationObject::findOne([
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'direction' => FederationObject::DIRECTION_LOCAL,
        ]);
        return $obj?->ap_id;
    }

    /** Absolute permalink robust in console (queue) context where request hostInfo may be unset. */
    private static function absoluteUrl(Content $content): string
    {
        $url = $content->getUrl(true);
        $parts = parse_url($url);
        if (!empty($parts['host'])) {
            return $url;
        }
        return FederationUrl::base() . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
