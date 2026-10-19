<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\activitypub\models\RemoteActor;
use humhub\modules\user\models\Group;
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
        $remote->banner_url = is_array($doc['image'] ?? null) ? ($doc['image']['url'] ?? null) : null;
        $remote->profile_url = self::extractUrl($doc['url'] ?? null);
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

        $response = $this->signedGet($actorUrl);

        if (!$response->getIsOk()) {
            throw new RuntimeException('Actor fetch failed (' . $response->getStatusCode() . '): ' . $actorUrl);
        }

        $data = json_decode($response->getContent(), true);
        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException('Invalid actor document: ' . $actorUrl);
        }
        return $data;
    }

    /**
     * GET signed by the instance actor (draft-cavage HTTP Signature over
     * (request-target) host date). Instances in "authorized fetch" / secure mode
     * reject unsigned actor/object GETs with 401; signing is harmless to instances
     * that do not require it.
     *
     * @return \yii\httpclient\Response
     */
    private function signedGet(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';

        $signer = (new KeyManager())->getOrCreateInstanceActor();
        $signatures = new SignatureService();
        $headerList = ['(request-target)', 'host', 'date'];
        $signingString = $signatures->buildSigningString('GET', $path, [
            'host' => $host,
            'date' => $date,
        ], $headerList);
        $signatureHeader = sprintf(
            'keyId="%s#main-key",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $signer->ap_id,
            implode(' ', $headerList),
            $signatures->signString($signingString, (string) $signer->private_key)
        );

        return (new FederationHttpClient())->createRequest()
            ->setMethod('GET')
            ->setUrl($url)
            ->addHeaders([
                'Host' => $host,
                'Date' => $date,
                'Accept' => 'application/activity+json',
                'Signature' => $signatureHeader,
            ])
            ->send();
    }

    /**
     * GET a WebFinger document, following up to 3 redirects manually (the federation
     * client disables curl auto-redirects for SSRF safety). Each redirect target is
     * re-validated as a safe public HTTPS URL. WebFinger delegation via redirect is
     * common — e.g. an apex domain pointing at the actual instance.
     *
     * @return \yii\httpclient\Response
     */
    private function webfingerGet(string $url)
    {
        for ($i = 0; $i < 4; $i++) {
            if (!UrlSafety::isSafePublicUrl($url)) {
                throw new RuntimeException('Refusing to fetch unsafe URL: ' . $url);
            }
            $response = (new FederationHttpClient())->createRequest()
                ->setMethod('GET')
                ->setUrl($url)
                ->addHeaders(['Accept' => 'application/jrd+json, application/json'])
                ->send();
            $status = (int) $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                return $response;
            }
            $location = $response->getHeaders()->get('location');
            if (!is_string($location) || $location === '') {
                return $response; // redirect without a target — let the caller handle the non-2xx
            }
            $url = self::absoluteUrl($url, $location);
        }
        throw new RuntimeException('Too many WebFinger redirects');
    }

    /** Resolve a (possibly relative) redirect Location against the request URL. */
    private static function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $host = $b['host'] ?? '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }
        $path = $b['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $scheme . '://' . $host . $dir . $location;
    }

    /** Best-effort re-fetch of the actor: profile fields, images, and follower/following counts. */
    public function refresh(RemoteActor $remote): void
    {
        try {
            $doc = $this->fetchActorDocument($remote->ap_id);
        } catch (\Throwable $e) {
            Yii::warning('ActivityPub refresh failed for ' . $remote->ap_id . ': ' . $e->getMessage(), 'activitypub');
            // Bump the timestamp so a dead/unreachable actor is not re-fetched on every view.
            $remote->last_refreshed_at = date('Y-m-d H:i:s');
            $remote->save(false);
            return;
        }
        if ((string) ($doc['id'] ?? '') !== $remote->ap_id) {
            return; // actor id mismatch
        }

        $previousIconUrl = $remote->icon_url;
        $previousBannerUrl = $remote->banner_url;

        if (isset($doc['preferredUsername'])) {
            $remote->preferred_username = (string) $doc['preferredUsername'];
        }
        if (!empty($doc['publicKey']['publicKeyPem'])) {
            $remote->public_key = (string) $doc['publicKey']['publicKeyPem'];
        }
        if (is_array($doc['icon'] ?? null) && !empty($doc['icon']['url'])) {
            $remote->icon_url = (string) $doc['icon']['url'];
        }
        if (is_array($doc['image'] ?? null) && !empty($doc['image']['url'])) {
            $remote->banner_url = (string) $doc['image']['url'];
        }
        $url = self::extractUrl($doc['url'] ?? null);
        if ($url !== null) {
            $remote->profile_url = $url;
        }
        $remote->followers_count = $this->fetchCollectionTotal($doc['followers'] ?? null);
        $remote->following_count = $this->fetchCollectionTotal($doc['following'] ?? null);
        $remote->last_refreshed_at = date('Y-m-d H:i:s');
        $remote->save(false);

        $user = User::findOne($remote->user_id);
        if ($user === null) {
            return;
        }
        $this->applyProfileImages($user, $doc, $previousIconUrl, $previousBannerUrl);
        $profile = $user->profile;
        if ($profile !== null) {
            $name = trim((string) ($doc['name'] ?? '')) ?: (string) ($remote->preferred_username ?? '');
            $name = FederatedHtml::stripCustomEmoji($name, $doc['tag'] ?? []);
            if ($name !== '') {
                $profile->activitypub_displayname = mb_substr($name, 0, 255);
            }
            $summary = FederatedHtml::toText($doc['summary'] ?? null);
            if ($summary !== '') {
                $profile->about = $summary;
            }
            $profile->save(false);
        }
    }

    /** GET an AP collection and return its `totalItems`, or null if unavailable/blocked. */
    private function fetchCollectionTotal($url): ?int
    {
        if (!is_string($url) || $url === '' || !UrlSafety::isSafePublicUrl($url)) {
            return null;
        }
        try {
            $response = $this->signedGet($url);
            if (!$response->getIsOk()) {
                return null;
            }
            $data = json_decode($response->getContent(), true);
            return isset($data['totalItems']) && is_numeric($data['totalItems']) ? (int) $data['totalItems'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Extract a usable human profile URL from an AP `url` value (string or array of links). */
    private static function extractUrl($url): ?string
    {
        if (is_string($url) && $url !== '') {
            return $url;
        }
        if (is_array($url)) {
            foreach ($url as $u) {
                if (is_string($u) && $u !== '') {
                    return $u;
                }
                if (is_array($u) && !empty($u['href'])) {
                    return (string) $u['href'];
                }
            }
        }
        return null;
    }

    private function createShadowUser(array $doc, string $actorUrl): User
    {
        $domain = (string) parse_url($actorUrl, PHP_URL_HOST);
        $preferred = isset($doc['preferredUsername']) ? (string) $doc['preferredUsername'] : 'user';
        $name = trim((string) ($doc['name'] ?? '')) ?: $preferred;
        $name = FederatedHtml::stripCustomEmoji($name, $doc['tag'] ?? []) ?: $preferred; // names can't render emoji images

        $user = new User();
        $user->user_source = FederatedUserSource::ID; // governs: email optional, no self-delete, managed attrs
        $user->username = $this->uniqueUsername('@' . $preferred . '@' . $domain);
        $user->email = null; // FederatedUserSource declares email optional (core PR #8239)
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_REGISTERED_ONLY; // followable + profile-viewable, off public discovery
        // Federated users must NOT land in the local default group; the core assigns it in
        // afterSave() when the user has no group yet, so suppress it before saving and put
        // them only in the configured remote-users group below.
        $user->allowAssignDefaultGroup(false);

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
        $profile->activitypub_displayname = mb_substr($name, 0, 255);
        $profile->activitypub_handle = mb_substr('@' . $preferred . '@' . $domain, 0, 255);
        // firstname/lastname intentionally left empty for federated users — their
        // identity lives in the Fediverse fields. They are globally `required` but
        // nullable columns, so save without validation.
        if (!$profile->save(false)) {
            Yii::warning('Federated user profile save failed', 'activitypub');
        }

        // Categorise federated users in the admin-configured group (for People filtering).
        $groupId = (int) Yii::$app->getModule('activitypub')->settings->get('remoteUserGroupId', 0);
        if ($groupId > 0) {
            $group = Group::findOne($groupId);
            if ($group !== null) {
                $group->addUser($user);
            }
        }

        $this->applyProfileImages($user, $doc);

        return $user;
    }

    /**
     * Download the actor doc's avatar (icon) + banner (image) and set them on the user. Best-effort.
     * An image is skipped when its URL is unchanged since the last sync and it was applied before —
     * replacing an unchanged image would only cause needless downloads and republishing churn.
     */
    public function applyProfileImages(User $user, array $doc, ?string $previousIconUrl = null, ?string $previousBannerUrl = null): void
    {
        $fetcher = new RemoteMediaFetcher();

        $iconUrl = is_array($doc['icon'] ?? null) ? ($doc['icon']['url'] ?? null) : null;
        if ($iconUrl !== $previousIconUrl || !$user->image->exists()) {
            $this->applyOneImage($user->image, $iconUrl, $fetcher);
        }

        $bannerUrl = is_array($doc['image'] ?? null) ? ($doc['image']['url'] ?? null) : null;
        if ($bannerUrl !== $previousBannerUrl || !$user->bannerImage->exists()) {
            $this->applyOneImage($user->bannerImage, $bannerUrl, $fetcher);
        }
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
            Yii::warning('ActivityPub profile image set failed: ' . $e->getMessage(), 'activitypub');
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

    /**
     * WebFinger a query URL and return the self-link ActivityPub actor URL.
     * Follows redirects with SSRF re-validation (via webfingerGet). Throws on failure.
     */
    public function webfingerActorUrl(string $webfingerUrl): string
    {
        $response = $this->webfingerGet($webfingerUrl);
        if (!$response->getIsOk()) {
            throw new RuntimeException('WebFinger lookup failed (' . $response->getStatusCode() . '): ' . $webfingerUrl);
        }
        $jrd = json_decode($response->getContent(), true);
        foreach ($jrd['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'self'
                && in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json'], true)) {
                $href = (string) ($link['href'] ?? '');
                if ($href !== '') {
                    return $href;
                }
            }
        }
        throw new RuntimeException('No ActivityPub actor link in WebFinger for ' . $webfingerUrl);
    }

    /** Resolve a handle (@user@domain) to a RemoteActor via WebFinger, creating the shadow user. */
    public function resolveByHandle(string $handle): RemoteActor
    {
        $wf = self::webfingerUrl($handle);
        if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
            throw new RuntimeException('Invalid or unsafe handle: ' . $handle);
        }

        $actorUrl = $this->webfingerActorUrl($wf);
        return $this->resolve($actorUrl);
    }
}
