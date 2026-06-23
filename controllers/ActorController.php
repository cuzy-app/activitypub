<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\PersonSerializer;
use humhub\modules\user\models\User;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ActorController extends Controller
{
    /** Actor documents are public, unauthenticated GETs. */
    public $access = null;

    public function beforeAction($action)
    {
        // Allow guest access to these endpoints regardless of site guest mode UI.
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /fediverse/actor/<guid> */
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        $extras = PersonSerializer::profileExtras($user);
        $doc = (new PersonSerializer())->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon']);

        return $this->asActivityJson($doc);
    }

    /** GET /fediverse/instance-actor */
    public function actionInstance()
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new NotFoundHttpException();
        }
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $doc = (new PersonSerializer())->serialize($actor, $actor->preferred_username, \humhub\modules\fediverse\services\FederationUrl::base());

        return $this->asActivityJson($doc);
    }

    private function asActivityJson(array $doc): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->content = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
