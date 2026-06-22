<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\FederationUrl;
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
        return $this->asJson([
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
        $module = Yii::$app->getModule('fediverse');
        $manifest = json_decode((string) @file_get_contents($module->getBasePath() . '/module.json'), true);
        $version = (string) ($manifest['version'] ?? '0.0.0');

        return $this->asJson([
            'version' => '2.1',
            'software' => [
                'name' => 'humhub-fediverse',
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

    private function asJson(array $data, string $contentType): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->headers->set('Content-Type', $contentType);
        $response->data = $data;
        return $response;
    }
}
