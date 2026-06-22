<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\ActivityDelivery;
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
            return null;
        }
        if (!hash_equals(SignatureService::digest($this->rawBody), (string) ($this->headers['digest'] ?? ''))) {
            return null;
        }

        // Reject stale/future-dated requests (basic replay window).
        if (!$this->isFreshDate((string) ($this->headers['date'] ?? ''))) {
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
        $signingString = $svc->buildSigningString('POST', $this->requestPath, [
            'host' => (string) ($this->headers['host'] ?? ''),
            'date' => (string) ($this->headers['date'] ?? ''),
            'digest' => (string) ($this->headers['digest'] ?? ''),
            'content-type' => (string) ($this->headers['content-type'] ?? ''),
        ], $headerList);
        if (!$svc->verifyString($signingString, $parsed['signature'], $pem)) {
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
        if (!is_array($inner) || ($inner['type'] ?? '') !== 'Follow') {
            return; // only Undo{Follow} in this phase
        }
        $localUser = $this->resolveFollowTarget($inner['object'] ?? null);
        if ($localUser === null) {
            return;
        }
        FederationFollow::deleteAll([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
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

        $inbox = $remote->shared_inbox_url ?: $remote->inbox_url;
        try {
            (new ActivityDelivery())->deliver($signer, $inbox, $accept);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Accept delivery failed: ' . $e->getMessage(), 'fediverse');
        }
    }
}
