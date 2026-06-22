<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\user\models\User;
use RuntimeException;
use Yii;
use yii\db\IntegrityException;

/**
 * Resolves a remote ActivityPub actor URL into a cached RemoteActor row backed
 * by a local "federated" shadow User (login disabled, hidden from directory).
 * Persistence happens only via resolveFromDocument(), which callers invoke
 * AFTER signature verification.
 */
class RemoteActorResolver
{
    /** Cached RemoteActor for an actor URL, or fetch + persist it. */
    public function resolve(string $actorUrl): RemoteActor
    {
        $existing = RemoteActor::findOne(['ap_id' => $actorUrl]);
        if ($existing !== null) {
            return $existing;
        }
        return $this->resolveFromDocument($actorUrl, $this->fetchActorDocument($actorUrl));
    }

    /** Persist (or return cached) a RemoteActor from an already-fetched, verified actor document. */
    public function resolveFromDocument(string $actorUrl, array $doc): RemoteActor
    {
        $existing = RemoteActor::findOne(['ap_id' => $actorUrl]);
        if ($existing !== null) {
            return $existing;
        }

        $user = $this->createShadowUser($doc, $actorUrl);

        $remote = new RemoteActor();
        $remote->user_id = $user->id;
        $remote->ap_id = $actorUrl;
        $remote->type = (string) ($doc['type'] ?? 'Person');
        $remote->preferred_username = isset($doc['preferredUsername']) ? (string) $doc['preferredUsername'] : null;
        $remote->domain = (string) parse_url($actorUrl, PHP_URL_HOST);
        $remote->handle = ($remote->preferred_username ?? 'user') . '@' . $remote->domain;
        $remote->inbox_url = (string) ($doc['inbox'] ?? '');
        $remote->shared_inbox_url = $doc['endpoints']['sharedInbox'] ?? null;
        $remote->outbox_url = $doc['outbox'] ?? null;
        $remote->public_key = $doc['publicKey']['publicKeyPem'] ?? null;
        $remote->icon_url = is_array($doc['icon'] ?? null) ? ($doc['icon']['url'] ?? null) : null;
        $remote->last_refreshed_at = date('Y-m-d H:i:s');

        if ($remote->inbox_url === '' || $remote->public_key === null) {
            throw new RuntimeException('Remote actor missing inbox or publicKey: ' . $actorUrl);
        }
        if (!$remote->save()) {
            throw new RuntimeException('Could not save RemoteActor: ' . implode(', ', $remote->getErrorSummary(true)));
        }
        return $remote;
    }

    /** Fetch and JSON-decode an actor document (SSRF-guarded, no redirects, no persistence). */
    public function fetchActorDocument(string $actorUrl): array
    {
        if (!UrlSafety::isSafePublicUrl($actorUrl)) {
            throw new RuntimeException('Refusing to fetch unsafe URL: ' . $actorUrl);
        }

        $client = new FederationHttpClient();
        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl($actorUrl)
            ->addHeaders(['Accept' => 'application/activity+json'])
            ->send();

        if (!$response->getIsOk()) {
            throw new RuntimeException('Actor fetch failed (' . $response->getStatusCode() . '): ' . $actorUrl);
        }

        $data = json_decode($response->getContent(), true);
        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException('Invalid actor document: ' . $actorUrl);
        }
        return $data;
    }

    private function createShadowUser(array $doc, string $actorUrl): User
    {
        $domain = (string) parse_url($actorUrl, PHP_URL_HOST);
        $preferred = isset($doc['preferredUsername']) ? (string) $doc['preferredUsername'] : 'user';
        $name = trim((string) ($doc['name'] ?? '')) ?: $preferred;

        $user = new User();
        $user->username = $this->uniqueUsername($preferred . '@' . $domain);
        $user->email = 'remote-' . substr(sha1($actorUrl), 0, 20) . '@federated.invalid';
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_HIDDEN; // excluded from People directory

        try {
            if (!$user->save()) {
                throw new RuntimeException('Could not create shadow user: ' . implode(', ', $user->getErrorSummary(true)));
            }
        } catch (IntegrityException $e) {
            // Concurrent creation for the same actor: reuse the existing shadow user if present.
            $existingRemote = RemoteActor::findOne(['ap_id' => $actorUrl]);
            if ($existingRemote !== null && $existingRemote->user_id !== null) {
                $reused = User::findOne($existingRemote->user_id);
                if ($reused !== null) {
                    return $reused;
                }
            }
            throw $e;
        }

        $profile = $user->profile;
        $profile->firstname = mb_substr($name, 0, 255);
        $profile->lastname = '';
        if (!$profile->save()) {
            Yii::warning('Federated user profile save failed: ' . implode(', ', $profile->getErrorSummary(true)), 'fediverse');
        }

        $defaultGroup = Yii::$app->getModule('user')->getDefaultGroup();
        if ($defaultGroup !== null) {
            $defaultGroup->addUser($user);
        }

        return $user;
    }

    private function uniqueUsername(string $base): string
    {
        $candidate = mb_substr($base, 0, 97);
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $candidate = mb_substr($base, 0, 90) . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
