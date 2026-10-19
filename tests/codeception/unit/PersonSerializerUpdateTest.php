<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\services\PersonSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class PersonSerializerUpdateTest extends HumHubDbTestCase
{
    public function testUpdateActivityWrapsDocument()
    {
        $actor = new LocalActor();
        $actor->ap_id = 'https://test.fedihub.social/activitypub/actor/abc';

        $doc = ['id' => $actor->ap_id, 'type' => 'Person', 'name' => 'New Name'];
        $activity = (new PersonSerializer())->updateActivity($actor, $doc, $actor->ap_id . '#updates/xyz');

        $this->assertSame('Update', $activity['type']);
        $this->assertSame($actor->ap_id, $activity['actor']);
        $this->assertSame($actor->ap_id . '#updates/xyz', $activity['id']);
        $this->assertSame($doc, $activity['object']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $activity['to']);
        $this->assertContains('https://www.w3.org/ns/activitystreams', $activity['@context']);
    }

    public function testSerializeIncludesSummaryAndIconWhenProvided()
    {
        $actor = new LocalActor();
        $actor->ap_id = 'https://test.fedihub.social/activitypub/actor/abc';
        $actor->type = 'Person';
        $actor->preferred_username = 'abc';
        $actor->public_key = 'KEY';

        $icon = ['type' => 'Image', 'url' => 'https://test.fedihub.social/a.jpg'];
        $doc = (new PersonSerializer())->serialize($actor, 'Name', 'https://test.fedihub.social/u/abc', '<p>bio</p>', $icon);
        $this->assertSame('<p>bio</p>', $doc['summary']);
        $this->assertSame($icon, $doc['icon']);

        $bare = (new PersonSerializer())->serialize($actor, 'Name', 'https://test.fedihub.social/u/abc');
        $this->assertArrayNotHasKey('summary', $bare);
        $this->assertArrayNotHasKey('icon', $bare);
    }
}
