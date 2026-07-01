<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\RemoteObjectResolver;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteObjectResolverTest extends HumHubDbTestCase
{
    public function testUnknownApIdResolvesNull()
    {
        $r = new RemoteObjectResolver();
        $this->assertNull($r->resolveRecord('https://remote/notes/nope'));
        $this->assertNull($r->resolveRootContent('https://remote/notes/nope'));
        $this->assertNull($r->resolveRecord(''));
    }

    public function testLocalDirectionIsNotResolvedAsRemote()
    {
        // An object WE published must not be treated as an ingested remote object.
        $obj = new FederationObject();
        $obj->ap_id = 'https://us/notes/1';
        $obj->direction = FederationObject::DIRECTION_LOCAL;
        $obj->object_type = 'Note';
        $obj->model_class = Post::class;
        $obj->model_id = 1;
        $this->assertTrue($obj->save());

        $this->assertNull((new RemoteObjectResolver())->resolveRecord('https://us/notes/1'));
    }

    public function testRemotePostResolvesToRecordAndRootContent()
    {
        $user = User::findOne(1);
        $post = $this->ingestPost($user, 'thread root 1/', 'https://remote/notes/1');

        $r = new RemoteObjectResolver();
        $this->assertInstanceOf(Post::class, $r->resolveRecord('https://remote/notes/1'));
        $this->assertEquals($post->content->id, $r->resolveRootContent('https://remote/notes/1')->id);
    }

    /** Create a Post authored by $user and map a remote AP id onto it (mirrors ProcessInboxActivity::ingestRemotePost). */
    private function ingestPost(User $user, string $message, string $apId): Post
    {
        $post = new Post($user, Content::VISIBILITY_PUBLIC, ['message' => $message]);
        $post->created_by = $user->id;
        $post->autoFollow = false;
        $post->content->created_by = $user->id;
        $post->silentContentCreation = true;
        $this->assertTrue($post->save());

        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Note';
        $obj->model_class = Post::class;
        $obj->model_id = $post->id;
        $this->assertTrue($obj->save());

        return $post;
    }
}
