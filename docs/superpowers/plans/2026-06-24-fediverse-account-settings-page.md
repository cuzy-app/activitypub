# Fediverse — consolidated account-settings page (User → Settings → Fediverse)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Add a "Fediverse" entry to the user account-settings menu (User → Settings) linking to one page that bundles all per-user Fediverse options: identity/handle, publish opt-in, follow a remote account, and the following list. Consolidate the two existing URL-only pages (`RemoteController`, `ProfileConfigController`) into it.

**Tech Stack:** PHP 8, Yii2, HumHub UI menu system. No core change.

> **Implementation update (2026-06-24):** per user feedback ("don't bury it under
> Settings/General — give it its own nav point"), the entry is registered as a
> **top-level** `AccountMenu` entry (`AccountMenu::EVENT_INIT` →
> `onAccountMenuInit`, with an `id`/`icon`, no `markAsActive`) instead of an
> `AccountSettingsMenu` sub-entry. Consequently the view renders with just the
> `_layout` (AccountMenu sidebar + one `panel panel-default`) and provides its own
> `panel-heading` + `panel-body` (no `beginContent(_userSettingsLayout)` wrapper).
> Task 2/Task 3 code below reflects the original sub-entry approach; the shipped
> code follows this note.

**Key facts (verified):**
- Menu hook: `['class' => AccountSettingsMenu::class, 'event' => EVENT_INIT('init'), 'callback' => ...]`; handler calls `$event->sender->addEntry(new MenuLink([...]))` (pattern: core `topic` module).
- Render in the settings sidebar: `$this->subLayout = '@humhub/modules/user/views/account/_layout'` in the controller `init()`.
- `DiscoveryPolicy::isDiscoverable($user)` = global on **and** `visibility === VISIBILITY_ALL` (followable). `federationEnabled` (per-user container setting) is the **separate** content/publish opt-in.
- Local handle = `'@' . FederationUrl::normalizeHandle($user->username) . '@' . FederationUrl::domain()`. Actor URL = `FederationUrl::actorUrl($user->guid)`.
- Follow: `$shadowUser->follow($user->id)`; Unfollow: `$shadowUser->unfollow($user->id)` (`Followable` behavior) — fire the outbound `Follow`/`Undo` via existing `Events` handlers.
- `RemoteActor->handle` is stored without a leading `@` (e.g. `cmburns@mastodon.social`).
- View flash: `$this->view->saved()/success($m)/error($m)`. `forcePostRequest()` exists. `Menu::markAsActive($str)` static.

---

## Task 1: SettingsController

**Files:** Create `controllers/SettingsController.php`

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;

/**
 * Consolidated per-user Fediverse settings (User → Settings → Fediverse):
 * identity/handle, publish opt-in, follow remote accounts, and the following list.
 */
class SettingsController extends Controller
{
    public function init()
    {
        parent::init();
        // Render inside the account-settings layout (left settings sidebar).
        $this->subLayout = '@humhub/modules/user/views/account/_layout';
    }

    protected function getAccessRules()
    {
        return [['login']];
    }

    public function actionIndex()
    {
        $user = Yii::$app->user->getIdentity();
        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($user);

        $follows = FederationFollow::find()
            ->where([
                'local_user_id' => $user->id,
                'direction' => FederationFollow::DIRECTION_OUTBOUND,
            ])
            ->andWhere(['!=', 'state', FederationFollow::STATE_REJECTED])
            ->with('remoteActor')
            ->orderBy(['id' => SORT_DESC])
            ->all();

        return $this->render('index', [
            'handle' => '@' . FederationUrl::normalizeHandle($user->username) . '@' . FederationUrl::domain(),
            'actorUrl' => FederationUrl::actorUrl($user->guid),
            'discoverable' => DiscoveryPolicy::isDiscoverable($user),
            'publishEnabled' => (bool) $settings->get('federationEnabled', false),
            'follows' => $follows,
        ]);
    }

    public function actionPublish()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($user);
        $settings->set('federationEnabled', Yii::$app->request->post('federationEnabled') ? 1 : 0);
        $this->view->saved();
        return $this->redirect(['index']);
    }

    public function actionFollow()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        if (!DiscoveryPolicy::isDiscoverable($user)) {
            $this->view->error(Yii::t('FediverseModule.base', 'Your profile must be discoverable (visible to all, including guests) before you can follow remote accounts.'));
            return $this->redirect(['index']);
        }
        $handle = (string) Yii::$app->request->post('handle', '');
        try {
            $remote = (new RemoteActorResolver())->resolveByHandle($handle);
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->follow($user->id); // fires Follow::EVENT_FOLLOWING_CREATED → outbound Follow
                $this->view->success(Yii::t('FediverseModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
            }
        } catch (\Throwable $e) {
            $this->view->error(Yii::t('FediverseModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]));
        }
        return $this->redirect(['index']);
    }

    public function actionUnfollow()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        $remote = RemoteActor::findOne((int) Yii::$app->request->post('remoteActorId', 0));
        if ($remote !== null) {
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->unfollow($user->id); // fires followRemoved → outbound Undo{Follow}
                $this->view->success(Yii::t('FediverseModule.base', 'Unfollowed {handle}.', ['handle' => $remote->handle]));
            }
        }
        return $this->redirect(['index']);
    }
}
```

- [ ] Verify `php -l controllers/SettingsController.php`.

---

## Task 2: the view

**Files:** Create `views/settings/index.php`

```php
<?php

use humhub\modules\fediverse\models\FederationFollow;
use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $handle string */
/* @var $actorUrl string */
/* @var $discoverable bool */
/* @var $publishEnabled bool */
/* @var $follows FederationFollow[] */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Your Fediverse handle'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'People on Mastodon and other Fediverse servers can search for this handle to follow you:'); ?></p>
        <p><code><?= Html::encode($handle); ?></code></p>
        <p><?= Html::a(Yii::t('FediverseModule.base', 'View your federation profile'), $actorUrl, ['target' => '_blank', 'rel' => 'noopener']); ?></p>
        <?php if ($discoverable) : ?>
            <div class="alert alert-success"><?= Yii::t('FediverseModule.base', 'Your profile is discoverable — remote users can follow you.'); ?></div>
        <?php else : ?>
            <div class="alert alert-warning"><?= Yii::t('FediverseModule.base', 'Your profile is not yet discoverable. To be followable from the Fediverse your profile visibility must be “Visible to all (including guests)”, and federation must be enabled for this site.'); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Publish your posts'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'When enabled, your public profile posts are delivered to your Fediverse followers.'); ?></p>
        <?= Html::beginForm(['publish']); ?>
            <div class="checkbox">
                <label>
                    <?= Html::checkbox('federationEnabled', $publishEnabled); ?>
                    <?= Yii::t('FediverseModule.base', 'Publish my public posts to the Fediverse'); ?>
                </label>
            </div>
            <?= Button::primary(Yii::t('FediverseModule.base', 'Save'))->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'Enter a handle like {example}. Their public posts will appear in your dashboard.', ['example' => '@user@mastodon.social']); ?></p>
        <?= Html::beginForm(['follow']); ?>
            <div class="form-group">
                <?= Html::textInput('handle', '', ['class' => 'form-control', 'placeholder' => '@user@domain']); ?>
            </div>
            <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Accounts you follow'); ?></div>
    <div class="panel-body">
        <?php if (empty($follows)) : ?>
            <p class="text-muted"><?= Yii::t('FediverseModule.base', 'You are not following any Fediverse accounts yet.'); ?></p>
        <?php else : ?>
            <ul class="list-group">
                <?php foreach ($follows as $follow) : ?>
                    <?php $remote = $follow->remoteActor; ?>
                    <?php if ($remote === null) { continue; } ?>
                    <li class="list-group-item clearfix">
                        <code>@<?= Html::encode($remote->handle); ?></code>
                        <?php if ($follow->state === FederationFollow::STATE_PENDING) : ?>
                            <span class="label label-default"><?= Yii::t('FediverseModule.base', 'pending'); ?></span>
                        <?php else : ?>
                            <span class="label label-success"><?= Yii::t('FediverseModule.base', 'following'); ?></span>
                        <?php endif; ?>
                        <span class="pull-right">
                            <?= Html::beginForm(['unfollow']); ?>
                                <?= Html::hiddenInput('remoteActorId', $remote->id); ?>
                                <?= Button::danger(Yii::t('FediverseModule.base', 'Unfollow'))->submit(); ?>
                            <?= Html::endForm(); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
```

(If `Button::danger` lacks a small-size helper, leave default size. Verify `Button` has `primary`/`danger` static constructors — they are used elsewhere in the module's views.)

---

## Task 3: menu entry

**Files:** Modify `Events.php`, `config.php`

- [ ] **Events.php** — add imports (top, with the other `use` lines):
```php
use humhub\helpers\ControllerHelper;
use humhub\modules\ui\menu\MenuLink;
use humhub\modules\user\widgets\AccountMenu;
```
and the handler (anywhere among the static handlers):
```php
    /** Adds a "Fediverse" entry to the user account-settings menu (User → Settings). */
    public static function onAccountSettingsMenuInit($event): void
    {
        if (Yii::$app->user->isGuest || !DiscoveryPolicy::isGloballyEnabled()) {
            return;
        }
        $event->sender->addEntry(new MenuLink([
            'label' => Yii::t('FediverseModule.base', 'Fediverse'),
            'url' => ['/fediverse/settings'],
            'sortOrder' => 350,
            'isActive' => ControllerHelper::isActivePath('fediverse', 'settings'),
        ]));
        if (ControllerHelper::isActivePath('fediverse', 'settings')) {
            AccountMenu::markAsActive('account-settings-settings');
        }
    }
```

- [ ] **config.php** — add to the `events` array:
```php
        ['class' => 'humhub\modules\user\widgets\AccountSettingsMenu', 'event' => 'init', 'callback' => ['humhub\modules\fediverse\Events', 'onAccountSettingsMenuInit']],
```

- [ ] Verify `php -l Events.php && php -l config.php`.

---

## Task 4: consolidate old pages

**Files:** Delete `controllers/RemoteController.php`, `controllers/ProfileConfigController.php`, `views/remote/index.php`, `views/profile-config/index.php`.

- [ ] Confirm no references remain (grep already showed none outside the files themselves). Delete the four files.
- [ ] Verify the module still lints: `find . -name '*.php' -not -path '*/vendor/*' | xargs -n1 php -l >/dev/null`.

---

## Task 5: CHANGELOG

**Files:** Modify `docs/CHANGELOG.md`

- [ ] Add under the `0.1.0 (Unreleased)` section, before the unit-tests bullet:
```markdown
- Enh: A single "Fediverse" page under User → Settings bundles all per-user
  options — your `@user@domain` handle, the publish opt-in, following a remote
  account by handle, and the list of accounts you follow (with unfollow). It
  replaces the two separate, unlinked pages.
```

---

## Deploy + verification (controller + human)

1. (controller) Flush cache (new `config.php` event), `php -l` all, no stray references to deleted controllers.
2. **Admin:** User menu (top-right) → Settings → a **Fediverse** entry appears in the left settings menu. Open it: handle shown, publish toggle reflects current state, follow form present, following list shows `@cmburns@mastodon.social` as `following`.
3. **Admin:** follow a new handle from the page → success flash, appears in the list; Unfollow → removed, Undo delivered. Toggle publish off/on → saved.

## Self-review risks
- **Layout:** if `_layout` is container-scoped and errors for a plain Controller, fall back to rendering without subLayout (page still works, just without the sidebar) — but `topic`'s user branch uses exactly this layout for a user context.
- **Menu visibility:** gated on `isGloballyEnabled()` so the entry is hidden when federation is off site-wide.
- **Discoverability vs publish:** the page distinguishes "followable" (visibility=ALL + global) from "publish posts" (`federationEnabled`); follow action enforces discoverability like the old `RemoteController`.
- **Deleting old controllers** removes `/fediverse/remote` + `/fediverse/profile-config` (404 after). Safe: pre-release, nothing links to them.
