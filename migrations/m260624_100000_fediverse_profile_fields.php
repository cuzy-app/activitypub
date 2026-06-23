<?php

use humhub\components\Migration;
use yii\db\Query;

class m260624_100000_fediverse_profile_fields extends Migration
{
    public function safeUp()
    {
        // 1. Category
        $categoryId = (new Query())->select('id')->from('profile_field_category')
            ->where(['title' => 'Fediverse'])->scalar();
        if (!$categoryId) {
            $this->insert('profile_field_category', [
                'title' => 'Fediverse',
                'description' => '',
                'sort_order' => 400,
                'visibility' => 1,
                'is_system' => 1,
            ]);
            $categoryId = (new Query())->select('id')->from('profile_field_category')
                ->where(['title' => 'Fediverse'])->scalar();
        }

        // 2. Fields + columns
        $fields = [
            'fediverse_handle' => ['title' => 'Fediverse Handle', 'sort_order' => 410],
            'fediverse_displayname' => ['title' => 'Fediverse Display Name', 'sort_order' => 420],
        ];
        foreach ($fields as $name => $cfg) {
            $exists = (new Query())->from('profile_field')->where(['internal_name' => $name])->exists();
            if (!$exists) {
                $this->insert('profile_field', [
                    'profile_field_category_id' => $categoryId,
                    'field_type_class' => 'humhub\\modules\\user\\models\\fieldtype\\Text',
                    'internal_name' => $name,
                    'title' => $cfg['title'],
                    'sort_order' => $cfg['sort_order'],
                    'required' => 0,
                    'show_at_registration' => 0,
                    'editable' => 0,   // protected: system-managed, not user-editable
                    'visible' => 1,
                    'is_system' => 1,
                ]);
            }
            if (!in_array($name, $this->db->getTableSchema('profile')->columnNames, true)) {
                $this->addColumn('profile', $name, 'varchar(255) DEFAULT NULL');
            }
        }
    }

    public function safeDown()
    {
        foreach (['fediverse_handle', 'fediverse_displayname'] as $name) {
            $this->delete('profile_field', ['internal_name' => $name]);
            if (in_array($name, $this->db->getTableSchema('profile')->columnNames, true)) {
                $this->dropColumn('profile', $name);
            }
        }
        $this->delete('profile_field_category', ['title' => 'Fediverse']);
        return true;
    }
}
