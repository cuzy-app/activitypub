<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\jobs\ProcessInboxActivity;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class InboxController extends Controller
{
    /** Public, unauthenticated, machine-to-machine endpoint. */
    public $access = null;
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** POST /fediverse/inbox (shared inbox) */
    public function actionShared()
    {
        return $this->accept(null);
    }

    /** POST /fediverse/actor/<guid>/inbox (per-user inbox) */
    public function actionUser($guid)
    {
        return $this->accept($guid);
    }

    private function accept(?string $targetGuid): Response
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new BadRequestHttpException('Federation disabled');
        }

        $rawBody = Yii::$app->request->getRawBody();
        $signature = Yii::$app->request->headers->get('signature');
        if ($rawBody === '' || $signature === null) {
            throw new BadRequestHttpException('Missing body or signature');
        }

        // Capture only the headers the verifier needs; respond fast, do work in the job.
        $headers = [
            'signature' => $signature,
            'date' => (string) Yii::$app->request->headers->get('date'),
            'digest' => (string) Yii::$app->request->headers->get('digest'),
            'host' => (string) Yii::$app->request->headers->get('host'),
            'content-type' => (string) Yii::$app->request->headers->get('content-type'),
        ];

        Yii::$app->queue->push(new ProcessInboxActivity([
            'rawBody' => $rawBody,
            'headers' => $headers,
            'requestPath' => Yii::$app->request->getUrl(),
            'targetGuid' => $targetGuid,
        ]));

        Yii::$app->response->statusCode = 202; // Accepted
        Yii::$app->response->format = Response::FORMAT_RAW;
        return Yii::$app->response;
    }
}
