<?php

use humhub\components\Migration;

class m260625_110000_activity_log extends Migration
{
    public function safeUp()
    {
        $this->createTable('activitypub_activity_log', [
            'id' => $this->primaryKey(),
            'direction' => $this->string(8)->notNull(),       // 'in' | 'out'
            'activity_type' => $this->string(64)->null(),
            'actor_uri' => $this->string(255)->null(),        // signer (in) or target inbox (out)
            'status' => $this->string(32)->notNull(),         // processed|rejected|delivered|failed_permanent|failed_transient
            'note' => $this->text()->null(),
            'body' => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->null(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex('idx_activity_log_created', 'activitypub_activity_log', 'created_at');
    }

    public function safeDown()
    {
        $this->dropTable('activitypub_activity_log');
    }
}
