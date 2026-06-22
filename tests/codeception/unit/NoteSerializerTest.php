<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\NoteSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class NoteSerializerTest extends HumHubDbTestCase
{
    public function testNoteAndCreateShape()
    {
        $actorUrl = 'https://test.fedihub.social/fediverse/actor/guid-1';
        $s = new NoteSerializer();

        $note = $s->note(
            $actorUrl,
            $actorUrl . '/notes/42',
            '<p>Hello Fediverse</p>',
            '2026-06-22T18:00:00Z',
            'https://test.fedihub.social/u/alice/',
            []
        );
        $this->assertSame('Note', $note['type']);
        $this->assertSame($actorUrl, $note['attributedTo']);
        $this->assertSame('<p>Hello Fediverse</p>', $note['content']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $note['to']);
        $this->assertContains($actorUrl . '/followers', $note['cc']);

        $create = $s->wrap('Create', $actorUrl, $note, $note['id'] . '/activity');
        $this->assertSame('Create', $create['type']);
        $this->assertSame($actorUrl, $create['actor']);
        $this->assertSame($note, $create['object']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $create['to']);

        $del = $s->delete($actorUrl, $note['id'], $note['id'] . '/delete');
        $this->assertSame('Delete', $del['type']);
        $this->assertSame('Tombstone', $del['object']['type']);
        $this->assertSame($note['id'], $del['object']['id']);
    }
}
