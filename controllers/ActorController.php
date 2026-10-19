<?php

namespace humhub\modules\activitypub\controllers;

use humhub\components\Controller;
use humhub\modules\activitypub\models\FederationFollow;
use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\models\SpaceCommunity;
use humhub\modules\activitypub\services\DiscoveryPolicy;
use humhub\modules\activitypub\services\KeyManager;
use humhub\modules\activitypub\services\PersonSerializer;
use humhub\modules\space\models\Space;
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

    /** GET /activitypub/actor/<guid> */
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        $extras = PersonSerializer::profileExtras($user);
        $doc = (new PersonSerializer())->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon'], $extras['image']);

        return $this->asActivityJson($doc);
    }

    /** GET /activitypub/actor/space/<guid> — the Group actor for a federated Space. */
    public function actionSpace($guid)
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new NotFoundHttpException();
        }
        $space = Space::findOne(['guid' => $guid]);
        // Only a coupled, guest-visible Space is a valid federated Group actor.
        if ($space === null
            || $space->visibility != Space::VISIBILITY_ALL
            || SpaceCommunity::findOne(['space_id' => $space->id]) === null) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForSpace($space);

        $summary = trim((string) $space->description) !== ''
            ? \humhub\modules\content\widgets\richtext\RichText::convert($space->description, \humhub\modules\content\widgets\richtext\RichText::FORMAT_HTML)
            : null;
        $icon = ['type' => 'Image', 'url' => $space->getProfileImage()->getUrl(null, true)];

        $doc = (new PersonSerializer())->serialize($actor, $space->getDisplayName(), $space->getUrl(true), $summary, $icon, null);

        return $this->asActivityJson($doc);
    }

    /** GET /activitypub/instance-actor */
    public function actionInstance()
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new NotFoundHttpException();
        }
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $doc = (new PersonSerializer())->serialize($actor, $actor->preferred_username, \humhub\modules\activitypub\services\FederationUrl::base());

        return $this->asActivityJson($doc);
    }

    /** GET /activitypub/actor/<guid>/following */
    public function actionFollowing($guid)
    {
        return $this->followCollection($guid, 'following', FederationFollow::DIRECTION_OUTBOUND);
    }

    /** GET /activitypub/actor/<guid>/followers */
    public function actionFollowers($guid)
    {
        return $this->followCollection($guid, 'followers', FederationFollow::DIRECTION_INBOUND);
    }

    /**
     * Serve a user's follow graph as a paged OrderedCollection of actor URIs so
     * remote servers can read the real following/followers counts (totalItems).
     * Only federated relationships have an AP identity — local HumHub follows are
     * intentionally not part of these collections.
     */
    private function followCollection(string $guid, string $kind, string $direction): Response
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor === null) {
            throw new NotFoundHttpException();
        }

        $query = FederationFollow::find()
            ->where(['local_user_id' => $user->id, 'direction' => $direction]);
        // Outbound: everything the user actively follows (mirrors the settings list);
        // inbound: only accepted followers.
        if ($direction === FederationFollow::DIRECTION_OUTBOUND) {
            $query->andWhere(['!=', 'state', FederationFollow::STATE_REJECTED]);
        } else {
            $query->andWhere(['state' => FederationFollow::STATE_ACCEPTED]);
        }
        $query->orderBy(['id' => SORT_DESC]);

        $total = (int) $query->count();
        $pageSize = 50;
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $base = $actor->ap_id . '/' . $kind;
        $page = (int) Yii::$app->request->get('page', 0);

        if ($page < 1) {
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $base,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $base . '?page=1',
                'last' => $base . '?page=' . $lastPage,
            ];
        } else {
            $items = [];
            foreach ($query->with('remoteActor')->offset(($page - 1) * $pageSize)->limit($pageSize)->all() as $follow) {
                $apId = $follow->remoteActor->ap_id ?? null;
                if ($apId) {
                    $items[] = $apId;
                }
            }
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $base . '?page=' . $page,
                'type' => 'OrderedCollectionPage',
                'partOf' => $base,
                'orderedItems' => $items,
            ];
            if ($page < $lastPage) {
                $doc['next'] = $base . '?page=' . ($page + 1);
            }
            if ($page > 1) {
                $doc['prev'] = $base . '?page=' . ($page - 1);
            }
        }

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
