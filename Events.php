<?php

namespace humhub\modules\fediverse;

use humhub\modules\content\events\ContentStateEvent;
use humhub\modules\content\models\Content;
use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\fediverse\jobs\DeliverActivity;
use humhub\modules\fediverse\jobs\DeliverToInbox;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\RemoteTargetResolver;
use humhub\modules\like\models\Like;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\NoteSerializer;
use humhub\modules\post\models\Post;
use humhub\modules\user\events\FollowEvent;
use humhub\modules\user\models\User;
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

    /** A LocalActor for a user that may federate as an actor, or null (shadow user / not discoverable). */
    private static function federatableActor(int $userId): ?LocalActor
    {
        $user = User::findOne($userId);
        if ($user === null) {
            return null;
        }
        if (RemoteActor::findOne(['user_id' => $user->id]) !== null) {
            return null; // a federated shadow user — its actions are inbound, never re-federated (loop guard)
        }
        if (!DiscoveryPolicy::isDiscoverable($user)) {
            return null; // no fetchable actor → remote can't verify; skip
        }
        return (new KeyManager())->getOrCreateForUser($user);
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

    /** A local user followed someone → if it's a remote shadow actor, send a Follow. */
    public static function onFollowCreated(FollowEvent $event): void
    {
        $follower = $event->user;
        $target = $event->target;
        if (!$follower instanceof User || !$target instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $target->id]);
        if ($remote === null) {
            return; // target is a normal local user, not a federated account
        }
        if (!DiscoveryPolicy::isDiscoverable($follower)) {
            Yii::warning('Fediverse: follower ' . $follower->id . ' is not federatable; remote follow not sent', 'fediverse');
            return;
        }

        $signer = (new KeyManager())->getOrCreateForUser($follower);

        $follow = FederationFollow::findOne([
            'local_user_id' => $follower->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
        ]);
        if ($follow !== null && $follow->state === FederationFollow::STATE_ACCEPTED) {
            return; // already following; avoid sending a duplicate Follow on re-save
        }
        if ($follow === null) {
            $follow = new FederationFollow();
            $follow->local_user_id = $follower->id;
            $follow->remote_actor_id = $remote->id;
            $follow->direction = FederationFollow::DIRECTION_OUTBOUND;
        }
        $follow->state = FederationFollow::STATE_PENDING;
        $follow->activity_ap_id = $signer->ap_id . '/follows/' . bin2hex(random_bytes(8));
        $follow->save();

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $follow->activity_ap_id,
            'type' => 'Follow',
            'actor' => $signer->ap_id,
            'object' => $remote->ap_id,
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => $follower->id,
            'inboxUrl' => $remote->inbox_url,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** A local user unfollowed a remote shadow actor → send Undo{Follow}. */
    public static function onFollowRemoved(FollowEvent $event): void
    {
        $follower = $event->user;
        $target = $event->target;
        if (!$follower instanceof User || !$target instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $target->id]);
        if ($remote === null) {
            return;
        }
        $follow = FederationFollow::findOne([
            'local_user_id' => $follower->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
        ]);
        if ($follow === null) {
            return;
        }
        $signer = LocalActor::findOne(['user_id' => $follower->id]);
        if ($signer !== null) {
            $undo = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $signer->ap_id . '/undos/' . bin2hex(random_bytes(8)),
                'type' => 'Undo',
                'actor' => $signer->ap_id,
                'object' => [
                    'id' => $follow->activity_ap_id,
                    'type' => 'Follow',
                    'actor' => $signer->ap_id,
                    'object' => $remote->ap_id,
                ],
            ];
            Yii::$app->queue->push(new DeliverToInbox([
                'signerUserId' => $follower->id,
                'inboxUrl' => $remote->inbox_url,
                'activityJson' => json_encode($undo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]));
        }
        $follow->delete();
    }

    /** Local user liked a remote-origin post → send Like to the remote author. */
    public static function onLikeCreated($event): void
    {
        $like = $event->sender;
        if (!empty($like->content_addon_record_id)) {
            return; // likes on comments are out of scope this phase
        }
        $actor = self::federatableActor((int) $like->created_by);
        if ($actor === null) {
            return; // not a local federatable user (shadow user or non-discoverable)
        }
        $target = (new RemoteTargetResolver())->resolve((int) $like->content_id);
        if ($target === null) {
            return; // not a remote-origin post
        }

        $likeApId = $actor->ap_id . '/likes/' . bin2hex(random_bytes(8));
        self::recordObject($likeApId, 'Like', Like::class, (int) $like->id);

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $likeApId,
            'type' => 'Like',
            'actor' => $actor->ap_id,
            'object' => $target['noteApId'],
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $like->created_by,
            'inboxUrl' => $target['remote']->inbox_url,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Local user removed a like on a remote post → send Undo{Like}. */
    public static function onLikeRemoved($event): void
    {
        $like = $event->sender;
        $obj = FederationObject::findOne([
            'model_class' => Like::class,
            'model_id' => (int) $like->id,
            'direction' => FederationObject::DIRECTION_LOCAL,
            'object_type' => 'Like',
        ]);
        if ($obj === null) {
            return; // this like was never federated
        }
        $signer = LocalActor::findOne(['user_id' => (int) $like->created_by]);
        $target = (new RemoteTargetResolver())->resolve((int) $like->content_id);
        if ($signer !== null && $target !== null) {
            $undo = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $signer->ap_id . '/undos/' . bin2hex(random_bytes(8)),
                'type' => 'Undo',
                'actor' => $signer->ap_id,
                'object' => [
                    'id' => $obj->ap_id,
                    'type' => 'Like',
                    'actor' => $signer->ap_id,
                    'object' => $target['noteApId'],
                ],
            ];
            Yii::$app->queue->push(new DeliverToInbox([
                'signerUserId' => (int) $like->created_by,
                'inboxUrl' => $target['remote']->inbox_url,
                'activityJson' => json_encode($undo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]));
        }
        $obj->delete();
    }
}
