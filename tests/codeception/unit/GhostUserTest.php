<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\services\GhostUser;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class GhostUserTest extends HumHubDbTestCase
{
    public function testGetOrCreateIsIdempotentAndStoredInSetting()
    {
        $u1 = GhostUser::getOrCreate();
        $this->assertInstanceOf(User::class, $u1);
        $this->assertSame((int) $u1->id, (int) \Yii::$app->getModule('activitypub')->settings->get('ghostUserId'));

        $u2 = GhostUser::getOrCreate();
        $this->assertSame($u1->id, $u2->id); // no duplicate
    }
}
