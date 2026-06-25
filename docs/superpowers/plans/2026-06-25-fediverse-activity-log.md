# Fediverse — federation activity log (debug)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** An opt-in, capped log of inbound/outbound federation activities (raw JSON + outcome) so an admin can inspect what was received/sent — e.g. to debug a mis-parsed text. Off by default (verbose + privacy-sensitive).

**Pieces:** a `fediverse_activity_log` table + model, an `ActivityLog::record()` service gated on a new `activityLogEnabled` setting (with retention cap), inbound/outbound logging hooks, and an admin toggle + log viewer.

---

## Task 1: table + model
**Create** `migrations/m260625_110000_activity_log.php`:
```php
<?php

use humhub\components\Migration;

class m260625_110000_activity_log extends Migration
{
    public function safeUp()
    {
        $this->createTable('fediverse_activity_log', [
            'id' => $this->primaryKey(),
            'direction' => $this->string(8)->notNull(),       // 'in' | 'out'
            'activity_type' => $this->string(64)->null(),
            'actor_uri' => $this->string(255)->null(),        // signer (in) or target inbox (out)
            'status' => $this->string(32)->notNull(),         // processed|rejected|delivered|failed_permanent|failed_transient
            'note' => $this->text()->null(),
            'body' => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->null(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex('idx_activity_log_created', 'fediverse_activity_log', 'created_at');
    }

    public function safeDown()
    {
        $this->dropTable('fediverse_activity_log');
    }
}
```

**Create** `models/ActivityLogEntry.php`:
```php
<?php

namespace humhub\modules\fediverse\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $direction
 * @property string|null $activity_type
 * @property string|null $actor_uri
 * @property string $status
 * @property string|null $note
 * @property string|null $body
 * @property string $created_at
 */
class ActivityLogEntry extends ActiveRecord
{
    public static function tableName()
    {
        return 'fediverse_activity_log';
    }
}
```

## Task 2: ActivityLog service
**Create** `services/ActivityLog.php`:
```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\ActivityLogEntry;
use Yii;

/** Opt-in, capped logging of federation activities for debugging. Never throws. */
class ActivityLog
{
    public const MAX_ENTRIES = 1000;

    public static function isEnabled(): bool
    {
        return (bool) Yii::$app->getModule('fediverse')->settings->get('activityLogEnabled', false);
    }

    public static function record(string $direction, ?string $type, ?string $actor, ?string $body, string $status, ?string $note = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $e = new ActivityLogEntry();
            $e->direction = $direction;
            $e->activity_type = $type !== null ? mb_substr($type, 0, 64) : null;
            $e->actor_uri = $actor !== null ? mb_substr($actor, 0, 255) : null;
            $e->status = mb_substr($status, 0, 32);
            $e->note = $note;
            $e->body = $body;
            $e->created_at = date('Y-m-d H:i:s');
            $e->save(false);
            // Amortised retention: trim to the most recent MAX_ENTRIES every ~50 inserts.
            if ($e->id !== null && ($e->id % 50) === 0) {
                ActivityLogEntry::deleteAll(['<', 'id', $e->id - self::MAX_ENTRIES]);
            }
        } catch (\Throwable $ex) {
            Yii::warning('Fediverse activity log failed: ' . $ex->getMessage(), 'fediverse');
        }
    }
}
```

## Task 3: setting (ConfigureForm)
**Modify** `models/ConfigureForm.php`: add `public $activityLogEnabled = false;`, a `boolean` rule, a label ("Log federation activity (debug)"), and load/save it via `settings->get/set('activityLogEnabled', ...)`.

## Task 4: logging hooks
**Modify** `jobs/ProcessInboxActivity.php` `run()`:
- on auth failure (signerDoc null): `ActivityLog::record('in', $activity['type'] ?? null, $actorUrl, $this->rawBody, 'rejected', 'signature/verification failed');` before the existing `return`.
- after the `switch`: `ActivityLog::record('in', $activity['type'], $actorUrl, $this->rawBody, 'processed');`
- add `use humhub\modules\fediverse\services\ActivityLog;`

**Modify** `jobs/DeliverToInbox.php` `run()` — record the outcome (type from the decoded activity, target = inbox):
- 2xx → `ActivityLog::record('out', $activity['type'] ?? null, $this->inboxUrl, $this->activityJson, 'delivered');`
- 4xx → `ActivityLog::record('out', $activity['type'] ?? null, $this->inboxUrl, $this->activityJson, 'failed_permanent', (string) $status);`
- transient (before throw) → `ActivityLog::record('out', $activity['type'] ?? null, $this->inboxUrl, $this->activityJson, 'failed_transient', (string) $status);`
- add the `use`.

## Task 5: admin toggle + log viewer
**Modify** `views/admin/index.php`: add the `activityLogEnabled` checkbox to the form, and below the panel a link/button to the log viewer (`['/fediverse/admin/activity-log']`).

**Modify** `controllers/AdminController.php`: add
```php
    public function actionActivityLog()
    {
        if (Yii::$app->request->isPost && Yii::$app->request->post('clear')) {
            ActivityLogEntry::deleteAll();
            $this->view->success(Yii::t('FediverseModule.base', 'Log cleared.'));
            return $this->redirect(['activity-log']);
        }
        $entries = ActivityLogEntry::find()->orderBy(['id' => SORT_DESC])->limit(100)->all();
        return $this->render('activity-log', ['entries' => $entries]);
    }
```
(import `ActivityLogEntry`.)

**Create** `views/admin/activity-log.php`: a panel with a table (time, direction, type, actor, status, note) where each row's raw `body` is in a collapsible `<details><pre>` (HTML-encoded), a "Clear log" POST button, and a hint if the log is disabled. Wrap body display so long JSON scrolls (`overflow:auto`).

## Task 6: CHANGELOG
```markdown
- Enh: An opt-in "federation activity log" (admin setting, off by default) records
  recent inbound/outbound activities (raw JSON + outcome) to a capped table, with
  an admin viewer — useful for debugging e.g. mis-parsed remote content.
```

---

## Deploy + verification (controller + human)
1. Run migration; `php -l` all; flush cache.
2. (controller) Enable the setting; trigger an inbound (e.g. a like from Mastodon) + an outbound (a post) → confirm rows appear with the raw JSON and correct status; disable → no new rows. Confirm retention trims beyond MAX_ENTRIES.
3. **Admin:** Administration → Fediverse → toggle "Log federation activity", open the log viewer, expand a row to see the raw JSON, clear the log.

## Self-review risks
- **Off by default**; `record()` no-ops + never throws (logging must not break federation).
- **Retention** trims to MAX_ENTRIES (amortised) so the table can't grow unbounded.
- **Privacy/size:** raw bodies can be large/sensitive → opt-in, capped, admin-only, clearable.
- **body column** is `mediumtext` (activities can exceed `text` rarely, but mediumtext is safe).
