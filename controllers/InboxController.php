<?php

namespace humhub\modules\activitypub\controllers;

use humhub\components\Controller;
use humhub\modules\activitypub\jobs\ProcessInboxActivity;
use humhub\modules\activitypub\services\DiscoveryPolicy;
use humhub\modules\activitypub\services\FederationUrl;
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

    /** POST /activitypub/inbox (shared inbox) */
    public function actionShared()
    {
        return $this->accept(null);
    }

    /** POST /activitypub/actor/<guid>/inbox (per-user inbox) */
    public function actionUser($guid)
    {
        return $this->accept($guid);
    }

    private function accept(?string $targetGuid): Response
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new BadRequestHttpException('Federation disabled');
        }

        // DoS backstop: cap inbox POSTs per source IP in a fixed 1-minute window
        // (the time bucket in the key makes it reset every minute regardless of
        // load). Generous — Mastodon backs off and retries on 429. Soft backstop
        // only: keyed on getUserIP() (depends on trusted-proxy config) and per
        // egress IP, so it is not a hard DoS control.
        $rlKey = 'activitypub.inbox.rl.' . md5((string) Yii::$app->request->getUserIP()) . '.' . (int) floor(time() / 60);
        $hits = (int) Yii::$app->cache->get($rlKey);
        if ($hits >= 300) {
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->headers->set('Retry-After', '60');
            Yii::$app->response->format = Response::FORMAT_RAW;
            return Yii::$app->response;
        }
        Yii::$app->cache->set($rlKey, $hits + 1, 120);

        $rawBody = Yii::$app->request->getRawBody();
        $signature = Yii::$app->request->headers->get('signature');
        if ($rawBody === '' || $signature === null) {
            throw new BadRequestHttpException('Missing body or signature');
        }

        // Capture ALL request headers (lowercased) so the verifier can reconstruct
        // the signing string for whatever set the remote signed. Respond fast,
        // do the work in the job.
        $headers = [];
        foreach (Yii::$app->request->headers->toArray() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) end($values) : (string) $values;
        }
        // Yii's header collection does not expose some headers under some SAPIs
        // (e.g. Host under FrankenPHP; Content-Type/Length arrive as CONTENT_* server
        // vars), but remotes sign them. Fill them from the server vars, which hold
        // exactly the values the request carried.
        foreach (['host' => 'HTTP_HOST', 'content-type' => 'CONTENT_TYPE', 'content-length' => 'CONTENT_LENGTH'] as $name => $var) {
            if (empty($headers[$name]) && !empty($_SERVER[$var])) {
                $headers[$name] = (string) $_SERVER[$var];
            }
        }

        Yii::$app->queue->push(new ProcessInboxActivity([
            'rawBody' => $rawBody,
            'headers' => $headers,
            'requestPath' => Yii::$app->request->getUrl(),
            // The path the remote signed, from our base URL (a proxy may rewrite the received one).
            'canonicalPath' => FederationUrl::canonicalRequestPath(Yii::$app->request->getPathInfo(), Yii::$app->request->getQueryString()),
            'targetGuid' => $targetGuid,
        ]));

        Yii::$app->response->statusCode = 202; // Accepted
        Yii::$app->response->format = Response::FORMAT_RAW;
        return Yii::$app->response;
    }
}
