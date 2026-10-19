<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\services\RemoteMediaFetcher;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteMediaFetcherTest extends HumHubDbTestCase
{
    public function testRejectsEmptyAndUnsafeUrls()
    {
        $f = new RemoteMediaFetcher();
        $this->assertNull($f->fetchImage(null));
        $this->assertNull($f->fetchImage(''));
        $this->assertNull($f->fetchImage('not-a-url'));
        $this->assertNull($f->fetchImage('http://127.0.0.1/x.png')); // not https / not public
    }

    public function testFilenameForDerivesExtensionFromMime()
    {
        $f = new RemoteMediaFetcher();
        $this->assertSame('avatar.png', $f->filenameFor('https://x.test/path/avatar.png', 'image/png'));
        $this->assertStringEndsWith('.jpg', $f->filenameFor('https://x.test/no-ext', 'image/jpeg'));
    }
}
