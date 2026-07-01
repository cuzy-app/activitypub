<?php

use yii\db\Migration;

/**
 * Space-backed Group actors (fediverse_local_actor.space_id) and the
 * Space↔remote-community coupling table for inbound Lemmy federation.
 */
class m260701_120000_fediverse_space_community extends Migration
{
    public function safeUp()
    {
        // A local actor may now belong to a Space (Group), a User (Person),
        // or the instance (Application). space_id is null for the latter two.
        $this->addColumn('fediverse_local_actor', 'space_id', $this->integer()->null()->after('user_id'));
        $this->createIndex('uq_local_actor_space', 'fediverse_local_actor', 'space_id', true);
        $this->addForeignKey('fk_local_actor_space', 'fediverse_local_actor', 'space_id', 'space', 'id', 'CASCADE');

        // One row per federated Space; the community's AP identity + delivery targets.
        $this->createTable('fediverse_space_community', [
            'id' => $this->primaryKey(),
            'space_id' => $this->integer()->notNull(),
            'community_ap_id' => $this->string(255)->notNull(),   // the Group actor id (routing key)
            'handle' => $this->string(255)->null(),                // !name@host / name@host
            'preferred_username' => $this->string(255)->null(),
            'name' => $this->string(255)->null(),                  // community display name
            'summary' => $this->text()->null(),                   // community description (HTML)
            'icon_url' => $this->string(255)->null(),             // avatar
            'image_url' => $this->string(255)->null(),            // banner
            'inbox_url' => $this->string(255)->notNull(),
            'shared_inbox_url' => $this->string(255)->null(),
            'followers_url' => $this->string(255)->null(),
            'outbox_url' => $this->string(255)->null(),
            'follow_state' => $this->string(16)->notNull()->defaultValue('pending'), // pending|accepted|rejected
            'follow_activity_ap_id' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->null(),
            'updated_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('uq_space_community_space', 'fediverse_space_community', 'space_id', true);
        $this->createIndex('uq_space_community_ap_id', 'fediverse_space_community', 'community_ap_id', true);
        $this->addForeignKey('fk_space_community_space', 'fediverse_space_community', 'space_id', 'space', 'id', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('fediverse_space_community');
        $this->dropForeignKey('fk_local_actor_space', 'fediverse_local_actor');
        $this->dropIndex('uq_local_actor_space', 'fediverse_local_actor');
        $this->dropColumn('fediverse_local_actor', 'space_id');
    }
}
