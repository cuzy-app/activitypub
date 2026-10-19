<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\ConfigureForm;
use tests\codeception\_support\HumHubDbTestCase;
use Yii;

class ConfigureFormTest extends HumHubDbTestCase
{
    public function testEnabledRoundtrip()
    {
        $form = new ConfigureForm();
        $form->enabled = true;
        $this->assertTrue($form->save());

        // Fresh form reads the persisted value.
        $reloaded = new ConfigureForm();
        $reloaded->loadSettings();
        $this->assertTrue((bool) $reloaded->enabled);

        // Defaults to false when unset.
        Yii::$app->getModule('activitypub')->settings->delete('enabled');
        $fresh = new ConfigureForm();
        $fresh->loadSettings();
        $this->assertFalse((bool) $fresh->enabled);
    }
}
