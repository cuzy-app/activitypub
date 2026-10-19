<?php

use humhub\components\Migration;
use yii\db\Query;

/**
 * Point the new `remoteUserGroupId` setting at the existing "Remote Users" group
 * so the (now configurable) auto-assignment works out of the box. The admin can
 * change the group in the ActivityPub module settings afterwards. Written directly to the
 * setting table (the module is not loadable in the migration console context).
 */
class m260625_120000_remote_user_group_setting extends Migration
{
    public function safeUp()
    {
        $exists = (new Query())->from('setting')
            ->where(['module_id' => 'activitypub', 'name' => 'remoteUserGroupId'])->exists();
        if ($exists) {
            return;
        }
        $groupId = (new Query())->select('id')->from('group')->where(['name' => 'Remote Users'])->scalar();
        if ($groupId) {
            $this->insert('setting', [
                'name' => 'remoteUserGroupId',
                'value' => (string) $groupId,
                'module_id' => 'activitypub',
            ]);
        }
    }

    public function safeDown()
    {
        $this->delete('setting', ['module_id' => 'activitypub', 'name' => 'remoteUserGroupId']);
    }
}
