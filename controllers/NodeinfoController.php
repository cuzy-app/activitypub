<?php

namespace humhub\modules\activitypub\controllers;

use humhub\components\Controller;
use humhub\modules\activitypub\services\FederationUrl;
use Yii;
use yii\web\Response;

class NodeinfoController extends Controller
{
    /** Public, unauthenticated endpoint. */
    public $access = null;

    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /.well-known/nodeinfo — the discovery document. */
    public function actionIndex()
    {
        return $this->respondJson([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => FederationUrl::base() . '/nodeinfo/2.1',
                ],
            ],
        ], 'application/json; charset=utf-8');
    }

    /** GET /nodeinfo/2.1 — the NodeInfo 2.1 schema document. */
    public function actionSchema()
    {
        $module = Yii::$app->getModule('activitypub');
        $manifest = json_decode((string) @file_get_contents($module->getBasePath() . '/module.json'), true);
        $version = (string) ($manifest['version'] ?? '0.0.0');

        return $this->respondJson([
            'version' => '2.1',
            'software' => [
                'name' => 'humhub-activitypub',
                'version' => $version,
            ],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => false,
            'usage' => [
                'users' => ['total' => 0],
                'localPosts' => 0,
            ],
            'metadata' => (object) [],
        ], 'application/json; charset=utf-8');
    }

    private function respondJson(array $data, string $contentType): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $contentType);
        $response->content = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
