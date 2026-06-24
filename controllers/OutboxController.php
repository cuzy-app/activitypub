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

        // This user's federated Notes (by their authored, locally-recorded objects), newest first.
        $postIds = Post::find()
            ->joinWith('content')
            ->where(['content.created_by' => $user->id])
            ->select('post.id')->column();

        $query = FederationObject::find()
            ->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])
            ->andWhere(['in', 'model_id', $postIds ?: [0]])
            ->orderBy(['id' => SORT_DESC]);

        $total = (int) $query->count();
        $pageSize = 20;
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $outbox = $actor->ap_id . '/outbox';
        $page = (int) Yii::$app->request->get('page', 0);

        if ($page < 1) {
            // The collection: pointers to the first/last page (items live on the pages).
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $outbox,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $outbox . '?page=1',
                'last' => $outbox . '?page=' . $lastPage,
            ];
        } else {
            // A page: the actual Create activities, referencing each Note by its AP id.
            $objects = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->all();
            $items = [];
            foreach ($objects as $obj) {
                $items[] = [
                    'id' => $obj->ap_id . '/activity',
                    'type' => 'Create',
                    'actor' => $actor->ap_id,
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'object' => $obj->ap_id,
                ];
            }
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $outbox . '?page=' . $page,
                'type' => 'OrderedCollectionPage',
                'partOf' => $outbox,
                'orderedItems' => $items,
            ];
            if ($page < $lastPage) {
                $doc['next'] = $outbox . '?page=' . ($page + 1);
            }
            if ($page > 1) {
                $doc['prev'] = $outbox . '?page=' . ($page - 1);
            }
        }

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->content = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
