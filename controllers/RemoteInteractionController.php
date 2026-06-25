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
        if ($user === null) {
            return $this->redirect(['/user/auth/login']);
        }
        $discoverabilityError = DiscoveryPolicy::discoverabilityError($user);
        if ($discoverabilityError !== null) {
            return $this->render('authorize', [
                'uri' => $uri,
                'remote' => null,
                'error' => $discoverabilityError,
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
