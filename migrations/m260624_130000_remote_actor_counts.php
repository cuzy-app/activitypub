<?php

use humhub\components\Migration;

class m260624_130000_remote_actor_counts extends Migration
{
    public function safeUp()
    {
        $this->addColumn('activitypub_remote_actor', 'profile_url', 'varchar(255) DEFAULT NULL');
        $this->addColumn('activitypub_remote_actor', 'followers_count', 'integer DEFAULT NULL');
        $this->addColumn('activitypub_remote_actor', 'following_count', 'integer DEFAULT NULL');
    }

    public function safeDown()
    {
        $this->dropColumn('activitypub_remote_actor', 'profile_url');
        $this->dropColumn('activitypub_remote_actor', 'followers_count');
        $this->dropColumn('activitypub_remote_actor', 'following_count');
    }
}
