<?php

use humhub\components\Migration;

class m260624_130000_remote_actor_counts extends Migration
{
    public function safeUp()
    {
        $this->addColumn('fediverse_remote_actor', 'profile_url', 'varchar(255) DEFAULT NULL');
        $this->addColumn('fediverse_remote_actor', 'followers_count', 'integer DEFAULT NULL');
        $this->addColumn('fediverse_remote_actor', 'following_count', 'integer DEFAULT NULL');
    }

    public function safeDown()
    {
        $this->dropColumn('fediverse_remote_actor', 'profile_url');
        $this->dropColumn('fediverse_remote_actor', 'followers_count');
        $this->dropColumn('fediverse_remote_actor', 'following_count');
    }
}
