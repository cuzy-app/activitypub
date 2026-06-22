<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;
use Yii;

class DiscoveryPolicyTest extends HumHubDbTestCase
{
    public function testRequiresGlobalSwitchAndGuestVisibleProfile()
    {
        $module = Yii::$app->getModule('fediverse');

        $user = new User();
        $user->visibility = User::VISIBILITY_ALL;

        $module->settings->set('enabled', false);
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'global off → not discoverable');

        $module->settings->set('enabled', true);
        $this->assertTrue(DiscoveryPolicy::isDiscoverable($user), 'global on + guest-visible → discoverable');

        $user->visibility = User::VISIBILITY_REGISTERED_ONLY;
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'not guest-visible → not discoverable');

        $user->visibility = User::VISIBILITY_HIDDEN;
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'hidden → not discoverable');
    }
}
