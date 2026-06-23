# Fediverse — Display-Name Sync (`Update{Person}`)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a local HumHub user changes their display name (first/last name), federate the change to their remote followers via a signed `Update{Person}` activity so Mastodon refreshes the cached display name.

**Architecture:** Hook `humhub\modules\user\models\Profile` `afterUpdate`. If `firstname`/`lastname` changed and the user is federatable (not a shadow user, discoverable, has a `LocalActor`) and has accepted inbound followers, build the actor document with the existing `PersonSerializer` (identical to the `/actor` endpoint, `name = displayName`), wrap it in an `Update` activity, and fan it out to followers via the existing `DeliverActivity` job (shared-inbox de-duplicated).

**Tech Stack:** PHP 8, Yii2, HumHub Profile AR events, `PersonSerializer` + `DeliverActivity` (reused), Codeception. One-directional (HumHub → Fediverse). Live-tested against real Mastodon.

---

## Scope

- **In:** display-name change (first/last name) on a federatable local user with inbound followers → `Update{Person}` to those followers.
- **Out (→ later, "Pflege" phase):** bio/`summary` and avatar/`icon` sync (the actor document carries neither yet); username/`preferredUsername` changes (would change the actor identity); any inbound profile sync.

## Key guards (must hold)

- **Name actually changed:** act only when `firstname` or `lastname` is in `$event->changedAttributes` (afterUpdate fires on every profile save).
- **Federatable only:** reuse `Events::federatableActor($userId)` — returns null for federated shadow users (a `RemoteActor` exists for them — loop/echo guard) and for non-discoverable users.
- **Followers only:** skip if the user has no accepted inbound followers (nothing to deliver; avoids pushing an empty job).

## Prerequisites

- Plans 1–7 shipped on `develop`. `PersonSerializer::serialize()`, `DeliverActivity` (fan-out to `FederationFollow` `direction=inbound`,`state=accepted`), `LocalActor`, `RemoteActor`, `DiscoveryPolicy`, `KeyManager`, and `Events::federatableActor()` all exist.
- Live env `https://test.fedihub.social`; admin is federatable and HAS inbound Mastodon followers (≈17 organic followers, shadow users 4–20). After `config.php`/`Events` changes, flush the HumHub cache (`php yii cache/flush-all` on the fedihub console) so the new event registration binds.

---

## File structure (created/modified)

```
fediverse/
├── config.php                                   # MODIFY: register Profile afterUpdate
├── Events.php                                    # MODIFY: onProfileUpdated handler
├── services/PersonSerializer.php                # MODIFY: add updateActivity() wrapper
└── tests/codeception/unit/PersonSerializerUpdateTest.php   # NEW
```

---

## Task 1: `PersonSerializer::updateActivity()` (pure wrapper) + unit test

**Files:** Modify `services/PersonSerializer.php`; Create `tests/codeception/unit/PersonSerializerUpdateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\PersonSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class PersonSerializerUpdateTest extends HumHubDbTestCase
{
    public function testUpdateActivityWrapsDocument()
    {
        $actor = new LocalActor();
        $actor->ap_id = 'https://test.fedihub.social/fediverse/actor/abc';

        $doc = ['id' => $actor->ap_id, 'type' => 'Person', 'name' => 'New Name'];
        $activity = (new PersonSerializer())->updateActivity($actor, $doc, $actor->ap_id . '#updates/xyz');

        $this->assertSame('Update', $activity['type']);
        $this->assertSame($actor->ap_id, $activity['actor']);
        $this->assertSame($actor->ap_id . '#updates/xyz', $activity['id']);
        $this->assertSame($doc, $activity['object']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $activity['to']);
        $this->assertContains('https://www.w3.org/ns/activitystreams', $activity['@context']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/PersonSerializerUpdateTest.php -c tests -v`
Expected: FAIL — `updateActivity()` not defined.

- [ ] **Step 3: Add `updateActivity()` to `services/PersonSerializer.php`**

Add this method to the `PersonSerializer` class (after `serialize()`):

```php
    /**
     * Wrap an actor document in an Update activity, sent to followers when the
     * local profile (e.g. display name) changes.
     *
     * @param LocalActor $actor the local actor whose profile changed
     * @param array $document the actor document from serialize()
     * @param string $activityId a unique id for this Update activity
     */
    public function updateActivity(LocalActor $actor, array $document, string $activityId): array
    {
        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $activityId,
            'type' => 'Update',
            'actor' => $actor->ap_id,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $document,
        ];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/PersonSerializerUpdateTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/luke/work/humhub/modules/develop/fediverse
git add services/PersonSerializer.php tests/codeception/unit/PersonSerializerUpdateTest.php
git commit -m "Add PersonSerializer::updateActivity for profile Update{Person}"
```

---

## Task 2: `Events::onProfileUpdated` + register `Profile` `afterUpdate`

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Add the handler to `Events.php`**

Add import: `use humhub\modules\user\models\Profile;`. (`PersonSerializer`, `DeliverActivity`, `FederationFollow`, `LocalActor`, `User`, `Yii`, and `federatableActor()` already exist in `Events.php`.)

Add this public static handler (near the other handlers, before the class closing `}`):

```php
    /** Local user's display name changed → Update{Person} to their remote followers. */
    public static function onProfileUpdated($event): void
    {
        $profile = $event->sender;
        if (!$profile instanceof Profile) {
            return;
        }
        $changed = $event->changedAttributes ?? [];
        if (!array_key_exists('firstname', $changed) && !array_key_exists('lastname', $changed)) {
            return; // display name (first/last) unchanged
        }

        $user = $profile->user;
        if ($user === null) {
            return;
        }
        $actor = self::federatableActor((int) $user->id);
        if ($actor === null) {
            return; // shadow user (loop guard) or not federatable
        }

        $hasFollowers = FederationFollow::find()->where([
            'local_user_id' => $user->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
            'state' => FederationFollow::STATE_ACCEPTED,
        ])->exists();
        if (!$hasFollowers) {
            return; // nobody to notify
        }

        $serializer = new PersonSerializer();
        $document = $serializer->serialize($actor, $user->displayName, $user->getUrl(true));
        $activity = $serializer->updateActivity($actor, $document, $actor->ap_id . '#updates/' . bin2hex(random_bytes(8)));

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => (int) $user->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
```

- [ ] **Step 2: Register the event in `config.php`**

Add to the `events` array:

```php
        ['class' => 'humhub\modules\user\models\Profile', 'event' => 'afterUpdate', 'callback' => ['humhub\modules\fediverse\Events', 'onProfileUpdated']],
```

- [ ] **Step 3: Verify syntax**

Run: `cd /home/luke/work/humhub/modules/develop/fediverse && php -l Events.php && php -l config.php`
Expected: `No syntax errors detected` on both. Confirm exactly one `use ...user\models\Profile;` import and one `onProfileUpdated` definition.

- [ ] **Step 4: Commit**

```bash
cd /home/luke/work/humhub/modules/develop/fediverse
git add Events.php config.php
git commit -m "Federate display-name changes as Update{Person} to followers"
```

- [ ] **Step 5: Deploy + live end-to-end check (me + human)**

1. (me) Flush cache so the new event binds: `php yii cache/flush-all` on the fedihub console.
2. (human) As admin (federatable, with inbound Mastodon followers), open profile/account settings and change the **first/last name**; save.
3. (human) From a Mastodon account that **follows** `@admin@test.fedihub.social` (e.g. one of the organic followers), check that `@admin`'s display name updates to the new value (Mastodon refreshes on receiving the `Update`).
4. (me) Confirm no `DeliverActivity`/delivery warning in the app log for the fan-out; confirm the handler fired (a temporary debug log line can confirm if needed).

---

## Done criteria

- Unit test green: `PersonSerializerUpdateTest`.
- Live: changing the display name on a federatable user with followers updates the name on a follower's Mastodon view; a profile save with no name change, a non-federatable user, or a user with no followers produces no outbound traffic.

## Self-review risks to confirm live

- **`$user->displayName` reflects the NEW name at `afterUpdate`** — the event fires post-commit, so `$user->profile` re-loads fresh values; confirm the delivered `name` is the new one (not stale).
- **`afterUpdate` only (not `afterInsert`)** — shadow-user profile creation is an insert and must not fire this; and even on a shadow profile *update*, `federatableActor()` returns null. Confirm no Update is emitted for shadow users.
- **Frequent saves** — every profile save with a name change emits one Update; acceptable. Confirm a save that changes only non-name fields emits nothing (the `changedAttributes` guard).
