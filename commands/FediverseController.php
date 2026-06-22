<?php

namespace humhub\modules\fediverse\commands;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use yii\console\Controller;
use yii\console\ExitCode;

class FediverseController extends Controller
{
    /** Usage: php protected/yii fediverse/status */
    public function actionStatus(): int
    {
        $this->stdout('Federation enabled: ' . (DiscoveryPolicy::isGloballyEnabled() ? 'yes' : 'no') . "\n");
        $this->stdout('Domain: ' . FederationUrl::domain() . "\n");
        $this->stdout('Instance actor: ' . FederationUrl::instanceActorUrl() . "\n");
        $this->stdout('Local actors: ' . LocalActor::find()->count() . "\n");
        return ExitCode::OK;
    }

    /** Usage: php protected/yii fediverse/ensure-instance-actor */
    public function actionEnsureInstanceActor(): int
    {
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $this->stdout('Instance actor ready: ' . $actor->ap_id . "\n");
        return ExitCode::OK;
    }
}
