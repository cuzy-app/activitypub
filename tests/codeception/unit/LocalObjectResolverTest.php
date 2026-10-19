<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\FederationObject;
use humhub\modules\activitypub\services\LocalObjectResolver;
use tests\codeception\_support\HumHubDbTestCase;

class LocalObjectResolverTest extends HumHubDbTestCase
{
    public function testUnknownApIdResolvesNull()
    {
        $this->assertNull((new LocalObjectResolver())->resolveContent('https://x/notes/does-not-exist'));
    }

    public function testNonLocalObjectResolvesNull()
    {
        $obj = new FederationObject();
        $obj->ap_id = 'https://remote/notes/1';
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Note';
        $obj->save();
        $this->assertNull((new LocalObjectResolver())->resolveContent('https://remote/notes/1'));
    }
}
