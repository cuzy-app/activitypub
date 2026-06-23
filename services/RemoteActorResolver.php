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
        $user->user_source = FederatedUserSource::ID; // governs: email optional, no self-delete, managed attrs
        $user->username = $this->uniqueUsername('@' . $preferred . '@' . $domain);
        $user->email = null; // FederatedUserSource declares email optional (core PR #8239)
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_REGISTERED_ONLY; // followable + profile-viewable, off public discovery

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
        $profile->fediverse_displayname = mb_substr($name, 0, 255);
        $profile->fediverse_handle = mb_substr('@' . $preferred . '@' . $domain, 0, 255);
        // firstname/lastname intentionally left empty for federated users — their
        // identity lives in the Fediverse fields. They are globally `required` but
        // nullable columns, so save without validation.
        if (!$profile->save(false)) {
            Yii::warning('Federated user profile save failed', 'fediverse');
        }

        $defaultGroup = Yii::$app->getModule('user')->getDefaultGroup();
        if ($defaultGroup !== null) {
            $defaultGroup->addUser($user);
        }

        $this->applyProfileImages($user, $doc);

        return $user;
    }

    /** Download the actor doc's avatar (icon) + banner (image) and set them on the user. Best-effort. */
    public function applyProfileImages(User $user, array $doc): void
    {
        $fetcher = new RemoteMediaFetcher();
        $this->applyOneImage($user->image, is_array($doc['icon'] ?? null) ? ($doc['icon']['url'] ?? null) : null, $fetcher);
        $this->applyOneImage($user->bannerImage, is_array($doc['image'] ?? null) ? ($doc['image']['url'] ?? null) : null, $fetcher);
    }

    /** @param mixed $assetImage a humhub AssetImage ($user->image / $user->bannerImage) */
    private function applyOneImage($assetImage, ?string $url, RemoteMediaFetcher $fetcher): void
    {
        $media = $fetcher->fetchImage($url);
        if ($media === null) {
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fedimg');
        if ($tmp === false) {
            return;
        }
        try {
            file_put_contents($tmp, $media['content']);
            $assetImage->setByFile($tmp);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse profile image set failed: ' . $e->getMessage(), 'fediverse');
        } finally {
            @unlink($tmp);
        }
    }

    private function uniqueUsername(string $base): string
    {
        $max = (int) (Yii::$app->getModule('user')->maximumUsernameLength ?: 50);
        $candidate = mb_substr($base, 0, $max);
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $suffix = '_' . $i;
            $candidate = mb_substr($base, 0, $max - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        return $candidate;
    }

    /** Build the WebFinger query URL for a handle like "@user@domain" / "user@domain"; '' if malformed. */
    public static function webfingerUrl(string $handle): string
    {
        $handle = ltrim(trim($handle), '@');
        if (!preg_match('/^([^@\s]+)@([^@\s]+\.[^@\s]+)$/', $handle, $m)) {
            return '';
        }
        return 'https://' . $m[2] . '/.well-known/webfinger?resource=acct:' . $m[1] . '@' . $m[2];
    }

    /** Resolve a handle (@user@domain) to a RemoteActor via WebFinger, creating the shadow user. */
    public function resolveByHandle(string $handle): RemoteActor
    {
        $wf = self::webfingerUrl($handle);
        if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
            throw new RuntimeException('Invalid or unsafe handle: ' . $handle);
        }

        $client = new FederationHttpClient();
        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl($wf)
            ->addHeaders(['Accept' => 'application/jrd+json, application/json'])
            ->send();
        if (!$response->getIsOk()) {
            throw new RuntimeException('WebFinger lookup failed (' . $response->getStatusCode() . '): ' . $handle);
        }

        $jrd = json_decode($response->getContent(), true);
        $actorUrl = '';
        foreach ($jrd['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'self'
                && in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json'], true)) {
                $actorUrl = (string) ($link['href'] ?? '');
                break;
            }
        }
        if ($actorUrl === '') {
            throw new RuntimeException('No ActivityPub actor link in WebFinger for ' . $handle);
        }
        return $this->resolve($actorUrl);
    }
}
