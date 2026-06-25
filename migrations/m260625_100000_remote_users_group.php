<?php

use humhub\components\Migration;
use yii\db\Query;

/**
 * Creates the "Remote Users" group (federated shadow users) and backfills all
 * existing federated users into it, so People can later be filtered local/remote.
 * The group name must match FederatedUserSource::REMOTE_GROUP_NAME.
 */
class m260625_100000_remote_users_group extends Migration
{
    private const GROUP_NAME = 'Remote Users';

    public function safeUp()
    {
        $groupId = (new Query())->select('id')->from('group')->where(['name' => self::GROUP_NAME])->scalar();
        if (!$groupId) {
            $this->insert('group', [
                'name' => self::GROUP_NAME,
                'description' => 'Federated users from other Fediverse servers (e.g. Mastodon).',
                'is_admin_group' => 0,
                'is_default_group' => 0,
                'is_protected' => 0,
                'show_at_registration' => 0,
                'show_at_directory' => 1,
                'sort_order' => 0,
                'notify_users' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $groupId = (int) $this->db->getLastInsertID();
        }

        // Backfill existing federated shadow users (those with a remote actor).
        $userIds = (new Query())->select('user_id')->from('fediverse_remote_actor')->column();
        foreach ($userIds as $uid) {
            $exists = (new Query())->from('group_user')
                ->where(['user_id' => $uid, 'group_id' => $groupId])->exists();
            if (!$exists) {
                $this->insert('group_user', [
                    'user_id' => $uid,
                    'group_id' => $groupId,
                    'is_group_manager' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function safeDown()
    {
        $groupId = (new Query())->select('id')->from('group')->where(['name' => self::GROUP_NAME])->scalar();
        if ($groupId) {
            $this->delete('group_user', ['group_id' => $groupId]);
            $this->delete('group', ['id' => $groupId]);
        }
    }
}
