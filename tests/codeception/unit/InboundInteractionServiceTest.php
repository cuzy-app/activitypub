<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\activitypub\models\FederationObject;
use humhub\modules\activitypub\services\InboundInteractionService;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class InboundInteractionServiceTest extends HumHubDbTestCase
{
    public function testAddRemoteCommentCreatesAuthoredCommentAndMapping()
    {
        $user = User::findOne(1);
        $post = $this->makePost($user, 'thread root 1/');

        $note = ['id' => 'https://remote/notes/2', 'content' => '<p>continuation 2/</p>'];
        (new InboundInteractionService())->addRemoteComment($post->content, $user, $note);

        $comment = Comment::find()->where(['content_id' => $post->content->id])->one();
        $this->assertNotNull($comment, 'a comment should be created on the root content');
        $this->assertEquals($user->id, $comment->created_by);
        $this->assertStringContainsString('continuation 2/', $comment->message);
        $this->assertTrue(
            FederationObject::find()
                ->where(['ap_id' => 'https://remote/notes/2', 'direction' => FederationObject::DIRECTION_REMOTE])
                ->exists(),
            'the comment must be mapped so Update/Delete of this part resolve back to it'
        );
    }

    public function testAddRemoteCommentIsIdempotentByNoteId()
    {
        $user = User::findOne(1);
        $post = $this->makePost($user, 'thread root 1/');
        $note = ['id' => 'https://remote/notes/2', 'content' => '<p>continuation 2/</p>'];

        $svc = new InboundInteractionService();
        $svc->addRemoteComment($post->content, $user, $note);
        $svc->addRemoteComment($post->content, $user, $note);

        $this->assertEquals(1, Comment::find()->where(['content_id' => $post->content->id])->count());
    }

    public function testAddRemoteCommentWithoutIdIsDropped()
    {
        $user = User::findOne(1);
        $post = $this->makePost($user, 'thread root 1/');

        (new InboundInteractionService())->addRemoteComment($post->content, $user, ['content' => '<p>no id</p>']);

        $this->assertEquals(0, Comment::find()->where(['content_id' => $post->content->id])->count());
    }

    private function makePost(User $user, string $message): Post
    {
        $post = new Post($user, Content::VISIBILITY_PUBLIC, ['message' => $message]);
        $post->created_by = $user->id;
        $post->autoFollow = false;
        $post->content->created_by = $user->id;
        $post->silentContentCreation = true;
        $this->assertTrue($post->save());
        return $post;
    }
}
