<?php

namespace humhub\modules\fediverse;

use humhub\models\RecordMap;
use humhub\modules\comment\models\Comment;
use humhub\modules\content\events\ContentStateEvent;
use humhub\modules\content\interfaces\ContentOwner;
use humhub\modules\content\models\Content;
use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\fediverse\jobs\DeliverActivity;
use humhub\modules\fediverse\jobs\DeliverToInbox;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederatedUserSource;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\NoteSerializer;
use humhub\modules\fediverse\services\PersonSerializer;
use humhub\modules\fediverse\services\RemoteTargetResolver;
use humhub\modules\like\models\Like;
use humhub\modules\post\models\Post;
use humhub\modules\user\events\FollowEvent;
use humhub\modules\user\models\Profile;
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
            RichText::convert((string) $record->message, RichText::FORMAT_HTML),
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

    /** The remote Note a like targets — a remote post (content) or a remote comment (addon) — or null. */
    private static function likeTarget($like): ?array
    {
        $addonId = $like->content_addon_record_id;
        if ($addonId instanceof \yii\db\Expression) {
            $addonId = null; // freshly-saved post-like carries Expression('NULL'), not real null
        }
        $resolver = new RemoteTargetResolver();
        if (empty($addonId)) {
            return $resolver->resolve((int) $like->content_id); // like on a post
        }
        // A like on an addon (e.g. a comment): content_addon_record_id is a RecordMap
        // id, NOT the addon's primary key — resolve it to the real record first.
        $addon = RecordMap::getById((int) $addonId, ContentOwner::class);
        if (!$addon instanceof Comment) {
            return null; // only comment-likes are federated
        }
        return $resolver->resolveComment((int) $addon->id);
    }

    /** Local user liked a remote post or remote comment → send Like to its author. */
    public static function onLikeCreated($event): void
    {
        $like = $event->sender;
        $actor = self::federatableActor((int) $like->created_by);
        if ($actor === null) {
            return; // not a local federatable user (shadow user or non-discoverable)
        }
        $target = self::likeTarget($like);
        if ($target === null) {
            return; // not a like on a remote post or remote comment
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

    /** Local comment → federate it: reply to a remote comment, reply to a remote post, or fan out on a local federated post. */
    public static function onCommentCreated($event): void
    {
        $comment = $event->sender;
        $actor = self::federatableActor((int) $comment->created_by);
        if ($actor === null) {
            return; // shadow user (inbound comment — loop guard) or non-federatable
        }

        if (!empty($comment->parent_comment_id)) {
            // Reply to a comment → federate only if the parent is a remote comment.
            $target = (new RemoteTargetResolver())->resolveComment((int) $comment->parent_comment_id);
            if ($target !== null) {
                self::sendReplyToRemote($comment, $actor, $target);
            }
            return; // replies to local comments are out of scope
        }

        $target = (new RemoteTargetResolver())->resolve((int) $comment->content_id);
        if ($target === null) {
            self::federateReplyToLocalPost($comment, $actor);
            return;
        }
        self::sendReplyToRemote($comment, $actor, $target);
    }

    /** Local user's display name changed → Update{Person} to their remote followers. */
    public static function onProfileUpdated($event): void
    {
        $profile = $event->sender;
        if (!$profile instanceof Profile) {
            return;
        }
        $changed = $event->changedAttributes ?? [];
        if (!array_key_exists('firstname', $changed)
            && !array_key_exists('lastname', $changed)
            && !array_key_exists('about', $changed)) {
            return; // nothing federatable changed (name or bio)
        }

        $user = $profile->user;
        if ($user === null) {
            return;
        }
        $actor = self::federatableActor((int) $user->id);
        if ($actor === null) {
            return; // shadow user (loop guard) or not federatable
        }

        $hasFollowers = FederationFollow::find()->where([
            'local_user_id' => $user->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
            'state' => FederationFollow::STATE_ACCEPTED,
        ])->exists();
        if (!$hasFollowers) {
            return; // nobody to notify
        }

        $serializer = new PersonSerializer();
        $extras = PersonSerializer::profileExtras($user);
        $document = $serializer->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon']);
        $activity = $serializer->updateActivity($actor, $document, $actor->ap_id . '#updates/' . bin2hex(random_bytes(8)));

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => (int) $user->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Deliver $comment as a Create{Note, inReplyTo: $target Note} to the remote author in $target. */
    private static function sendReplyToRemote($comment, LocalActor $actor, array $target): void
    {
        $noteApId = $actor->ap_id . '/comments/' . (int) $comment->id;
        self::recordObject($noteApId, 'Note', Comment::class, (int) $comment->id);

        $published = date('c', strtotime((string) $comment->created_at));
        $note = [
            'id' => $noteApId,
            'type' => 'Note',
            'attributedTo' => $actor->ap_id,
            'content' => RichText::convert((string) $comment->message, RichText::FORMAT_HTML),
            'inReplyTo' => $target['noteApId'],
            'published' => $published,
            'to' => [$target['remote']->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteApId . '/activity',
            'type' => 'Create',
            'actor' => $actor->ap_id,
            'published' => $published,
            'to' => [$target['remote']->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $note,
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $comment->created_by,
            'inboxUrl' => $target['remote']->inbox_url,
            'activityJson' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** A top-level comment on a LOCAL federated post → fan the reply out to the post author's remote followers. */
    private static function federateReplyToLocalPost($comment, LocalActor $actor): void
    {
        $content = Content::findOne((int) $comment->content_id);
        if ($content === null) {
            return;
        }
        $record = $content->getPolymorphicRelation();
        if (!$record instanceof Post) {
            return;
        }
        $postNoteId = self::existingNoteId(Post::class, (int) $record->id);
        if ($postNoteId === null) {
            return; // the post was never federated → no remote followers saw it
        }
        $postAuthor = $content->createdBy;
        if ($postAuthor === null) {
            return;
        }
        $authorActor = LocalActor::findOne(['user_id' => $postAuthor->id]);
        if ($authorActor === null) {
            return;
        }

        $noteApId = $actor->ap_id . '/comments/' . (int) $comment->id;
        self::recordObject($noteApId, 'Note', Comment::class, (int) $comment->id);

        $published = date('c', strtotime((string) $comment->created_at));
        $note = [
            'id' => $noteApId,
            'type' => 'Note',
            'attributedTo' => $actor->ap_id,
            'content' => RichText::convert((string) $comment->message, RichText::FORMAT_HTML),
            'inReplyTo' => $postNoteId,
            'published' => $published,
            'to' => [$authorActor->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteApId . '/activity',
            'type' => 'Create',
            'actor' => $actor->ap_id,
            'published' => $published,
            'to' => [$authorActor->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $note,
        ];
        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => (int) $postAuthor->id,        // deliver to the post author's followers
            'signerUserId' => (int) $comment->created_by,  // signed by the commenter
            'activityJson' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Set the display-name callbacks so federated users render from their Fediverse fields. */
    public static function onBeforeRequest(): void
    {
        $userModule = Yii::$app->getModule('user');
        $userModule->displayNameCallback = [self::class, 'displayName'];
        $userModule->displayNameSubCallback = [self::class, 'displayNameSub'];
    }

    /** Federated users → their Fediverse display name (fallback handle/username); others → HumHub default. */
    public static function displayName($user): string
    {
        if (!$user instanceof User) {
            return '';
        }
        if ($user->user_source === FederatedUserSource::ID && $user->profile !== null) {
            $dn = trim((string) $user->profile->fediverse_displayname);
            if ($dn !== '') {
                return $dn;
            }
            $handle = trim((string) $user->profile->fediverse_handle);
            return $handle !== '' ? $handle : $user->username;
        }
        // HumHub default (mirrors User::getDisplayName)
        $name = '';
        if ($user->profile !== null && Yii::$app->settings->get('displayNameFormat') === '{profile.firstname} {profile.lastname}') {
            $name = $user->profile->firstname . ' ' . $user->profile->lastname;
        }
        return ($name === '' || $name === ' ') ? $user->username : $name;
    }

    /** Federated users → their handle as the secondary line; others → HumHub default sub. */
    public static function displayNameSub($user): string
    {
        if (!$user instanceof User || $user->profile === null) {
            return '';
        }
        if ($user->user_source === FederatedUserSource::ID) {
            return trim((string) $user->profile->fediverse_handle);
        }
        return $user->profile->getFieldValue(Yii::$app->settings->get('displayNameSubFormat', ''), false, false) ?? '';
    }

    /** Register the Fediverse UserSource so it governs federated shadow users. */
    public static function onUserSourceCollectionSet($event): void
    {
        $event->parameters['userSources'][FederatedUserSource::ID] = ['class' => FederatedUserSource::class];
    }

    /** Local user removed a like on a remote post or comment → send Undo{Like}. */
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
        $target = self::likeTarget($like);
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
