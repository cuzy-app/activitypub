# Fediverse — remote-post engagement (suppress native like/comment, show real counts + origin link)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** On an *ingested remote* post (authored by a federated shadow user), don't show HumHub's native like/comment UI (they'd only reflect local interaction and would double up). Instead show a single read-only element: the post's **real** Fediverse like/boost counts + a **View original ↗** link to the source thread. No second set of counters.

> **Implementation update (2026-06-24, Option B):** per user decision, native
> like/comment are KEPT (a local like/reply still federates out) — we do NOT
> suppress `LikeLink`/`CommentLink`/`Comments`. Instead we only ADD a separate,
> clearly-labelled "Fediverse" engagement indicator (favourite + boost counts +
> "View original" link). Reply count is omitted: Mastodon's `replies` collection
> exposes no `totalItems` via AP, so the link covers the discussion. So: no
> `onWallEntryAddonsRun`, and `onWallEntryLinksRun` only adds the widget (no
> removeWidget). The Task 1–4 bodies below are the original (suppressing) draft.

**Why this shape:** A remote Note's AP doc already contains `likes.totalItems` and `shares.totalItems` inline (verified) and a human `url`. Replies have no reliable inline count → we don't show a reply number, the origin link covers the discussion. No content is mirrored, so nothing goes stale or lingers after deletion. **No DB schema** — counts live in the cache; the link uses the note's AP id (or cached `url`).

**Mechanics (verified):**
- `WallEntryLinks` (the like/comment action row) and `WallEntryAddons` (the comment list/form) both `extend BaseStack` (no own `run()`), so `EVENT_RUN` ('run') fires after every init contribution → safe point to `removeWidget()`/`addWidget()`. Both expose `public $object` (the content record).
- Like adds `humhub\modules\like\widgets\LikeLink` to WallEntryLinks; Comment adds `humhub\modules\comment\widgets\CommentLink` to WallEntryLinks and `humhub\modules\comment\widgets\Comments` to WallEntryAddons.

---

## Task 1: RefreshRemotePost job (cache the note's counts)

**Create** `jobs/RefreshRemotePost.php`:
```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\queue\ActiveJob;
use Yii;

/** Best-effort: fetch a remote Note and cache its like/boost counts + origin url (6h TTL). */
class RefreshRemotePost extends ActiveJob
{
    public string $apId = '';

    public function run()
    {
        if ($this->apId === '') {
            return;
        }
        try {
            $doc = (new RemoteActorResolver())->fetchActorDocument($this->apId);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse post-stats refresh failed for ' . $this->apId . ': ' . $e->getMessage(), 'fediverse');
            return;
        }
        $likes = isset($doc['likes']['totalItems']) && is_numeric($doc['likes']['totalItems']) ? (int) $doc['likes']['totalItems'] : null;
        $shares = isset($doc['shares']['totalItems']) && is_numeric($doc['shares']['totalItems']) ? (int) $doc['shares']['totalItems'] : null;
        $url = is_string($doc['url'] ?? null) ? $doc['url'] : null;
        Yii::$app->cache->set('fediverse.poststats.' . md5($this->apId), [
            'likes' => $likes,
            'shares' => $shares,
            'url' => $url,
        ], 21600);
    }
}
```

## Task 2: RemotePostEngagement widget

**Create** `widgets/RemotePostEngagement.php`:
```php
<?php

namespace humhub\modules\fediverse\widgets;

use humhub\components\Widget;
use Yii;

/** Read-only engagement element for an ingested remote post: real like/boost counts + origin link. */
class RemotePostEngagement extends Widget
{
    public $apId;

    public function run()
    {
        if (!$this->apId) {
            return '';
        }
        $stats = Yii::$app->cache->get('fediverse.poststats.' . md5($this->apId));
        $url = (is_array($stats) && !empty($stats['url'])) ? $stats['url'] : $this->apId;
        return $this->render('remotePostEngagement', [
            'url' => $url,
            'likes' => is_array($stats) ? ($stats['likes'] ?? null) : null,
            'shares' => is_array($stats) ? ($stats['shares'] ?? null) : null,
        ]);
    }
}
```

**Create** `widgets/views/remotePostEngagement.php`:
```php
<?php

use yii\helpers\Html;

/* @var $url string */
/* @var $likes int|null */
/* @var $shares int|null */

$parts = [];
if ($likes !== null) {
    $parts[] = '♥ ' . Yii::$app->formatter->asInteger($likes);
}
if ($shares !== null) {
    $parts[] = '🔁 ' . Yii::$app->formatter->asInteger($shares);
}
?>
<span class="text-muted">
    <?php if ($parts) : ?><?= Html::encode(implode('   ', $parts)); ?> &middot; <?php endif; ?>
    <?= Html::a(Yii::t('FediverseModule.base', 'View original') . ' ↗', $url, ['target' => '_blank', 'rel' => 'noopener']); ?>
</span>
```

## Task 3: Events hooks + config

**Modify** `Events.php` — add `use humhub\modules\fediverse\jobs\RefreshRemotePost;` (Post, FederationObject, RemoteActor, Yii already imported) and:
```php
    /** Remote posts: drop native like/comment links, show the real-counts + origin element instead. */
    public static function onWallEntryLinksRun($event): void
    {
        $apId = self::remotePostApId($event->sender->object ?? null);
        if ($apId === null) {
            return;
        }
        self::lazyRefreshRemotePost($apId);
        $event->sender->removeWidget('humhub\modules\like\widgets\LikeLink');
        $event->sender->removeWidget('humhub\modules\comment\widgets\CommentLink');
        $event->sender->addWidget('humhub\modules\fediverse\widgets\RemotePostEngagement', ['apId' => $apId], ['sortOrder' => 15]);
    }

    /** Remote posts: drop the native comment list/form (no native commenting on a mirrored post). */
    public static function onWallEntryAddonsRun($event): void
    {
        if (self::remotePostApId($event->sender->object ?? null) === null) {
            return;
        }
        $event->sender->removeWidget('humhub\modules\comment\widgets\Comments');
    }

    /** If the wall object is an ingested remote post, return its Note AP id; else null. */
    private static function remotePostApId($object): ?string
    {
        if (!$object instanceof Post) {
            return null;
        }
        $authorId = (int) ($object->content->created_by ?? 0);
        if ($authorId === 0 || RemoteActor::findOne(['user_id' => $authorId]) === null) {
            return null; // local post — leave native UI untouched
        }
        $fed = FederationObject::findOne([
            'direction' => FederationObject::DIRECTION_REMOTE,
            'object_type' => 'Note',
            'model_class' => Post::class,
            'model_id' => $object->id,
        ]);
        return $fed?->ap_id;
    }

    /** Enqueue a background refresh of a remote post's cached counts if absent/expired (6h TTL). */
    private static function lazyRefreshRemotePost(string $apId): void
    {
        $cache = Yii::$app->cache;
        if ($cache->get('fediverse.poststats.' . md5($apId)) !== false) {
            return; // fresh within TTL
        }
        $lock = 'fediverse.poststats.lock.' . md5($apId);
        if ($cache->get($lock)) {
            return;
        }
        $cache->set($lock, 1, 600);
        Yii::$app->queue->push(new RefreshRemotePost(['apId' => $apId]));
    }
```

**Modify** `config.php` — add to `events`:
```php
        ['class' => 'humhub\modules\content\widgets\WallEntryLinks', 'event' => 'run', 'callback' => ['humhub\modules\fediverse\Events', 'onWallEntryLinksRun']],
        ['class' => 'humhub\modules\content\widgets\WallEntryAddons', 'event' => 'run', 'callback' => ['humhub\modules\fediverse\Events', 'onWallEntryAddonsRun']],
```

## Task 4: CHANGELOG
```markdown
- Enh: Ingested remote posts no longer show HumHub's native like/comment UI
  (which would only reflect local interaction). Instead they show the post's real
  Fediverse like/boost counts and a "View original" link to the source thread —
  one set of counters, nothing mirrored or kept past deletion. Counts come from
  the Note document and refresh lazily in the background (6h) when the post is
  viewed.
```

---

## Deploy + verification (controller + human)
1. `php -l` all; flush cache.
2. **Admin:** open the dashboard / a followed remote account's profile with ingested posts. A remote post shows **no** native Like/Comment buttons or comment box; instead `♥ N  🔁 M · View original ↗` (numbers appear after the first background refresh — reload). Your OWN posts and local posts are completely unchanged (native like/comment intact).

## Self-review risks
- **Local posts untouched:** `remotePostApId()` returns null unless the author is a shadow user AND a remote Note FederationObject exists.
- **EVENT_RUN timing:** fires after all init contributions, so LikeLink/CommentLink/Comments are present to remove; runs per render (no accumulation).
- **Counts staleness:** ≤6h for viewed posts; absent counts just hide the numbers (link still shown). Dead notes (410) simply leave no cached counts.
- **Performance:** one indexed `RemoteActor` lookup per wall entry (fast no-op for local posts); the FederationObject lookup only runs for shadow-authored posts.
- **Tradeoff (intended):** local users can no longer natively like/comment an ingested remote post (so no outbound like/reply from that surface) — matches the "remote posts are a read-only window" decision.
