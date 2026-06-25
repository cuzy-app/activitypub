<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\services\ContentTagService;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\file\models\File;
use humhub\modules\post\models\Post;
use humhub\modules\topic\models\Topic;
use humhub\modules\fediverse\services\FederatedHtml;
use humhub\modules\fediverse\services\InboundInteractionService;
use humhub\modules\fediverse\services\LocalObjectResolver;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\fediverse\services\RemoteMediaFetcher;
use humhub\modules\fediverse\services\SignatureService;
use humhub\modules\queue\ActiveJob;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use Yii;

/**
 * Authenticates an inbound activity's HTTP Signature (digest required, fresh
 * date, key fetched from keyId, signer == activity actor) and ONLY THEN
 * resolves/persists the remote actor and dispatches by type. Phase 3 handles
 * Follow and Undo{Follow}; other types are logged and ignored.
 */
class ProcessInboxActivity extends ActiveJob
{
    public string $rawBody = '';
    public array $headers = [];
    public string $requestPath = '';
    public ?string $targetGuid = null;

    public function run()
    {
        $activity = json_decode($this->rawBody, true);
        if (!is_array($activity) || empty($activity['type']) || empty($activity['actor'])) {
            Yii::warning('Fediverse inbox: malformed activity', 'fediverse');
            return;
        }

        $actorUrl = is_array($activity['actor']) ? ($activity['actor']['id'] ?? '') : (string) $activity['actor'];
        if ($actorUrl === '') {
            return;
        }

        // Authenticate BEFORE any persistence or trust. Returns the verified
        // signer document; the shadow actor is created lazily by the handlers,
        // only when something is actually stored.
        $signerDoc = $this->authenticate($actorUrl);
        if ($signerDoc === null) {
            Yii::warning('Fediverse inbox: authentication failed for ' . $actorUrl, 'fediverse');
            return;
        }
        $signerId = (string) ($signerDoc['id'] ?? $actorUrl);

        switch ($activity['type']) {
            case 'Follow':
                $this->handleFollow($activity, $signerId, $signerDoc);
                break;
            case 'Undo':
                $this->handleUndo($activity, $signerId);
                break;
            case 'Create':
                $this->handleCreate($activity, $signerId, $signerDoc);
                break;
            case 'Like':
                $this->handleLike($activity, $signerId, $signerDoc);
                break;
            case 'Accept':
                $this->handleAccept($activity, $signerId);
                break;
            case 'Delete':
                $this->handleDelete($activity, $signerId);
                break;
            case 'Update':
                $this->handleUpdate($activity, $signerId);
                break;
            default:
                Yii::info('Fediverse inbox: ignoring unsupported type ' . $activity['type'], 'fediverse');
        }
    }

    /**
     * Verify the HTTP Signature and, on success, return the verified signer
     * document (NOT persisted — handlers create the shadow actor only when they
     * actually store something). Returns null (caller drops the activity) on any
     * verification failure.
     */
    private function authenticate(string $actorUrl): ?array
    {
        $parsed = SignatureService::parseSignatureHeader($this->headers['signature'] ?? '');
        if (empty($parsed['signature']) || empty($parsed['headers']) || empty($parsed['keyId'])) {
            return null;
        }

        $headerList = explode(' ', $parsed['headers']);

        // Body must be authenticated: digest required in the signed set and matching.
        if (!in_array('digest', $headerList, true)) {
            Yii::warning('Fediverse inbox: digest not in signed headers [' . $parsed['headers'] . ']', 'fediverse');
            return null;
        }
        if (!hash_equals(SignatureService::digest($this->rawBody), (string) ($this->headers['digest'] ?? ''))) {
            Yii::warning('Fediverse inbox: digest mismatch (got ' . (string) ($this->headers['digest'] ?? '') . ')', 'fediverse');
            return null;
        }

        // Reject stale/future-dated requests (basic replay window).
        if (!$this->isFreshDate((string) ($this->headers['date'] ?? ''))) {
            Yii::warning('Fediverse inbox: stale/invalid date (' . (string) ($this->headers['date'] ?? '') . ')', 'fediverse');
            return null;
        }

        // Fetch the signer's key from keyId (NOT from the body); no persistence.
        $signerUrl = preg_replace('/#.*$/', '', $parsed['keyId']);
        $resolver = new RemoteActorResolver();
        try {
            $signerDoc = $resolver->fetchActorDocument($signerUrl);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse inbox: signer fetch failed: ' . $e->getMessage(), 'fediverse');
            return null;
        }
        $pem = $signerDoc['publicKey']['publicKeyPem'] ?? null;
        if (!is_string($pem) || $pem === '') {
            return null;
        }

        $svc = new SignatureService();
        // Reconstruct from ALL received headers so any signed header resolves.
        $signingString = $svc->buildSigningString('POST', $this->requestPath, $this->headers, $headerList);
        if (!$svc->verifyString($signingString, $parsed['signature'], $pem)) {
            Yii::warning('Fediverse inbox: signature verify failed (actor ' . $actorUrl
                . ', signed headers [' . $parsed['headers'] . '])', 'fediverse');
            return null;
        }

        // Binding: the signer (keyId owner) must equal the activity actor.
        $signerId = (string) ($signerDoc['id'] ?? $signerUrl);
        if ($signerId !== $actorUrl) {
            Yii::warning('Fediverse inbox: actor/keyId mismatch (' . $actorUrl . ' vs ' . $signerId . ')', 'fediverse');
            return null;
        }

        return $signerDoc;
    }

    /** Persist (creating if needed) the signer as a remote actor — call only when about to store. */
    private function persistActor(string $signerId, array $signerDoc): RemoteActor
    {
        return (new RemoteActorResolver())->resolveFromDocument($signerId, $signerDoc);
    }

    private function isFreshDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }
        $delta = time() - $ts;
        return $delta <= 3600 && $delta >= -300; // up to 1h old, 5min future skew
    }

    private function handleFollow(array $activity, string $signerId, array $signerDoc): void
    {
        $localUser = $this->resolveFollowTarget($activity['object'] ?? null);
        if ($localUser === null) {
            Yii::warning('Fediverse Follow: unknown target object', 'fediverse');
            return;
        }
        $remote = $this->persistActor($signerId, $signerDoc);

        $follow = FederationFollow::findOne([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
        if ($follow === null) {
            $follow = new FederationFollow();
            $follow->local_user_id = $localUser->id;
            $follow->remote_actor_id = $remote->id;
            $follow->direction = FederationFollow::DIRECTION_INBOUND;
        }
        $follow->state = FederationFollow::STATE_ACCEPTED; // auto-accept (spec §12)
        $follow->activity_ap_id = $activity['id'] ?? null;
        $follow->save();

        $this->sendAccept($localUser, $remote, $activity);
    }

    private function handleUndo(array $activity, string $signerId): void
    {
        $remote = RemoteActor::findOne(['ap_id' => $signerId]);
        if ($remote === null) {
            return; // nothing of theirs to undo
        }
        $inner = $activity['object'] ?? null;
        if (!is_array($inner)) {
            return;
        }
        $type = $inner['type'] ?? '';

        if ($type === 'Follow') {
            $localUser = $this->resolveFollowTarget($inner['object'] ?? null);
            if ($localUser === null) {
                return;
            }
            FederationFollow::deleteAll([
                'local_user_id' => $localUser->id,
                'remote_actor_id' => $remote->id,
                'direction' => FederationFollow::DIRECTION_INBOUND,
            ]);
            return;
        }

        if ($type === 'Like') {
            $record = (new LocalObjectResolver())->resolveLikeable($this->objectId($inner['object'] ?? null));
            $author = User::findOne($remote->user_id);
            if ($record !== null && $author !== null) {
                (new InboundInteractionService())->removeLike($record, $author);
            } else {
                Yii::warning('Fediverse Undo{Like}: could not resolve likeable/author', 'fediverse');
            }
        }
    }

    /** Inbound Create{Note, inReplyTo} → comment on the replied-to local content. */
    private function handleCreate(array $activity, string $signerId, array $signerDoc): void
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || ($object['type'] ?? '') !== 'Note') {
            return;
        }
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (!is_string($inReplyTo) || $inReplyTo === '') {
            // Top-level post: only ingest from an actor a local user already follows;
            // never mint a shadow user for a stranger's post.
            $remote = RemoteActor::findOne(['ap_id' => $signerId]);
            if ($remote !== null) {
                $this->ingestRemotePost($object, $remote);
            }
            return;
        }
        $content = (new LocalObjectResolver())->resolveContent($inReplyTo);
        if ($content === null) {
            return; // a reply to something we don't host
        }
        $remote = $this->persistActor($signerId, $signerDoc);
        $author = User::findOne($remote->user_id);
        if ($author === null) {
            return;
        }
        (new InboundInteractionService())->addComment(
            $content,
            $author,
            (string) ($object['content'] ?? ''),
            (string) ($object['id'] ?? '')
        );
    }

    /** Inbound Delete{Note|Tombstone} → soft-delete the ingested remote post/comment (author only). */
    private function handleDelete(array $activity, string $signerId): void
    {
        $remote = RemoteActor::findOne(['ap_id' => $signerId]);
        if ($remote === null) {
            return; // we have nothing of theirs to delete
        }
        $apId = $this->objectId($activity['object'] ?? null);
        $record = $this->resolveRemoteRecord($apId);
        if ($record === null) {
            return; // not something we ingested
        }
        if (!$this->isAuthoredBy($record, $remote)) {
            Yii::warning('Fediverse Delete: actor is not the author of the target ' . $apId, 'fediverse');
            return;
        }
        try {
            $record->delete();
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Delete failed for ' . $apId . ': ' . $e->getMessage(), 'fediverse');
            return;
        }
        FederationObject::deleteAll(['ap_id' => $apId, 'direction' => FederationObject::DIRECTION_REMOTE]);
    }

    /** Inbound Update{Note|Person|…} → update content or refresh remote actor. */
    private function handleUpdate(array $activity, string $signerId): void
    {
        $remote = RemoteActor::findOne(['ap_id' => $signerId]);
        if ($remote === null) {
            return; // unknown actor → nothing to update/refresh
        }
        $object = $activity['object'] ?? null;
        if (!is_array($object)) {
            return;
        }
        $objType = $object['type'] ?? '';
        if (in_array($objType, ['Person', 'Application', 'Service', 'Group', 'Organization'], true)) {
            $this->refreshRemoteActor($object, $remote);
            return;
        }
        if ($objType !== 'Note') {
            return;
        }
        $record = $this->resolveRemoteRecord((string) ($object['id'] ?? ''));
        if ($record === null) {
            return;
        }
        if (!$this->isAuthoredBy($record, $remote)) {
            Yii::warning('Fediverse Update: actor is not the author of the target', 'fediverse');
            return;
        }
        $record->message = FederatedHtml::toText($object['content'] ?? null);
        try {
            if (!$record->save()) {
                Yii::warning('Fediverse Update save failed: ' . implode(', ', $record->getErrorSummary(true)), 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Update error: ' . $e->getMessage(), 'fediverse');
        }
    }

    /** Inbound Update{Person} → refresh the remote actor's shadow user (name, bio, avatar, banner). */
    private function refreshRemoteActor(array $doc, RemoteActor $remote): void
    {
        // An actor may only update itself.
        if ((string) ($doc['id'] ?? '') !== $remote->ap_id) {
            Yii::warning('Fediverse Update{Person}: object id does not match the signing actor', 'fediverse');
            return;
        }

        if (isset($doc['preferredUsername'])) {
            $remote->preferred_username = (string) $doc['preferredUsername'];
        }
        if (!empty($doc['publicKey']['publicKeyPem'])) {
            $remote->public_key = (string) $doc['publicKey']['publicKeyPem'];
        }
        if (is_array($doc['icon'] ?? null) && !empty($doc['icon']['url'])) {
            $remote->icon_url = (string) $doc['icon']['url'];
        }
        $remote->last_refreshed_at = date('Y-m-d H:i:s');
        $remote->save();

        $user = User::findOne($remote->user_id);
        if ($user === null) {
            return;
        }

        $profile = $user->profile;
        $name = trim((string) ($doc['name'] ?? '')) ?: (string) ($doc['preferredUsername'] ?? '');
        if ($name !== '' && $profile !== null) {
            $profile->firstname = mb_substr($name, 0, 255);
            if (empty($profile->lastname)) {
                // HumHub requires a last name; shadow profiles use the domain (matches createShadowUser).
                $profile->lastname = mb_substr((string) ($remote->domain ?: parse_url((string) $remote->ap_id, PHP_URL_HOST)), 0, 255);
            }
            $summary = FederatedHtml::toText($doc['summary'] ?? null);
            if ($summary !== '') {
                $profile->about = $summary;
            }
            if (!$profile->save()) {
                Yii::warning('Fediverse Update{Person}: profile save failed: ' . implode(', ', $profile->getErrorSummary(true)), 'fediverse');
            }
        }

        (new RemoteActorResolver())->applyProfileImages($user, $doc);
    }

    /** Resolve a remote-origin AP object id to the ingested local record (Post or Comment), or null. */
    private function resolveRemoteRecord(string $apId)
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne(['ap_id' => $apId, 'direction' => FederationObject::DIRECTION_REMOTE]);
        if ($obj === null || $obj->model_class === null || $obj->model_id === null) {
            return null;
        }
        if (!class_exists($obj->model_class) || !method_exists($obj->model_class, 'findOne')) {
            return null;
        }
        return $obj->model_class::findOne($obj->model_id);
    }

    /** True if the verified remote actor is the author of the ingested record. */
    private function isAuthoredBy($record, RemoteActor $remote): bool
    {
        if ($record instanceof Post) {
            return (int) ($record->content->created_by ?? 0) === (int) $remote->user_id;
        }
        if ($record instanceof Comment) {
            return (int) $record->created_by === (int) $remote->user_id;
        }
        return false;
    }

    /** Inbound Like → like the referenced local content. */
    private function handleLike(array $activity, string $signerId, array $signerDoc): void
    {
        $apId = $this->objectId($activity['object'] ?? null);
        $record = (new LocalObjectResolver())->resolveLikeable($apId);
        if ($record === null) {
            Yii::warning('Fediverse Like: could not resolve likeable for ' . $apId, 'fediverse');
            return; // don't mint a shadow user for a like we can't attach
        }
        $remote = $this->persistActor($signerId, $signerDoc);
        $author = User::findOne($remote->user_id);
        if ($author !== null) {
            (new InboundInteractionService())->addLike($record, $author, (string) ($activity['id'] ?? ''));
        }
    }

    /** Remote accepted our outbound Follow → mark our pending follow(s) to it accepted. */
    private function handleAccept(array $activity, string $signerId): void
    {
        $remote = RemoteActor::findOne(['ap_id' => $signerId]);
        if ($remote === null) {
            return; // we never followed them → no pending follow to accept
        }
        // Accept.object may be an embedded Follow OR a bare string (its AP id) — Mastodon sends a string.
        $inner = $activity['object'] ?? null;
        if (is_array($inner) && ($inner['type'] ?? 'Follow') !== 'Follow') {
            return; // an embedded non-Follow object; ignore
        }
        $follows = FederationFollow::findAll([
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
            'state' => FederationFollow::STATE_PENDING,
        ]);
        foreach ($follows as $follow) {
            $follow->state = FederationFollow::STATE_ACCEPTED;
            $follow->save();
        }
    }

    /** Ingest a followed remote actor's top-level Note as a Post on the shadow profile. */
    private function ingestRemotePost(array $note, RemoteActor $remote): void
    {
        // Only ingest from actors a local user actually follows (we only receive such posts anyway).
        $followed = FederationFollow::find()
            ->where(['remote_actor_id' => $remote->id, 'direction' => FederationFollow::DIRECTION_OUTBOUND])
            ->exists();
        if (!$followed) {
            return;
        }
        $noteId = (string) ($note['id'] ?? '');
        if ($noteId === '' || \humhub\modules\fediverse\models\FederationObject::find()->where(['ap_id' => $noteId])->exists()) {
            return; // malformed or already ingested
        }
        $shadow = User::findOne($remote->user_id);
        if ($shadow === null) {
            return;
        }

        $parsed = FederatedHtml::extractTrailingHashtags(FederatedHtml::toText($note['content'] ?? null));
        $post = new \humhub\modules\post\models\Post($shadow, \humhub\modules\content\models\Content::VISIBILITY_PUBLIC, [
            'message' => $parsed['text'],
        ]);
        $post->created_by = $shadow->id;
        $post->autoFollow = false;
        $post->content->created_by = $shadow->id; // queue has no logged-in user; Content requires created_by
        $post->silentContentCreation = true;       // no per-post notification (Mastodon-like timeline behavior)
        try {
            if (!$post->save()) {
                Yii::warning('Fediverse ingest post failed: ' . implode(', ', $post->getErrorSummary(true)), 'fediverse');
                return;
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse ingest post error: ' . $e->getMessage(), 'fediverse');
            return;
        }

        $obj = new \humhub\modules\fediverse\models\FederationObject();
        $obj->ap_id = $noteId;
        $obj->direction = \humhub\modules\fediverse\models\FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Note';
        $obj->model_class = \humhub\modules\post\models\Post::class;
        $obj->model_id = $post->id;
        $obj->save();

        $this->attachTopics($post, $parsed['tags']);
        $this->attachRemoteImages($post, $note);
    }

    /** Attach the parsed trailing hashtags as HumHub topics to the ingested post. */
    private function attachTopics(Post $post, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }
        $content = $post->content;
        $models = [];
        foreach ($tagNames as $name) {
            $name = trim(mb_substr((string) $name, 0, 100));
            if ($name === '') {
                continue;
            }
            // Find-or-create per name within this container (dedupe; no permission gate).
            $topic = Topic::find()->where(['name' => $name, 'contentcontainer_id' => $content->contentcontainer_id])->one();
            if ($topic === null) {
                $topic = new Topic(['name' => $name, 'contentcontainer_id' => $content->contentcontainer_id]);
                if (!$topic->save()) {
                    continue;
                }
            }
            $models[] = $topic;
        }
        if ($models !== []) {
            (new ContentTagService($post->content))->addTags($models);
        }
    }

    private const MAX_ATTACHMENTS = 8;

    /** Download a remote Note's image attachments and attach them to the ingested post. */
    private function attachRemoteImages(Post $post, array $note): void
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
                $file->created_by = (int) $post->content->created_by; // shadow user; queue has no logged-in user
                if (!$file->save()) {
                    Yii::warning('Fediverse attachment File save failed: ' . implode(', ', $file->getErrorSummary(true)), 'fediverse');
                    continue;
                }
                $file->setStoredFileContent($media['content']);
                $post->fileManager->attach($file);
                $count++;
            } catch (\Throwable $e) {
                Yii::warning('Fediverse attachment error for ' . $url . ': ' . $e->getMessage(), 'fediverse');
            }
        }
    }

    /** Extract an object id from an AP value that may be a string or an embedded object. */
    private function objectId($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return (string) $value;
    }

    private function resolveFollowTarget($object): ?User
    {
        $url = is_array($object) ? ($object['id'] ?? '') : (string) $object;
        if ($url === '') {
            return null;
        }
        $actor = LocalActor::findOne(['ap_id' => $url]);
        if ($actor === null || $actor->user_id === null) {
            return null;
        }
        return User::findOne($actor->user_id);
    }

    private function sendAccept(User $localUser, RemoteActor $remote, array $followActivity): void
    {
        $signer = LocalActor::findOne(['user_id' => $localUser->id]);
        if ($signer === null) {
            return;
        }

        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $signer->ap_id . '/accepts/' . bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $signer->ap_id,
            'object' => [
                'id' => $followActivity['id'] ?? null,
                'type' => 'Follow',
                'actor' => $remote->ap_id,
                'object' => $signer->ap_id,
            ],
        ];

        // Deliver the Accept to the follower's PERSONAL inbox — a directed
        // activity. The shared inbox is for broadcast and silently drops an
        // unaddressed directed Accept (it returns 202 but never routes it).
        $inbox = $remote->inbox_url ?: $remote->shared_inbox_url;
        if ($inbox === null || $inbox === '') {
            return;
        }
        // Deliver via a retryable per-inbox job (decoupled from inbox processing).
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $localUser->id,
            'inboxUrl' => $inbox,
            'activityJson' => json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
}
