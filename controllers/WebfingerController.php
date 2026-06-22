<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\user\models\User;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebfingerController extends Controller
{
    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /.well-known/webfinger?resource=acct:<handle>@<domain> */
    public function actionIndex($resource = null)
    {
        if (!is_string($resource) || !str_starts_with($resource, 'acct:')) {
            throw new BadRequestHttpException('resource must be an acct: URI');
        }

        // acct:handle@domain
        $acct = substr($resource, strlen('acct:'));
        [$handle, $domain] = array_pad(explode('@', $acct, 2), 2, '');
        if ($handle === '' || strcasecmp($domain, FederationUrl::domain()) !== 0) {
            throw new NotFoundHttpException();
        }

        $user = $this->resolveUserByHandle($handle);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        $actorUrl = $actor->ap_id;

        $jrd = [
            'subject' => 'acct:' . $actor->preferred_username . '@' . FederationUrl::domain(),
            'aliases' => [$actorUrl, $user->getUrl(true)],
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $user->getUrl(true),
                ],
            ],
        ];

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->headers->set('Content-Type', 'application/jrd+json; charset=utf-8');
        $response->data = $jrd;
        return $response;
    }

    /**
     * Resolve a WebFinger handle to a user: first by an existing LocalActor
     * preferred_username, then by the raw username as a fallback.
     */
    private function resolveUserByHandle(string $handle): ?User
    {
        $actor = \humhub\modules\fediverse\models\LocalActor::findOne(['preferred_username' => $handle]);
        if ($actor !== null && $actor->user_id !== null) {
            return User::findOne($actor->user_id);
        }
        return User::findOne(['username' => $handle]);
    }
}
