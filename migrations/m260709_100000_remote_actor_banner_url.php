<?php

use humhub\components\Migration;

class m260709_100000_remote_actor_banner_url extends Migration
{
    public function safeUp()
    {
        $this->addColumn('activitypub_remote_actor', 'banner_url', 'varchar(255) DEFAULT NULL');
    }

    public function safeDown()
    {
        $this->dropColumn('activitypub_remote_actor', 'banner_url');
    }
}
