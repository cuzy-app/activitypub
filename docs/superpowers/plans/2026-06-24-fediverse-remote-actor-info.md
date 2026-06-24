# Fediverse — remote-account info on shadow profiles (link + real counts + lazy refresh)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Problem:** A federated shadow user's HumHub profile shows the *local* social graph (Friends/Followers/Following/Spaces) — e.g. "1 Followers / 0 Friends" — which is misleading for a remote account. We can't mirror the real graph (no ghost user per follower).

**Solution:**
1. **Profile-menu link** to the original remote profile (new tab), for remote shadow users.
2. **Real Fediverse counts**: replace the misleading header counter set on remote profiles with the account's real Followers/Following (fetched from its home server). Hide the set if the numbers are unavailable (never show false 0/1).
3. **Lazy refresh**: counts/profile are re-fetched by a background job when a remote profile is viewed and its data is stale (`last_refreshed_at` older than 24h) **or** counts are still `null` (so existing actors backfill on first view). No cron, only viewed accounts.

**Detection:** a `RemoteActor` row exists for the profile user's id.

---

## Task 1: schema — counts + profile url on remote actor

**Create** `migrations/m260624_130000_remote_actor_counts.php`:
```php
<?php

use humhub\components\Migration;

class m260624_130000_remote_actor_counts extends Migration
{
    public function safeUp()
    {
        $this->addColumn('fediverse_remote_actor', 'profile_url', 'varchar(255) DEFAULT NULL');
        $this->addColumn('fediverse_remote_actor', 'followers_count', 'integer DEFAULT NULL');
        $this->addColumn('fediverse_remote_actor', 'following_count', 'integer DEFAULT NULL');
    }

    public function safeDown()
    {
        $this->dropColumn('fediverse_remote_actor', 'profile_url');
        $this->dropColumn('fediverse_remote_actor', 'followers_count');
        $this->dropColumn('fediverse_remote_actor', 'following_count');
    }
}
```
**Modify** `models/RemoteActor.php` — add `@property` lines: `string|null $profile_url`, `int|null $followers_count`, `int|null $following_count`. Run the migration.

---

## Task 2: resolver — capture url + refresh() + count fetch

**Modify** `services/RemoteActorResolver.php`.

- In `resolveFromDocument()`, right before `$remote->last_refreshed_at = ...`, add:
```php
        $remote->profile_url = self::extractUrl($doc['url'] ?? null);
```
- Add a public `refresh()` and two private helpers:
```php
    /** Best-effort re-fetch of the actor: profile fields, images, and follower/following counts. */
    public function refresh(RemoteActor $remote): void
    {
        try {
            $doc = $this->fetchActorDocument($remote->ap_id);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse refresh failed for ' . $remote->ap_id . ': ' . $e->getMessage(), 'fediverse');
            // Bump the timestamp so a dead/unreachable actor is not re-fetched on every view.
            $remote->last_refreshed_at = date('Y-m-d H:i:s');
            $remote->save(false);
            return;
        }
        if ((string) ($doc['id'] ?? '') !== $remote->ap_id) {
            return; // actor id mismatch
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
        $this->applyProfileImages($user, $doc);
        $profile = $user->profile;
        if ($profile !== null) {
            $name = trim((string) ($doc['name'] ?? '')) ?: (string) ($remote->preferred_username ?? '');
            if ($name !== '') {
                $profile->fediverse_displayname = mb_substr($name, 0, 255);
            }
            $summary = trim(strip_tags((string) ($doc['summary'] ?? '')));
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
            $response = (new FederationHttpClient())->createRequest()
                ->setMethod('GET')
                ->setUrl($url)
                ->addHeaders(['Accept' => 'application/activity+json'])
                ->send();
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
```
(`FederationHttpClient`, `UrlSafety` are same-namespace; `applyProfileImages` is the existing public method.)

---

## Task 3: the refresh job

**Create** `jobs/RefreshRemoteActor.php`:
```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\queue\ActiveJob;

/** Best-effort background refresh of a remote actor's cached data (profile + follower/following counts). */
class RefreshRemoteActor extends ActiveJob
{
    public int $remoteActorId = 0;

    public function run()
    {
        $remote = RemoteActor::findOne($this->remoteActorId);
        if ($remote !== null) {
            (new RemoteActorResolver())->refresh($remote);
        }
    }
}
```

---

## Task 4: widgets + lazy trigger

**Modify** `Events.php` — imports: add `use humhub\modules\fediverse\jobs\RefreshRemoteActor;` and `use humhub\modules\ui\widgets\CounterSetItem;` (MenuLink, RemoteActor, User already imported). Add three methods:
```php
    /** Profile menu: add a link to the original remote profile (federated shadow users only) + lazy-refresh. */
    public static function onProfileMenuInit($event): void
    {
        $user = $event->sender->user ?? null;
        if (!$user instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $user->id]);
        if ($remote === null) {
            return;
        }
        self::lazyRefreshRemoteActor($remote);

        $event->sender->addEntry(new MenuLink([
            'label' => Yii::t('FediverseModule.base', 'Fediverse profile'),
            'id' => 'profile-fediverse',
            'icon' => 'globe',
            'url' => $remote->profile_url ?: $remote->ap_id,
            'htmlOptions' => ['target' => '_blank', 'rel' => 'noopener'],
            'sortOrder' => 400,
            'isActive' => false,
        ]));
    }

    /** Profile header: replace the misleading local-graph counters with the real remote counts (or hide them). */
    public static function onProfileHeaderCounterSet($event): void
    {
        $widget = $event->sender;
        $user = $widget->user ?? null;
        if (!$user instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $user->id]);
        if ($remote === null) {
            return; // local user → leave native counters untouched
        }

        $counters = [];
        if ($remote->followers_count !== null) {
            $counters[] = new CounterSetItem([
                'label' => Yii::t('FediverseModule.base', 'Followers'),
                'value' => (int) $remote->followers_count,
                'url' => null,
            ]);
        }
        if ($remote->following_count !== null) {
            $counters[] = new CounterSetItem([
                'label' => Yii::t('FediverseModule.base', 'Following'),
                'value' => (int) $remote->following_count,
                'url' => null,
            ]);
        }

        if (empty($counters)) {
            $event->isValid = false; // no real numbers yet → hide rather than mislead
            return;
        }
        $widget->counters = $counters;
    }

    /** Enqueue a background refresh of a remote actor if its cached data is stale or counts are missing. */
    private static function lazyRefreshRemoteActor(RemoteActor $remote): void
    {
        $ttl = 24 * 3600;
        $last = $remote->last_refreshed_at ? strtotime((string) $remote->last_refreshed_at) : 0;
        $stale = !$last || (time() - $last) >= $ttl;
        $missingCounts = $remote->followers_count === null && $remote->following_count === null;
        if (!$stale && !$missingCounts) {
            return;
        }
        // De-dupe concurrent enqueues for the same actor within a short window.
        $cache = Yii::$app->cache;
        $key = 'fediverse.refresh.' . $remote->id;
        if ($cache->get($key)) {
            return;
        }
        $cache->set($key, 1, 600);
        Yii::$app->queue->push(new RefreshRemoteActor(['remoteActorId' => (int) $remote->id]));
    }
```

**Modify** `config.php` — add to `events`:
```php
        ['class' => 'humhub\modules\user\widgets\ProfileMenu', 'event' => 'init', 'callback' => ['humhub\modules\fediverse\Events', 'onProfileMenuInit']],
        ['class' => 'humhub\modules\user\widgets\ProfileHeaderCounterSet', 'event' => 'beforeRun', 'callback' => ['humhub\modules\fediverse\Events', 'onProfileHeaderCounterSet']],
```

---

## Task 5: CHANGELOG

Add under `0.1.0 (Unreleased)` (before the unit-tests bullet):
```markdown
- Enh: A federated (remote) user's profile now shows a "Fediverse profile" link
  in the profile menu (to their original account) and replaces the misleading
  local follower/friend counters with the account's real Fediverse
  follower/following counts. The counts (and the profile) are refreshed lazily
  in the background when the profile is viewed and the cached data is older than
  a day, so they stay reasonably current without any periodic job.
```

---

## Deploy + verification (controller + human)

1. Run migration; `php -l` all; flush cache.
2. (controller) Force a refresh of one actor (e.g. via the lazy path or a one-off) and confirm `followers_count`/`following_count`/`profile_url` populate from the DB.
3. **Admin:** open a remote profile (e.g. `@cmburns@mastodon.social` or `@eucommission@…`). First view may show no counters (then a background refresh runs); reload → real **Followers/Following** shown, and the **Profile menu** has a **Fediverse profile ↗** link to the original. Local users' profiles are unchanged.

## Self-review risks
- **Staleness:** counts are ≤24h old for viewed profiles (acceptable; Mastodon itself caches remote counts). Dead actors bump the timestamp on failure to avoid re-hammering.
- **Server restrictions:** some servers hide collection `totalItems` → counts stay null → counter set hidden (no false numbers).
- **Thundering herd:** a 10-min cache lock de-dupes enqueues; the TTL gate prevents repeat refreshes.
- **Scope:** the counter hook only acts when a `RemoteActor` exists for the user → local profiles untouched. The link uses `profile_url` (falls back to `ap_id`).
- **No core change.**
