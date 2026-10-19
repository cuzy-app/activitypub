<?php

namespace humhub\modules\activitypub\controllers;

use humhub\components\Controller;
use humhub\modules\activitypub\services\CommunityResolver;
use humhub\modules\activitypub\services\CommunitySubscriptionService;
use humhub\modules\activitypub\services\DiscoveryPolicy;
use humhub\modules\activitypub\services\RemoteActorResolver;
use humhub\modules\activitypub\services\UrlSafety;
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
        if ($user === null) {
            return $this->redirect(['/user/auth/login']);
        }

        // Resolve to the actor document (no persistence) so we can branch on type.
        $doc = null;
        $error = null;
        try {
            $doc = $this->resolveDoc($uri);
        } catch (\Throwable $e) {
            $error = Yii::t('ActivitypubModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]);
        }
        if ($doc === null && $error === null) {
            $error = Yii::t('ActivitypubModule.base', 'That address is not a followable account.');
        }

        // A Lemmy-family community (Group) → join its mirrored Space. No shadow user,
        // and the Space's own Group actor sends the Follow, so no discoverability need.
        if ($doc !== null && ($doc['type'] ?? '') === 'Group') {
            if (Yii::$app->request->isPost) {
                try {
                    $space = (new CommunitySubscriptionService())
                        ->provisionOrJoin($user, $doc, (string) ($doc['id'] ?? $uri), $this->handleFor($uri, $doc));
                    $this->view->success(Yii::t('ActivitypubModule.base', 'Joined the community space “{name}”.', ['name' => $space->name]));
                    return $this->redirect($space->getUrl());
                } catch (\Throwable $e) {
                    $error = Yii::t('ActivitypubModule.base', 'Could not join that community: {msg}', ['msg' => $e->getMessage()]);
                }
            }
            return $this->render('authorize', [
                'uri' => $uri,
                'remote' => null,
                'community' => ['name' => (string) ($doc['name'] ?? $doc['preferredUsername'] ?? 'Community'), 'handle' => $this->handleFor($uri, $doc)],
                'error' => $error,
            ]);
        }

        // A person (or other actor) → follow it as this user (requires discoverability).
        $remote = null;
        if ($doc !== null && $error === null) {
            $discoverabilityError = DiscoveryPolicy::discoverabilityError($user);
            if ($discoverabilityError !== null) {
                return $this->render('authorize', ['uri' => $uri, 'remote' => null, 'community' => null, 'error' => $discoverabilityError]);
            }
            try {
                $remote = (new RemoteActorResolver())->resolveFromDocument((string) ($doc['id'] ?? $uri), $doc);
            } catch (\Throwable $e) {
                $error = Yii::t('ActivitypubModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        if ($remote !== null && Yii::$app->request->isPost) {
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->follow($user->id); // outbound Follow via the usual event path
                $this->view->success(Yii::t('ActivitypubModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
                return $this->redirect($shadow->getUrl());
            }
        }

        return $this->render('authorize', ['uri' => $uri, 'remote' => $remote, 'community' => null, 'error' => $error]);
    }

    /** Resolve a remote-interaction uri (acct/! handle or actor URL) to its actor document, or null. */
    private function resolveDoc(string $uri): ?array
    {
        $resolver = new RemoteActorResolver();
        if (stripos($uri, 'http') !== 0) {
            $handle = preg_replace('/^acct:/i', '', trim($uri));
            $wf = CommunityResolver::webfingerUrl($handle); // strips a leading ! or @, builds acct:name@host
            if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
                return null;
            }
            $actorUrl = $resolver->webfingerActorUrl($wf);
            $doc = $resolver->fetchActorDocument($actorUrl);
        } else {
            $doc = $resolver->fetchActorDocument($uri); // SSRF-guarded inside
        }
        return empty($doc['inbox']) ? null : $doc;
    }

    /** A display handle (!name@host) for a community, from the input uri or the resolved doc. */
    private function handleFor(string $uri, array $doc): string
    {
        if (stripos($uri, 'http') !== 0) {
            return '!' . ltrim(preg_replace('/^acct:/i', '', trim($uri)), '!@');
        }
        $host = (string) parse_url((string) ($doc['id'] ?? ''), PHP_URL_HOST);
        return '!' . (string) ($doc['preferredUsername'] ?? '') . '@' . $host;
    }
}
