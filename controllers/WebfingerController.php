<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\LocalActor;
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
    /** Public, unauthenticated endpoint. */
    public $access = null;

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

        // Tolerate a leading '@' (some clients send acct:@user@domain).
        $acct = ltrim(substr($resource, strlen('acct:')), '@');
        if (!str_contains($acct, '@')) {
            throw new BadRequestHttpException('resource must be acct:user@domain');
        }
        [$handle, $domain] = explode('@', $acct, 2);
        if ($handle === '' || strcasecmp($domain, FederationUrl::domain()) !== 0) {
            throw new NotFoundHttpException();
        }

        // The instance (Application) actor is discoverable directly when federation is on.
        $instance = LocalActor::findOne([
            'preferred_username' => $handle,
            'type' => LocalActor::TYPE_APPLICATION,
        ]);
        if ($instance !== null) {
            if (!DiscoveryPolicy::isGloballyEnabled()) {
                throw new NotFoundHttpException();
            }
            return $this->jrd($instance->preferred_username, $instance->ap_id, null);
        }

        $user = $this->resolveUserByHandle($handle);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        return $this->jrd($actor->preferred_username, $actor->ap_id, $user->getUrl(true));
    }

    /**
     * Resolve a WebFinger handle to a user: first by an existing LocalActor
     * preferred_username, then by the raw username as a fallback.
     */
    private function resolveUserByHandle(string $handle): ?User
    {
        $actor = LocalActor::findOne(['preferred_username' => $handle]);
        if ($actor !== null && $actor->user_id !== null) {
            return User::findOne($actor->user_id);
        }
        return User::findOne(['username' => $handle]);
    }

    private function jrd(string $handle, string $actorUrl, ?string $profileUrl): Response
    {
        $aliases = [$actorUrl];
        $links = [
            ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUrl],
        ];
        if ($profileUrl !== null) {
            $aliases[] = $profileUrl;
            $links[] = [
                'rel' => 'http://webfinger.net/rel/profile-page',
                'type' => 'text/html',
                'href' => $profileUrl,
            ];
        }
        // Remote-follow template: lets other servers route a follow of one of our
        // users through our /authorize_interaction landing.
        $links[] = [
            'rel' => 'http://ostatus.org/schema/1.0/subscribe',
            'template' => FederationUrl::base() . '/authorize_interaction?uri={uri}',
        ];

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/jrd+json; charset=utf-8');
        $response->content = json_encode([
            'subject' => 'acct:' . $handle . '@' . FederationUrl::domain(),
            'aliases' => $aliases,
            'links' => $links,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
