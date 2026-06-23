<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\comment\models\Comment;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\post\models\Post;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\fediverse\services\InboundInteractionService;
use humhub\modules\fediverse\services\LocalObjectResolver;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\fediverse\services\SignatureService;
use humhub\modules\queue\ActiveJob;
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

        // Authenticate BEFORE any persistence or trust.
        $remote = $this->authenticate($actorUrl);
        if ($remote === null) {
            Yii::warning('Fediverse inbox: authentication failed for ' . $actorUrl, 'fediverse');
            return;
        }

        switch ($activity['type']) {
            case 'Follow':
                $this->handleFollow($activity, $remote);
                break;
            case 'Undo':
                $this->handleUndo($activity, $remote);
                break;
            case 'Create':
                $this->handleCreate($activity, $remote);
                break;
            case 'Like':
                $this->handleLike($activity, $remote);
                break;
            case 'Accept':
                $this->handleAccept($activity, $remote);
                break;
            case 'Delete':
                $this->handleDelete($activity, $remote);
                break;
            case 'Update':
                $this->handleUpdate($activity, $remote);
                break;
            default:
                Yii::info('Fediverse inbox: ignoring unsupported type ' . $activity['type'], 'fediverse');
        }
    }

    /**
     * Verify the HTTP Signature and, on success, resolve+persist the actor.
     * Returns null (caller drops the activity) on any verification failure.
     */
    private function authenticate(string $actorUrl): ?RemoteActor
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

        return $resolver->resolveFromDocument($signerId, $signerDoc);
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

    private function handleFollow(array $activity, RemoteActor $remote): void
    {
        $localUser = $this->resolveFollowTarget($activity['object'] ?? null);
        if ($localUser === null) {
            Yii::warning('Fediverse Follow: unknown target object', 'fediverse');
            return;
        }

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

    private function handleUndo(array $activity, RemoteActor $remote): void
    {
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
    private function handleCreate(array $activity, RemoteActor $remote): void
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || ($object['type'] ?? '') !== 'Note') {
            return;
        }
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (!is_string($inReplyTo) || $inReplyTo === '') {
            $this->ingestRemotePost($object, $remote);
            return;
        }
        $content = (new LocalObjectResolver())->resolveContent($inReplyTo);
        if ($content === null) {
            return; // a reply to something we don't host
        }
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
    private function handleDelete(array $activity, RemoteActor $remote): void
    {
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

    /** Inbound Update{Note} → update the ingested remote post/comment content (author only). */
    private function handleUpdate(array $activity, RemoteActor $remote): void
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || ($object['type'] ?? '') !== 'Note') {
            return; // Update{Person} and other types are out of scope here
        }
        $record = $this->resolveRemoteRecord((string) ($object['id'] ?? ''));
        if ($record === null) {
            return;
        }
        if (!$this->isAuthoredBy($record, $remote)) {
            Yii::warning('Fediverse Update: actor is not the author of the target', 'fediverse');
            return;
        }
        $record->message = trim(strip_tags((string) ($object['content'] ?? '')));
        try {
            if (!$record->save()) {
                Yii::warning('Fediverse Update save failed: ' . implode(', ', $record->getErrorSummary(true)), 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Update error: ' . $e->getMessage(), 'fediverse');
        }
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
    private function handleLike(array $activity, RemoteActor $remote): void
    {
        $apId = $this->objectId($activity['object'] ?? null);
        $record = (new LocalObjectResolver())->resolveLikeable($apId);
        $author = User::findOne($remote->user_id);
        if ($record !== null && $author !== null) {
            (new InboundInteractionService())->addLike($record, $author, (string) ($activity['id'] ?? ''));
        } else {
            Yii::warning('Fediverse Like: could not resolve likeable/author for ' . $apId, 'fediverse');
        }
    }

    /** Remote accepted our outbound Follow → mark our pending follow(s) to it accepted. */
    private function handleAccept(array $activity, RemoteActor $remote): void
    {
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

        $post = new \humhub\modules\post\models\Post($shadow, \humhub\modules\content\models\Content::VISIBILITY_PUBLIC, [
            'message' => trim(strip_tags((string) ($note['content'] ?? ''))),
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
        try {
            if (!(new ActivityDelivery())->deliver($signer, $inbox, $accept)) {
                Yii::warning('Fediverse Accept delivery returned non-2xx for ' . $inbox, 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Accept delivery failed: ' . $e->getMessage(), 'fediverse');
        }
    }
}
