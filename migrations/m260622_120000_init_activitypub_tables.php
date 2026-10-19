<?php

use yii\db\Migration;

class m260622_120000_init_activitypub_tables extends Migration
{
    public function safeUp()
    {
        // --- activitypub_local_actor: a federatable local actor (user or instance) ---
        $this->createTable('activitypub_local_actor', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->null(),            // null = instance actor
            'type' => $this->string(32)->notNull(),           // 'Person' | 'Application'
            'preferred_username' => $this->string(255)->notNull(),
            'ap_id' => $this->string(255)->notNull(),         // actor URL
            'public_key' => $this->text()->null(),
            'private_key' => $this->text()->null(),           // encrypted at rest (plan 2)
            'federation_enabled' => $this->boolean()->notNull()->defaultValue(false),
            'followers_count' => $this->integer()->notNull()->defaultValue(0),
            'following_count' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->dateTime()->null(),
            'updated_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('uq_local_actor_user', 'activitypub_local_actor', 'user_id', true);
        $this->createIndex('uq_local_actor_ap_id', 'activitypub_local_actor', 'ap_id', true);
        $this->createIndex('uq_local_actor_username', 'activitypub_local_actor', 'preferred_username', true);
        $this->addForeignKey('fk_local_actor_user', 'activitypub_local_actor', 'user_id', 'user', 'id', 'CASCADE');

        // --- activitypub_remote_actor: shadow record for a remote actor ---
        $this->createTable('activitypub_remote_actor', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),         // the federated shadow user
            'ap_id' => $this->string(255)->notNull(),
            'type' => $this->string(32)->notNull(),           // 'Person' (phase 1)
            'preferred_username' => $this->string(255)->null(),
            'handle' => $this->string(255)->null(),           // user@host
            'domain' => $this->string(255)->notNull(),
            'inbox_url' => $this->string(255)->notNull(),
            'shared_inbox_url' => $this->string(255)->null(),
            'outbox_url' => $this->string(255)->null(),
            'public_key' => $this->text()->null(),
            'icon_url' => $this->string(255)->null(),
            'last_refreshed_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->null(),
            'updated_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('uq_remote_actor_ap_id', 'activitypub_remote_actor', 'ap_id', true);
        $this->createIndex('uq_remote_actor_user', 'activitypub_remote_actor', 'user_id', true);
        $this->createIndex('idx_remote_actor_domain', 'activitypub_remote_actor', 'domain');
        $this->addForeignKey('fk_remote_actor_user', 'activitypub_remote_actor', 'user_id', 'user', 'id', 'CASCADE');

        // --- activitypub_object: AP-object/activity URI ↔ local record mapping ---
        $this->createTable('activitypub_object', [
            'id' => $this->primaryKey(),
            'ap_id' => $this->string(255)->notNull(),
            'direction' => $this->string(16)->notNull(),      // 'local' | 'remote'
            'object_type' => $this->string(32)->notNull(),    // 'Note','Like','Follow','Create',...
            'model_class' => $this->string(255)->null(),
            'model_id' => $this->integer()->null(),
            'in_reply_to_ap_id' => $this->string(255)->null(),
            'raw_json' => $this->text()->null(),
            'created_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('uq_object_ap_id', 'activitypub_object', 'ap_id', true);
        $this->createIndex('idx_object_model', 'activitypub_object', ['model_class', 'model_id']);
        $this->createIndex('idx_object_in_reply_to', 'activitypub_object', 'in_reply_to_ap_id');

        // --- activitypub_follow: AP follow handshake state ---
        $this->createTable('activitypub_follow', [
            'id' => $this->primaryKey(),
            'local_user_id' => $this->integer()->notNull(),
            'remote_actor_id' => $this->integer()->notNull(),
            'direction' => $this->string(16)->notNull(),      // 'inbound' | 'outbound'
            'state' => $this->string(16)->notNull()->defaultValue('pending'), // pending|accepted|rejected
            'activity_ap_id' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->null(),
            'updated_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex(
            'uq_follow_unique',
            'activitypub_follow',
            ['local_user_id', 'remote_actor_id', 'direction'],
            true
        );
        $this->addForeignKey('fk_follow_user', 'activitypub_follow', 'local_user_id', 'user', 'id', 'CASCADE');
        $this->addForeignKey('fk_follow_remote_actor', 'activitypub_follow', 'remote_actor_id', 'activitypub_remote_actor', 'id', 'CASCADE');

        // --- activitypub_delivery_log: per-target delivery observability ---
        $this->createTable('activitypub_delivery_log', [
            'id' => $this->primaryKey(),
            'object_id' => $this->integer()->null(),
            'target_inbox' => $this->string(255)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'), // pending|delivered|failed
            'http_code' => $this->integer()->null(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'last_attempt_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('idx_delivery_object', 'activitypub_delivery_log', 'object_id');
        $this->addForeignKey('fk_delivery_object', 'activitypub_delivery_log', 'object_id', 'activitypub_object', 'id', 'SET NULL');
    }

    public function safeDown()
    {
        $this->dropTable('activitypub_delivery_log');
        $this->dropTable('activitypub_follow');
        $this->dropTable('activitypub_object');
        $this->dropTable('activitypub_remote_actor');
        $this->dropTable('activitypub_local_actor');
    }
}
