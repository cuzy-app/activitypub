<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\PersonSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class PersonSerializerUpdateTest extends HumHubDbTestCase
{
    public function testUpdateActivityWrapsDocument()
    {
        $actor = new LocalActor();
        $actor->ap_id = 'https://test.fedihub.social/fediverse/actor/abc';

        $doc = ['id' => $actor->ap_id, 'type' => 'Person', 'name' => 'New Name'];
        $activity = (new PersonSerializer())->updateActivity($actor, $doc, $actor->ap_id . '#updates/xyz');

        $this->assertSame('Update', $activity['type']);
        $this->assertSame($actor->ap_id, $activity['actor']);
        $this->assertSame($actor->ap_id . '#updates/xyz', $activity['id']);
        $this->assertSame($doc, $activity['object']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $activity['to']);
        $this->assertContains('https://www.w3.org/ns/activitystreams', $activity['@context']);
    }
}
