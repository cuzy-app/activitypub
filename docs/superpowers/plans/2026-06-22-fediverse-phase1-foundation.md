# Fediverse Phase 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up an installable HumHub `fediverse` module with its database schema, ActiveRecord models, and a global federation on/off admin setting — no protocol behaviour yet.

**Architecture:** A standard HumHub module (`humhub\modules\fediverse`) extending `humhub\components\Module`. Five module-owned tables hold federation state (local actors, remote-actor shadows, AP-object↔record mapping, follow handshake state, delivery log). Core HumHub tables are never altered. A global setting `enabled` gates everything later.

**Tech Stack:** PHP 8, Yii2, HumHub module framework, Codeception (`HumHubDbTestCase`), MySQL/MariaDB.

---

## Phase 1 plan roadmap (this is plan 1 of 5)

1. **Foundation** *(this plan)* — scaffolding, migrations, models, global setting.
2. **Identity & Discovery** — key manager, instance actor, `Person` serialization, WebFinger, Actor & NodeInfo endpoints.
3. **Inbound core** — inbox controller, HTTP-signature verification, remote-actor resolver / federated users, `Follow` → auto-`Accept`.
4. **Outbound** — per-container opt-in accessor + admin toggle, the §5 visibility invariant, serializers for `Create{Note}`/`Update`/`Delete`/`Like`, delivery queue jobs, outbox.
5. **Inbound interactions & local→remote follow** — map inbound `Create{Note,inReplyTo}`/`Like` to native comments/likes; local user follows a remote account.

Spec reference: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§ numbers below refer to it).

---

## Prerequisites

- The module folder is `/home/luke/work/humhub/modules/develop/fediverse` and is loaded by a working HumHub **dev environment** with a configured database. Console entry point is `protected/yii` in the HumHub installation root (referred to below as `$HH`).
- The HumHub **test** database/environment is configured (the same one `HumHubDbTestCase` uses). Unit tests in this plan extend `tests\codeception\_support\HumHubDbTestCase` and therefore need the test DB bootstrap. The Codeception binary is the one shipped with HumHub core (`composer`-installed `vendor/bin/codecept`).
- All commands below are run from the module root `/home/luke/work/humhub/modules/develop/fediverse` unless stated otherwise.

---

## File structure (created by this plan)

```
fediverse/
├── module.json                         # module manifest
├── config.php                          # module config (id/class/namespace, events)
├── Module.php                          # Module class, getConfigUrl()
├── migrations/
│   └── m260622_120000_init_fediverse_tables.php
├── models/
│   ├── LocalActor.php                  # → fediverse_local_actor
│   ├── RemoteActor.php                 # → fediverse_remote_actor
│   ├── FederationObject.php            # → fediverse_object
│   ├── FederationFollow.php            # → fediverse_follow
│   ├── DeliveryLog.php                 # → fediverse_delivery_log
│   └── ConfigureForm.php               # global settings form
├── controllers/
│   └── AdminController.php             # admin config page
├── views/
│   └── admin/
│       └── index.php                   # config form view
├── messages/                           # (translations, created lazily — empty for now)
└── tests/
    ├── codeception.yml
    └── codeception/
        ├── _bootstrap.php
        ├── _support/
        ├── unit.suite.yml
        └── unit/
            ├── LocalActorTest.php
            └── ConfigureFormTest.php
```

Naming conventions locked in here and reused by later plans:

| Table | Model class (`humhub\modules\fediverse\models\…`) |
|---|---|
| `fediverse_local_actor` | `LocalActor` |
| `fediverse_remote_actor` | `RemoteActor` |
| `fediverse_object` | `FederationObject` |
| `fediverse_follow` | `FederationFollow` |
| `fediverse_delivery_log` | `DeliveryLog` |

---

## Task 1: Module scaffolding

**Files:**
- Create: `module.json`
- Create: `Module.php`
- Create: `config.php`

- [ ] **Step 1: Create `module.json`**

```json
{
    "id": "fediverse",
    "name": "Fediverse",
    "description": "Connects this HumHub to the Fediverse (ActivityPub) — Mastodon-compatible federation.",
    "keywords": ["fediverse", "activitypub", "mastodon"],
    "version": "0.1.0",
    "humhub": {
        "minVersion": "1.19"
    }
}
```

- [ ] **Step 2: Create `Module.php`**

```php
<?php

namespace humhub\modules\fediverse;

use yii\helpers\Url;

class Module extends \humhub\components\Module
{
    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/fediverse/admin/index']);
    }
}
```

- [ ] **Step 3: Create `config.php`**

```php
<?php

return [
    'id' => 'fediverse',
    'class' => 'humhub\modules\fediverse\Module',
    'namespace' => 'humhub\modules\fediverse',
    'events' => [
        // Event handlers are registered in later plans (outbound listeners, etc.).
    ],
];
```

- [ ] **Step 4: Enable the module and verify it loads**

Run (from the HumHub install root `$HH`):
```bash
php protected/yii module/enable fediverse
```
Expected: output `Module fediverse enabled.` (or equivalent success) and no error. Re-running is idempotent.

- [ ] **Step 5: Commit**

```bash
git add module.json Module.php config.php
git commit -m "Add fediverse module scaffolding"
```

---

## Task 2: Codeception test harness

This mirrors the layout used by the `polls` module so unit tests can run against the HumHub test DB.

**Files:**
- Create: `tests/codeception.yml`
- Create: `tests/codeception/unit.suite.yml`
- Create: `tests/codeception/_bootstrap.php`
- Create: `tests/codeception/_support/.gitkeep`

- [ ] **Step 1: Create `tests/codeception.yml`**

```yaml
actor: Tester
namespace: fediverse
bootstrap: _bootstrap.php
settings:
    colors: true
    memory_limit: 1024M
paths:
    tests: codeception
    output: codeception/_output
    data: codeception/_data
    helpers: codeception/_support
    envs: ../../../../core/develop/protected/humhub/tests/config/env
```

> Note: the `envs` path points at the HumHub core test env. Adjust the relative path to match this dev environment's core checkout location if it differs (the research found cores under `/home/luke/work/humhub/core/<branch>/protected/humhub`).

- [ ] **Step 2: Create `tests/codeception/unit.suite.yml`**

```yaml
actor: UnitTester
modules:
    enabled:
        - Asserts
        - Yii2
```

- [ ] **Step 3: Create `tests/codeception/_bootstrap.php`**

```php
<?php
// Codeception unit-suite bootstrap. The HumHub test environment is loaded via
// the configured `envs` path; nothing else is needed here.
```

- [ ] **Step 4: Create `tests/codeception/_support/.gitkeep`**

```
```
(empty file, keeps the directory in git)

- [ ] **Step 5: Verify the suite runs (with zero tests)**

Run (from the module root):
```bash
../../../../core/develop/protected/humhub/vendor/bin/codecept run unit -c tests
```
Expected: Codeception starts, reports `OK (0 tests, 0 assertions)` (or "No tests executed"). If the binary path differs in this environment, use the `vendor/bin/codecept` from the active core checkout. Record the working command — later tasks reuse it as `codecept run unit`.

- [ ] **Step 6: Commit**

```bash
git add tests
git commit -m "Add Codeception unit test harness"
```

---

## Task 3: Initial database migration (five tables)

**Files:**
- Create: `migrations/m260622_120000_init_fediverse_tables.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use yii\db\Migration;

class m260622_120000_init_fediverse_tables extends Migration
{
    public function safeUp()
    {
        // --- fediverse_local_actor: a federatable local actor (user or instance) ---
        $this->createTable('fediverse_local_actor', [
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
        $this->createIndex('uq_local_actor_user', 'fediverse_local_actor', 'user_id', true);
        $this->createIndex('uq_local_actor_ap_id', 'fediverse_local_actor', 'ap_id', true);
        $this->createIndex('uq_local_actor_username', 'fediverse_local_actor', 'preferred_username', true);
        $this->addForeignKey('fk_local_actor_user', 'fediverse_local_actor', 'user_id', 'user', 'id', 'CASCADE');

        // --- fediverse_remote_actor: shadow record for a remote actor ---
        $this->createTable('fediverse_remote_actor', [
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
        $this->createIndex('uq_remote_actor_ap_id', 'fediverse_remote_actor', 'ap_id', true);
        $this->createIndex('uq_remote_actor_user', 'fediverse_remote_actor', 'user_id', true);
        $this->createIndex('idx_remote_actor_domain', 'fediverse_remote_actor', 'domain');
        $this->addForeignKey('fk_remote_actor_user', 'fediverse_remote_actor', 'user_id', 'user', 'id', 'CASCADE');

        // --- fediverse_object: AP-object/activity URI ↔ local record mapping ---
        $this->createTable('fediverse_object', [
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
        $this->createIndex('uq_object_ap_id', 'fediverse_object', 'ap_id', true);
        $this->createIndex('idx_object_model', 'fediverse_object', ['model_class', 'model_id']);
        $this->createIndex('idx_object_in_reply_to', 'fediverse_object', 'in_reply_to_ap_id');

        // --- fediverse_follow: AP follow handshake state ---
        $this->createTable('fediverse_follow', [
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
            'fediverse_follow',
            ['local_user_id', 'remote_actor_id', 'direction'],
            true
        );
        $this->addForeignKey('fk_follow_user', 'fediverse_follow', 'local_user_id', 'user', 'id', 'CASCADE');
        $this->addForeignKey('fk_follow_remote_actor', 'fediverse_follow', 'remote_actor_id', 'fediverse_remote_actor', 'id', 'CASCADE');

        // --- fediverse_delivery_log: per-target delivery observability ---
        $this->createTable('fediverse_delivery_log', [
            'id' => $this->primaryKey(),
            'object_id' => $this->integer()->null(),
            'target_inbox' => $this->string(255)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'), // pending|delivered|failed
            'http_code' => $this->integer()->null(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'last_attempt_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('idx_delivery_object', 'fediverse_delivery_log', 'object_id');
        $this->addForeignKey('fk_delivery_object', 'fediverse_delivery_log', 'object_id', 'fediverse_object', 'id', 'SET NULL');
    }

    public function safeDown()
    {
        $this->dropTable('fediverse_delivery_log');
        $this->dropTable('fediverse_follow');
        $this->dropTable('fediverse_object');
        $this->dropTable('fediverse_remote_actor');
        $this->dropTable('fediverse_local_actor');
    }
}
```

- [ ] **Step 2: Apply the migration**

HumHub applies a module's `migrations/` automatically on enable. Re-trigger it:
```bash
php protected/yii module/enable fediverse
```
(Run from `$HH`.) Expected: success, no SQL errors.

- [ ] **Step 3: Verify the tables exist**

Run against the dev DB (use the credentials from `$HH/protected/config/dynamic.php`):
```bash
mysql -u <user> -p<pass> <dbname> -e "SHOW TABLES LIKE 'fediverse_%';"
```
Expected: five rows —
`fediverse_delivery_log`, `fediverse_follow`, `fediverse_local_actor`, `fediverse_object`, `fediverse_remote_actor`.

- [ ] **Step 4: Commit**

```bash
git add migrations/m260622_120000_init_fediverse_tables.php
git commit -m "Add initial fediverse schema migration"
```

---

## Task 4: ActiveRecord models

**Files:**
- Create: `models/LocalActor.php`
- Create: `models/RemoteActor.php`
- Create: `models/FederationObject.php`
- Create: `models/FederationFollow.php`
- Create: `models/DeliveryLog.php`
- Test: `tests/codeception/unit/LocalActorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/LocalActorTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use tests\codeception\_support\HumHubDbTestCase;

class LocalActorTest extends HumHubDbTestCase
{
    public function testInstanceActorRoundtrip()
    {
        $actor = new LocalActor();
        $actor->type = 'Application';
        $actor->preferred_username = 'instance.actor';
        $actor->ap_id = 'https://example.com/fediverse/actor/instance';
        $actor->public_key = 'PUB';
        $actor->private_key = 'PRIV';
        $actor->federation_enabled = true;

        $this->assertTrue($actor->save(), implode(', ', $actor->getErrorSummary(true)));

        $reloaded = LocalActor::findOne(['preferred_username' => 'instance.actor']);
        $this->assertNotNull($reloaded);
        $this->assertSame('Application', $reloaded->type);
        $this->assertNull($reloaded->user_id);
        $this->assertNotEmpty($reloaded->created_at);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from module root, using the command confirmed in Task 2):
```bash
codecept run unit unit/LocalActorTest.php -c tests -v
```
Expected: FAIL — `Class "humhub\modules\fediverse\models\LocalActor" not found`.

- [ ] **Step 3: Write `models/LocalActor.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string $preferred_username
 * @property string $ap_id
 * @property string|null $public_key
 * @property string|null $private_key
 * @property bool $federation_enabled
 * @property int $followers_count
 * @property int $following_count
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class LocalActor extends ActiveRecord
{
    public const TYPE_PERSON = 'Person';
    public const TYPE_APPLICATION = 'Application';

    public static function tableName()
    {
        return 'fediverse_local_actor';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['type', 'preferred_username', 'ap_id'], 'required'],
            [['user_id', 'followers_count', 'following_count'], 'integer'],
            [['federation_enabled'], 'boolean'],
            [['public_key', 'private_key'], 'string'],
            [['type'], 'string', 'max' => 32],
            [['preferred_username', 'ap_id'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
            [['preferred_username'], 'unique'],
            [['user_id'], 'unique'],
        ];
    }
}
```

- [ ] **Step 4: Write `models/RemoteActor.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int $user_id
 * @property string $ap_id
 * @property string $type
 * @property string|null $preferred_username
 * @property string|null $handle
 * @property string $domain
 * @property string $inbox_url
 * @property string|null $shared_inbox_url
 * @property string|null $outbox_url
 * @property string|null $public_key
 * @property string|null $icon_url
 * @property string|null $last_refreshed_at
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class RemoteActor extends ActiveRecord
{
    public static function tableName()
    {
        return 'fediverse_remote_actor';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['user_id', 'ap_id', 'type', 'domain', 'inbox_url'], 'required'],
            [['user_id'], 'integer'],
            [['public_key'], 'string'],
            [['type'], 'string', 'max' => 32],
            [['ap_id', 'preferred_username', 'handle', 'domain', 'inbox_url', 'shared_inbox_url', 'outbox_url', 'icon_url'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
            [['user_id'], 'unique'],
        ];
    }
}
```

- [ ] **Step 5: Write `models/FederationObject.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property string $ap_id
 * @property string $direction
 * @property string $object_type
 * @property string|null $model_class
 * @property int|null $model_id
 * @property string|null $in_reply_to_ap_id
 * @property string|null $raw_json
 * @property string|null $created_at
 */
class FederationObject extends ActiveRecord
{
    public const DIRECTION_LOCAL = 'local';
    public const DIRECTION_REMOTE = 'remote';

    public static function tableName()
    {
        return 'fediverse_object';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['ap_id', 'direction', 'object_type'], 'required'],
            [['model_id'], 'integer'],
            [['raw_json'], 'string'],
            [['direction'], 'string', 'max' => 16],
            [['direction'], 'in', 'range' => [self::DIRECTION_LOCAL, self::DIRECTION_REMOTE]],
            [['object_type'], 'string', 'max' => 32],
            [['ap_id', 'model_class', 'in_reply_to_ap_id'], 'string', 'max' => 255],
            [['ap_id'], 'unique'],
        ];
    }
}
```

- [ ] **Step 6: Write `models/FederationFollow.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int $local_user_id
 * @property int $remote_actor_id
 * @property string $direction
 * @property string $state
 * @property string|null $activity_ap_id
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class FederationFollow extends ActiveRecord
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATE_PENDING = 'pending';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REJECTED = 'rejected';

    public static function tableName()
    {
        return 'fediverse_follow';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['local_user_id', 'remote_actor_id', 'direction'], 'required'],
            [['local_user_id', 'remote_actor_id'], 'integer'],
            [['direction'], 'in', 'range' => [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND]],
            [['state'], 'in', 'range' => [self::STATE_PENDING, self::STATE_ACCEPTED, self::STATE_REJECTED]],
            [['state'], 'default', 'value' => self::STATE_PENDING],
            [['activity_ap_id'], 'string', 'max' => 255],
        ];
    }
}
```

- [ ] **Step 7: Write `models/DeliveryLog.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * @property int $id
 * @property int|null $object_id
 * @property string $target_inbox
 * @property string $status
 * @property int|null $http_code
 * @property int $attempts
 * @property string|null $last_attempt_at
 * @property string|null $created_at
 */
class DeliveryLog extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public static function tableName()
    {
        return 'fediverse_delivery_log';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules()
    {
        return [
            [['target_inbox'], 'required'],
            [['object_id', 'http_code', 'attempts'], 'integer'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_DELIVERED, self::STATUS_FAILED]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['attempts'], 'default', 'value' => 0],
            [['target_inbox'], 'string', 'max' => 255],
        ];
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

```bash
codecept run unit unit/LocalActorTest.php -c tests -v
```
Expected: PASS (1 test, ≥4 assertions).

- [ ] **Step 9: Commit**

```bash
git add models tests/codeception/unit/LocalActorTest.php
git commit -m "Add fediverse ActiveRecord models"
```

---

## Task 5: Global federation setting + admin config page

Implements §5's "global federation switch" and surfaces a Guest-Mode prerequisite warning.

**Files:**
- Create: `models/ConfigureForm.php`
- Create: `controllers/AdminController.php`
- Create: `views/admin/index.php`
- Test: `tests/codeception/unit/ConfigureFormTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/ConfigureFormTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\ConfigureForm;
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
        Yii::$app->getModule('fediverse')->settings->delete('enabled');
        $fresh = new ConfigureForm();
        $fresh->loadSettings();
        $this->assertFalse((bool) $fresh->enabled);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
codecept run unit unit/ConfigureFormTest.php -c tests -v
```
Expected: FAIL — `Class "humhub\modules\fediverse\models\ConfigureForm" not found`.

- [ ] **Step 3: Write `models/ConfigureForm.php`**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\modules\user\helpers\AuthHelper;
use Yii;
use yii\base\Model;

class ConfigureForm extends Model
{
    /** @var bool global on/off switch for all federation */
    public $enabled = false;

    public function rules()
    {
        return [
            [['enabled'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'enabled' => Yii::t('FediverseModule.base', 'Enable federation for this site'),
        ];
    }

    public function loadSettings(): self
    {
        $this->enabled = (bool) $this->getModule()->settings->get('enabled', false);
        return $this;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->getModule()->settings->set('enabled', (bool) $this->enabled);
        return true;
    }

    /**
     * Federation can only ever publish guest-visible content, so guest access
     * must be enabled site-wide (spec §5). Used by the view to warn the admin.
     */
    public function isGuestAccessEnabled(): bool
    {
        return AuthHelper::isGuestAccessEnabled();
    }

    private function getModule(): \humhub\components\Module
    {
        return Yii::$app->getModule('fediverse');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
codecept run unit unit/ConfigureFormTest.php -c tests -v
```
Expected: PASS.

- [ ] **Step 5: Write `controllers/AdminController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\fediverse\models\ConfigureForm;
use Yii;

class AdminController extends Controller
{
    public function actionIndex()
    {
        $model = new ConfigureForm();
        $model->loadSettings();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->saved();
            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'model' => $model,
        ]);
    }
}
```

- [ ] **Step 6: Write `views/admin/index.php`**

```php
<?php

use humhub\widgets\bootstrap\Button;
use humhub\widgets\form\ActiveForm;
use humhub\modules\fediverse\models\ConfigureForm;

/* @var $this \humhub\components\View */
/* @var $model ConfigureForm */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Fediverse'); ?></div>
    <div class="panel-body">
        <?php if (!$model->isGuestAccessEnabled()) : ?>
            <div class="alert alert-warning">
                <?= Yii::t('FediverseModule.base', 'Guest access is currently disabled. Federation only publishes content that is visible to guests, so nothing will be federated until you enable guest access in the user settings.'); ?>
            </div>
        <?php endif; ?>

        <?php $form = ActiveForm::begin(); ?>
            <?= $form->field($model, 'enabled')->checkbox(); ?>
            <?= Button::save()->submit(); ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
```

- [ ] **Step 7: Verify the config page renders**

In a browser, open the HumHub admin and navigate to the Fediverse module's configure page (Administration → Modules → Fediverse → Configure, which links to `/fediverse/admin/index`). Toggle "Enable federation", save, and confirm the success flash appears and the checkbox state persists after reload. Also confirm the guest-access warning shows when guest access is off.

- [ ] **Step 8: Commit**

```bash
git add models/ConfigureForm.php controllers/AdminController.php views/admin/index.php tests/codeception/unit/ConfigureFormTest.php
git commit -m "Add global federation setting and admin config page"
```

---

## Done criteria for this plan

- The `fediverse` module enables cleanly in HumHub and its five tables exist.
- All five models load and persist (`LocalActorTest` green).
- The global federation switch saves and reloads (`ConfigureFormTest` green), with a guest-access prerequisite warning in the admin UI.
- No HumHub core table was modified.

Next: **Plan 2 — Identity & Discovery** (key manager, instance actor, `Person` serialization, WebFinger, Actor & NodeInfo endpoints).
