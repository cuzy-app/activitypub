# Fediverse — remote-profile sidebar cleanup + remote-account widget + hide friend button

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** On a federated (remote) shadow user's profile:
1. Remove the local-graph sidebar panels that are meaningless for a remote account: **Spaces** (`UserSpaces`), **Followers** (`UserFollower`), and the **Friends** panel (`FriendsPanel`).
2. Add a **Remote account** sidebar widget: a short note that this is a mirrored Fediverse profile, a link to the original, and metadata (handle, home server, real follower/following counts).
3. Hide the **"+ Friends"** button (a friend request to a login-disabled shadow user can never be accepted).

**Detection:** a `RemoteActor` row exists for the profile user's id.

**Mechanics (verified):**
- `ProfileSidebar extends BaseSidebar extends BaseStack` — `public $widgets`, `removeWidget($fqcn)`, `addWidget($fqcn,$params,$opts)`; fires `EVENT_INIT` ('init') after the `widgets` config is applied (Yii sets public props before `init()`). Widgets set in `user/views/profile/home.php`: UserTags(10), UserSpaces(20), FriendsPanel(30), UserFollower(40).
- `FriendshipButton extends Widget` with `public $user`; hide via `EVENT_BEFORE_RUN` → `$event->isValid = false` (same pattern as the counter-set hook). `isVisibleForUser()` only checks guest/enabled/self — does not exclude shadow users.

---

## Task 1: RemoteAccountInfo widget

**Create** `widgets/RemoteAccountInfo.php`:
```php
<?php

namespace humhub\modules\fediverse\widgets;

use humhub\components\Widget;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\user\models\User;

/** Sidebar panel shown on a federated shadow user's profile: origin link + metadata. */
class RemoteAccountInfo extends Widget
{
    public $user;

    public function run()
    {
        if (!$this->user instanceof User) {
            return '';
        }
        $remote = RemoteActor::findOne(['user_id' => $this->user->id]);
        if ($remote === null) {
            return '';
        }
        return $this->render('remoteAccountInfo', ['remote' => $remote]);
    }
}
```

**Create** `widgets/views/remoteAccountInfo.php`:
```php
<?php

use yii\helpers\Html;

/* @var $remote \humhub\modules\fediverse\models\RemoteActor */

$url = $remote->profile_url ?: $remote->ap_id;
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Remote account'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'This is a profile from the Fediverse, mirrored here. The full profile lives on its home server.'); ?></p>
        <p><strong><?= Html::a(Html::encode('@' . $remote->handle), $url, ['target' => '_blank', 'rel' => 'noopener']); ?></strong></p>
        <p><?= Html::a(Yii::t('FediverseModule.base', 'View full profile on {domain}', ['domain' => Html::encode($remote->domain)]),
            $url, ['target' => '_blank', 'rel' => 'noopener', 'class' => 'btn btn-default btn-sm']); ?></p>
        <?php if ($remote->followers_count !== null || $remote->following_count !== null) : ?>
            <hr>
            <small class="text-muted">
                <?php if ($remote->followers_count !== null) : ?>
                    <?= Yii::t('FediverseModule.base', '{n} followers', ['n' => Yii::$app->formatter->asInteger($remote->followers_count)]); ?>
                <?php endif; ?>
                <?php if ($remote->following_count !== null) : ?>
                    &middot; <?= Yii::t('FediverseModule.base', '{n} following', ['n' => Yii::$app->formatter->asInteger($remote->following_count)]); ?>
                <?php endif; ?>
            </small>
        <?php endif; ?>
    </div>
</div>
```

---

## Task 2: Events handlers

**Modify** `Events.php` — add the two handlers (RemoteActor, User already imported):
```php
    /** Remote profiles: drop the meaningless local-graph sidebar panels and add the remote-account panel. */
    public static function onProfileSidebarInit($event): void
    {
        $sidebar = $event->sender;
        $user = $sidebar->user ?? null;
        if (!$user instanceof User || RemoteActor::findOne(['user_id' => $user->id]) === null) {
            return;
        }
        $sidebar->removeWidget('humhub\modules\user\widgets\UserSpaces');
        $sidebar->removeWidget('humhub\modules\user\widgets\UserFollower');
        $sidebar->removeWidget('humhub\modules\friendship\widgets\FriendsPanel');
        $sidebar->addWidget('humhub\modules\fediverse\widgets\RemoteAccountInfo', ['user' => $user], ['sortOrder' => 5]);
    }

    /** Hide the "Add Friend" button on remote profiles (a request to a login-disabled shadow user is pointless). */
    public static function onFriendshipButton($event): void
    {
        $user = $event->sender->user ?? null;
        if ($user instanceof User && RemoteActor::findOne(['user_id' => $user->id]) !== null) {
            $event->isValid = false;
        }
    }
```

**Modify** `config.php` — add to `events`:
```php
        ['class' => 'humhub\modules\user\widgets\ProfileSidebar', 'event' => 'init', 'callback' => ['humhub\modules\fediverse\Events', 'onProfileSidebarInit']],
        ['class' => 'humhub\modules\friendship\widgets\FriendshipButton', 'event' => 'beforeRun', 'callback' => ['humhub\modules\fediverse\Events', 'onFriendshipButton']],
```

---

## Task 3: CHANGELOG

Add under `0.1.0 (Unreleased)` (before the unit-tests bullet):
```markdown
- Enh: A remote user's profile drops the meaningless local-graph sidebar panels
  (Spaces, Followers, Friends) in favour of a "Remote account" panel linking to
  the origin profile with the real follower/following counts, and hides the
  "Add Friend" button (a friend request to a remote account can't be accepted).
```

---

## Deploy + verification (controller + human)
1. `php -l` all; flush cache (new events).
2. **Admin:** open `@cmburns` / `@EUCommission` profile → sidebar shows a **Remote account** panel (origin link + handle + counts), and **no** Spaces/Followers/Friends panels; the **"+ Friends"** button is gone. A **local** user's profile is unchanged (panels + friend button intact).

## Self-review risks
- **Local users untouched:** both handlers no-op unless a `RemoteActor` exists for the profile user.
- **removeWidget needs exact FQCN strings** matching `home.php` (`humhub\modules\user\widgets\UserSpaces` etc.).
- **EVENT_INIT timing:** `widgets` config is applied before `init()`, so the panels are present to remove.
- **Friendship module disabled:** the `FriendshipButton`/`FriendsPanel` classes simply never render, so the event/removeWidget are harmless no-ops.
- **Follow still reachable:** hiding the friend button doesn't remove the Follow action (it stays in the header controls menu).
