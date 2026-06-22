<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class FederationContentPolicyTest extends HumHubDbTestCase
{
    public function testContainerOptInRoundTrip()
    {
        // A fresh container is not opted in; setting the flag flips it.
        $user = User::findOne(['username' => 'User1']) ?? User::find()->one();
        $this->assertNotNull($user, 'a test user fixture is required');

        $this->assertFalse(FederationContentPolicy::isContainerOptedIn($user));
        Yii::$app->getModule('fediverse')->settings->contentContainer($user)->set('federationEnabled', 1);
        $this->assertTrue(FederationContentPolicy::isContainerOptedIn($user));
        Yii::$app->getModule('fediverse')->settings->contentContainer($user)->set('federationEnabled', 0);
        $this->assertFalse(FederationContentPolicy::isContainerOptedIn($user));
    }
}
