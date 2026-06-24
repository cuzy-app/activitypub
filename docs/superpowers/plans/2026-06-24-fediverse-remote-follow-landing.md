# Fediverse — remote-follow landing (`authorize_interaction`)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Bug:** When someone follows a HumHub user from elsewhere in the Fediverse (entering their `@user@test.fedihub.social` handle), Mastodon redirects to `https://test.fedihub.social/authorize_interaction?uri=…` → 404. Our WebFinger doesn't advertise the remote-follow template and we have no such endpoint.

**Fix:**
1. Advertise the `http://ostatus.org/schema/1.0/subscribe` template in WebFinger → `…/authorize_interaction?uri={uri}`.
2. Implement `/authorize_interaction`: a login-required landing that resolves the `uri` (handle or profile URL) to a remote actor and lets the user confirm-follow it (reusing the existing follow path), then redirects to the now-followed account's local profile.

**Security:** login required; the follow only happens on POST (HumHub CSRF); the follower must be federatable (`DiscoveryPolicy::isDiscoverable`); `uri` resolution is SSRF-guarded (`fetchActorDocument`/WebFinger already guard).

---

## Task 1: WebFinger subscribe template

**Modify** `controllers/WebfingerController.php` — in `jrd()`, after building `$links`, add (unconditionally):
```php
        $links[] = [
            'rel' => 'http://ostatus.org/schema/1.0/subscribe',
            'template' => FederationUrl::base() . '/authorize_interaction?uri={uri}',
        ];
```

## Task 2: RemoteInteractionController

**Create** `controllers/RemoteInteractionController.php`:
```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;
use yii\web\BadRequestHttpException;

/**
 * Remote-follow landing (Mastodon "authorize_interaction"): a logged-in local user
 * lands here (via the WebFinger subscribe template) to follow a remote account
 * they found elsewhere in the Fediverse.
 */
class RemoteInteractionController extends Controller
{
    protected function getAccessRules()
    {
        return [['login']];
    }

    /** GET/POST /authorize_interaction?uri=<handle|url> */
    public function actionIndex($uri = null)
    {
        $uri = trim((string) $uri);
        if ($uri === '') {
            throw new BadRequestHttpException('Missing uri');
        }
        $user = Yii::$app->user->getIdentity();
        if (!DiscoveryPolicy::isDiscoverable($user)) {
            return $this->render('authorize', [
                'uri' => $uri,
                'remote' => null,
                'error' => Yii::t('FediverseModule.base', 'Your profile must be discoverable (visible to all, including guests) before you can follow remote accounts.'),
            ]);
        }

        $remote = null;
        $error = null;
        try {
            $remote = $this->resolveUri($uri);
        } catch (\Throwable $e) {
            $error = Yii::t('FediverseModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]);
        }
        if ($remote === null && $error === null) {
            $error = Yii::t('FediverseModule.base', 'That address is not a followable account.');
        }

        if ($remote !== null && Yii::$app->request->isPost) {
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->follow($user->id); // outbound Follow via the usual event path
                $this->view->success(Yii::t('FediverseModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
                return $this->redirect($shadow->getUrl());
            }
        }

        return $this->render('authorize', ['uri' => $uri, 'remote' => $remote, 'error' => $error]);
    }

    /** Resolve a remote-interaction uri (acct handle or profile URL) to a RemoteActor. */
    private function resolveUri(string $uri): ?RemoteActor
    {
        $resolver = new RemoteActorResolver();
        if (stripos($uri, 'http') !== 0) {
            $handle = ltrim(preg_replace('/^acct:/i', '', $uri), '@');
            if (substr_count($handle, '@') !== 1) {
                return null;
            }
            return $resolver->resolveByHandle($handle);
        }
        $doc = $resolver->fetchActorDocument($uri);
        if (empty($doc['inbox'])) {
            return null; // not an actor (e.g. a status URL) — unsupported here
        }
        return $resolver->resolveFromDocument((string) ($doc['id'] ?? $uri), $doc);
    }
}
```

**Create** `views/remote-interaction/authorize.php`:
```php
<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $uri string */
/* @var $remote \humhub\modules\fediverse\models\RemoteActor|null */
/* @var $error string|null */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account'); ?></div>
    <div class="panel-body">
        <?php if ($error !== null) : ?>
            <div class="alert alert-warning"><?= Html::encode($error); ?></div>
            <?= Html::a(Yii::t('FediverseModule.base', 'Back to Fediverse settings'), ['/fediverse/settings'], ['class' => 'btn btn-default']); ?>
        <?php elseif ($remote !== null) : ?>
            <p><?= Yii::t('FediverseModule.base', 'Do you want to follow this Fediverse account?'); ?></p>
            <p><code>@<?= Html::encode($remote->handle); ?></code></p>
            <?= Html::beginForm(['/fediverse/remote-interaction/index', 'uri' => $uri]); ?>
                <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
                <?= Html::a(Yii::t('FediverseModule.base', 'Cancel'), ['/dashboard/dashboard'], ['class' => 'btn btn-link']); ?>
            <?= Html::endForm(); ?>
        <?php endif; ?>
    </div>
</div>
```

## Task 3: route

**Modify** `config.php` `urlManagerRules` — add:
```php
        ['pattern' => 'authorize_interaction', 'route' => 'fediverse/remote-interaction/index'],
```

## Task 4: CHANGELOG
```markdown
- Enh: Remote-follow landing — following a local user from elsewhere in the
  Fediverse now works: WebFinger advertises the `subscribe` template and a new
  `/authorize_interaction` endpoint lets a logged-in user confirm and follow a
  remote account (by handle or profile URL) they discovered on another server.
```

---

## Deploy + verification (controller + human)
1. `php -l` all; flush cache.
2. WebFinger: `…/.well-known/webfinger?resource=acct:Admin@test.fedihub.social` now includes a `subscribe` link with the `authorize_interaction?uri={uri}` template.
3. `/authorize_interaction?uri=@cmburns@mastodon.social` (logged in) → confirm page → Follow → lands on cmburns' local profile, now following.
4. **From Mastodon:** view a remote account, Follow, enter `@admin@test.fedihub.social` → redirected to our confirm page (after login) instead of 404.

## Self-review risks
- **Login required** (`getAccessRules`); guests are bounced to login and returned.
- **Follow only on POST** (CSRF-protected); GET just shows the confirm page.
- **SSRF:** URL `uri` goes through `fetchActorDocument` (UrlSafety guard); handles go through WebFinger.
- **Non-actor uri** (a status URL) → "not a followable account" message, no crash.
- **Not discoverable** → clear message instead of a silent failure.
