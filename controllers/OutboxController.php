<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class OutboxController extends Controller
{
    public $access = null;

    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /fediverse/actor/<guid>/outbox */
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor === null) {
            throw new NotFoundHttpException();
        }

        // Count this user's federated Notes (by their authored, locally-recorded objects).
        $postIds = Post::find()
            ->joinWith('content')
            ->where(['content.created_by' => $user->id])
            ->select('post.id')->column();

        $total = FederationObject::find()
            ->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])
            ->andWhere(['in', 'model_id', $postIds ?: [0]])
            ->count();

        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor->ap_id . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => (int) $total,
            'first' => $actor->ap_id . '/outbox?page=1',
        ];

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->content = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
