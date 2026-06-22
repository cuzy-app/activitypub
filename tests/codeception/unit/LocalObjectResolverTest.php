<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\LocalObjectResolver;
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
