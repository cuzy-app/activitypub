# Fediverse — Lemmy inbound Spaces, Plan 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Stand up the plumbing for subscribing a HumHub Space to a remote Lemmy-family community: a Space-backed `Group` actor, the `fediverse_space_community` coupling, community discovery via WebFinger, a system "Fediverse" ghost owner, and the `Follow`/`Accept` handshake. No content ingest or write-back yet (Plans 2 & 3).

**Architecture:** A Space gets its own row in the (now polymorphic) `fediverse_local_actor` table (`space_id` set, `type='Group'`) with an RSA keypair and a federation-facing actor URL. `CommunitySubscriptionService` resolves a community handle (`!name@host`) to its `Group` actor document, provisions-or-joins a public Space owned by a single global ghost user, records the coupling in `fediverse_space_community`, and sends a signed `Follow` from the Space actor to the community inbox. The community's `Accept` is matched back to the coupling and flips `follow_state` to `accepted`.

**Tech Stack:** PHP 8, Yii2, HumHub Space/User models, the module's existing `KeyManager`/`SignatureService`/`ActivityDelivery`/`DeliverToInbox`/`FederationHttpClient`/`UrlSafety`. Unit tests via `HumHubDbTestCase`; network + live handshake verified on `https://test.fedihub.social`.

---

## Scope

- **In:** polymorphic `fediverse_local_actor` (add `space_id`, `type='Group'`); new `fediverse_space_community` coupling table + model; `FederationUrl::spaceActorUrl`; `KeyManager::getOrCreateForSpace`; a Space `Group` actor endpoint + inbox route; `CommunityResolver` (handle → `Group` doc, SSRF-guarded); the "Fediverse" ghost user (module setting + provisioner); `DeliverToInbox` signer generalization (`signerActorId`); `CommunitySubscriptionService` (provision-or-join + `Follow`); inbound `Accept` matched to a community coupling.
- **Out (later plans):** unwrapping `Announce` / ingesting posts, comments, votes (Plan 2); write-back `Create{Page}`/`Note`/`Like` (Plan 3); subscribe UI, unsubscribe/archive, media, moderation, `titleMode` enforcement, CHANGELOG/version (Plan 4). A minimal programmatic `subscribe()` entry is built here for testing; the human-facing UI is Plan 4.

## Key facts confirmed against the code (do not re-derive)

- `fediverse_remote_actor` **already has** a `type` column and a `NOT NULL` `user_id` FK to `user`. A community `Group` has **no shadow user**, so its actor data (inbox, keys, handle, name) lives on `fediverse_space_community`, **not** a `remote_actor` row. (Member Persons who post/comment still become normal shadow users in later plans.)
- `ProcessInboxActivity::authenticate()` fetches the signer key via `fetchActorDocument` and does **not** create a shadow user. Only handler methods that call `persistActor()` do. So verifying a community's `Accept`/`Announce` signature is safe; just never call `persistActor()` for the community.
- `DeliverToInbox` signs as `LocalActor::findOne(['user_id' => $signerUserId])` — it cannot sign as a Space actor (no `user_id`). Generalize it with a `signerActorId` (LocalActor PK) that takes precedence.
- `Space::save()` (SCENARIO_CREATE) assigns ownership from `created_by`/current identity. In a service/queue context set `created_by` to the ghost and call `setSpaceOwner($ghostId)` + `addMember($subscriberId)` explicitly. `Space::addMember(int $userId, int $canLeave=1, bool $silent=false, string $groupId=Space::USERGROUP_MEMBER, bool $showAtDashboard=true)`.
- `PersonSerializer::serialize(LocalActor $actor, string $name, string $profileUrl, ?string $summary, ?array $icon, ?array $image)` emits `type` from `$actor->type` — it is already type-agnostic, so a `Group` actor reuses it unchanged.

---

## File structure

```
fediverse/
├── migrations/m260701_120000_fediverse_space_community.php   # CREATE
├── models/LocalActor.php                                     # MODIFY: space_id, TYPE_GROUP
├── models/SpaceCommunity.php                                 # CREATE
├── services/FederationUrl.php                                # MODIFY: spaceActorUrl()
├── services/KeyManager.php                                   # MODIFY: getOrCreateForSpace()
├── services/CommunityResolver.php                            # CREATE
├── services/RemoteActorResolver.php                          # MODIFY: expose webfinger→actorUrl helper
├── services/GhostUser.php                                    # CREATE
├── services/CommunitySubscriptionService.php                 # CREATE
├── controllers/ActorController.php                           # MODIFY: actionSpace()
├── config.php                                                # MODIFY: space actor routes
├── jobs/DeliverToInbox.php                                   # MODIFY: signerActorId
├── jobs/ProcessInboxActivity.php                             # MODIFY: Accept → community coupling
└── tests/codeception/unit/                                   # CREATE tests per task
```

**Test run command** (module suite is wired to the core harness, commit `a48270e`). From the module dir:
```
cd /home/luke/work/humhub/modules/develop/fediverse/tests && codecept run unit <Name>Test
```
A fresh agent sandbox has no DB/Codeception — `php -l <file>` always works; defer `codecept` + live handshake to the human (per the dev-environment note).

---

## Task 1: Migration — polymorphic local actor + coupling table

**Files:** Create `migrations/m260701_120000_fediverse_space_community.php`

- [ ] **Step 1: Write the migration**

```php
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
```

- [ ] **Step 2: Lint**

Run: `php -l migrations/m260701_120000_fediverse_space_community.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Apply (human/live)**

Run (on the test core): `protected/yii migrate/up --includeModuleMigrations=1`
Expected: migration `m260701_120000_fediverse_space_community` applied; `fediverse_space_community` exists, `fediverse_local_actor` has `space_id`.

- [ ] **Step 4: Commit** (on the human's word)

---

## Task 2: `LocalActor` model — Group type + `space_id`

**Files:** Modify `models/LocalActor.php`, Test `tests/codeception/unit/LocalActorGroupTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use tests\codeception\_support\HumHubDbTestCase;

class LocalActorGroupTest extends HumHubDbTestCase
{
    public function testGroupActorForSpaceRoundtrip()
    {
        $actor = new LocalActor();
        $actor->space_id = 1; // space fixture id 1 exists in the HumHub test DB
        $actor->type = LocalActor::TYPE_GROUP;
        $actor->preferred_username = 'group.actor.test';
        $actor->ap_id = 'https://example.com/fediverse/actor/space/abc-guid';
        $actor->public_key = 'PUB';
        $actor->private_key = 'PRIV';

        $this->assertTrue($actor->save(), implode(', ', $actor->getErrorSummary(true)));

        $reloaded = LocalActor::findOne(['space_id' => 1]);
        $this->assertNotNull($reloaded);
        $this->assertSame('Group', $reloaded->type);
        $this->assertNull($reloaded->user_id);
        $this->assertSame(1, (int) $reloaded->space_id);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd tests && codecept run unit LocalActorGroupTest`
Expected: FAIL — `space_id`/`TYPE_GROUP` unknown (property/constant not defined).

- [ ] **Step 3: Implement — add the constant, property doc, and rules**

Add to the class docblock (after `@property int|null $user_id`):
```php
 * @property int|null $space_id
```
Add the constant next to the existing ones:
```php
    public const TYPE_GROUP = 'Group';
```
Update `rules()` — add `space_id` as integer + unique (leave the existing rules intact):
```php
            [['user_id', 'space_id', 'followers_count', 'following_count'], 'integer'],
            [['space_id'], 'unique'],
```
(Replace the existing `[['user_id', 'followers_count', 'following_count'], 'integer'],` line with the one above.)

- [ ] **Step 4: Run to verify it passes**

Run: `cd tests && codecept run unit LocalActorGroupTest`
Expected: PASS.

- [ ] **Step 5: Lint + Commit** (`php -l models/LocalActor.php`; commit on the human's word)

---

## Task 3: `SpaceCommunity` model

**Files:** Create `models/SpaceCommunity.php`, Test `tests/codeception/unit/SpaceCommunityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\SpaceCommunity;
use tests\codeception\_support\HumHubDbTestCase;

class SpaceCommunityTest extends HumHubDbTestCase
{
    public function testCouplingRoundtripAndLookup()
    {
        $c = new SpaceCommunity();
        $c->space_id = 1;
        $c->community_ap_id = 'https://lemmy.example/c/linux';
        $c->handle = '!linux@lemmy.example';
        $c->inbox_url = 'https://lemmy.example/c/linux/inbox';
        $this->assertTrue($c->save(), implode(', ', $c->getErrorSummary(true)));
        $this->assertSame(SpaceCommunity::FOLLOW_STATE_PENDING, $c->follow_state);

        $found = SpaceCommunity::findOne(['community_ap_id' => 'https://lemmy.example/c/linux']);
        $this->assertNotNull($found);
        $this->assertSame(1, (int) $found->space_id);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `cd tests && codecept run unit SpaceCommunityTest` → FAIL (class not found).

- [ ] **Step 3: Implement the model**

```php
<?php

namespace humhub\modules\fediverse\models;

use humhub\components\ActiveRecord;
use humhub\modules\space\models\Space;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Couples a HumHub Space to a remote Lemmy-family community (a Group actor).
 * One row per federated Space; carries the community's AP identity and the
 * delivery targets we send Follow/interactions to.
 *
 * @property int $id
 * @property int $space_id
 * @property string $community_ap_id
 * @property string|null $handle
 * @property string|null $preferred_username
 * @property string|null $name
 * @property string|null $summary
 * @property string|null $icon_url
 * @property string|null $image_url
 * @property string $inbox_url
 * @property string|null $shared_inbox_url
 * @property string|null $followers_url
 * @property string|null $outbox_url
 * @property string $follow_state
 * @property string|null $follow_activity_ap_id
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class SpaceCommunity extends ActiveRecord
{
    public const FOLLOW_STATE_PENDING = 'pending';
    public const FOLLOW_STATE_ACCEPTED = 'accepted';
    public const FOLLOW_STATE_REJECTED = 'rejected';

    public static function tableName()
    {
        return 'fediverse_space_community';
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
            [['space_id', 'community_ap_id', 'inbox_url'], 'required'],
            [['space_id'], 'integer'],
            [['summary'], 'string'],
            [['community_ap_id', 'handle', 'preferred_username', 'name', 'icon_url', 'image_url',
              'inbox_url', 'shared_inbox_url', 'followers_url', 'outbox_url', 'follow_activity_ap_id'], 'string', 'max' => 255],
            [['follow_state'], 'string', 'max' => 16],
            [['follow_state'], 'default', 'value' => self::FOLLOW_STATE_PENDING],
            [['space_id'], 'unique'],
            [['community_ap_id'], 'unique'],
        ];
    }

    public function getSpace()
    {
        return $this->hasOne(Space::class, ['id' => 'space_id']);
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `cd tests && codecept run unit SpaceCommunityTest` → PASS.

- [ ] **Step 5: Lint + Commit** (`php -l models/SpaceCommunity.php`)

---

## Task 4: `FederationUrl::spaceActorUrl` + `KeyManager::getOrCreateForSpace`

**Files:** Modify `services/FederationUrl.php`, `services/KeyManager.php`; Test `tests/codeception/unit/SpaceActorTest.php`

- [ ] **Step 1: Add `spaceActorUrl` to `FederationUrl`** (next to `actorUrl`)

```php
    /** Absolute ActivityPub actor id/URL for a Space's Group actor. */
    public static function spaceActorUrl(string $guid): string
    {
        return self::base() . '/fediverse/actor/space/' . $guid;
    }
```

- [ ] **Step 2: Write the failing test** for the Space keypair provisioner

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\space\models\Space;
use tests\codeception\_support\HumHubDbTestCase;

class SpaceActorTest extends HumHubDbTestCase
{
    public function testGetOrCreateForSpaceIsIdempotent()
    {
        $space = Space::findOne(['id' => 1]);
        $this->assertNotNull($space);

        $actor = (new KeyManager())->getOrCreateForSpace($space);
        $this->assertSame(LocalActor::TYPE_GROUP, $actor->type);
        $this->assertSame((int) $space->id, (int) $actor->space_id);
        $this->assertNotEmpty($actor->private_key);
        $this->assertStringContainsString('/fediverse/actor/space/' . $space->guid, $actor->ap_id);

        $again = (new KeyManager())->getOrCreateForSpace($space);
        $this->assertSame($actor->id, $again->id); // no duplicate
    }
}
```

- [ ] **Step 3: Run to verify it fails** — `cd tests && codecept run unit SpaceActorTest` → FAIL (method missing).

- [ ] **Step 4: Implement `getOrCreateForSpace`** in `KeyManager` (mirror `getOrCreateForUser`)

Add the import at the top:
```php
use humhub\modules\space\models\Space;
```
Add the method:
```php
    /** Get (or lazily create) the Group LocalActor for a Space. */
    public function getOrCreateForSpace(Space $space): LocalActor
    {
        $actor = LocalActor::findOne(['space_id' => $space->id]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->space_id = $space->id;
        $actor->type = LocalActor::TYPE_GROUP;
        $actor->preferred_username = $this->uniqueHandle($space->url ?: ('space_' . $space->id));
        $actor->ap_id = FederationUrl::spaceActorUrl($space->guid);
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;

        try {
            if (!$actor->save()) {
                throw new \RuntimeException('Could not create Space LocalActor: ' . implode(', ', $actor->getErrorSummary(true)));
            }
        } catch (\yii\db\IntegrityException $e) {
            $existing = LocalActor::findOne(['space_id' => $space->id]);
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }

        return $actor;
    }
```
(`$space->url` is the Space's URL slug; `uniqueHandle()` normalizes + de-collides it. `RuntimeException`/`IntegrityException` are referenced fully-qualified to avoid extra imports beyond the existing `use RuntimeException;` — if that import is present, drop the leading `\` on `RuntimeException`.)

- [ ] **Step 5: Run to verify it passes** — `cd tests && codecept run unit SpaceActorTest` → PASS.

- [ ] **Step 6: Lint + Commit** (`php -l services/FederationUrl.php services/KeyManager.php`)

---

## Task 5: Space `Group` actor endpoint + routes

**Files:** Modify `controllers/ActorController.php`, `config.php`

- [ ] **Step 1: Add the routes** to `config.php` `urlManagerRules`. **Order matters** — the `space/<guid>` rules MUST come before the generic `fediverse/actor/<guid>` rule (else `space` is captured as a guid). Insert directly above the `'fediverse/actor/<guid:[\w\-]+>'` line:

```php
        ['pattern' => 'fediverse/actor/space/<guid:[\w\-]+>/inbox', 'route' => 'fediverse/inbox/shared', 'verb' => ['POST']],
        ['pattern' => 'fediverse/actor/space/<guid:[\w\-]+>', 'route' => 'fediverse/actor/space'],
```
(The Space actor advertises `inbox = <ap_id>/inbox`, routed to the shared inbox processor — `ProcessInboxActivity` dispatches `Accept`/`Announce` by signer/actor, so a per-Space target guid is not needed.)

- [ ] **Step 2: Add `actionSpace` to `ActorController`**

Add imports at the top:
```php
use humhub\modules\fediverse\models\SpaceCommunity;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\space\models\Space;
```
Add the action:
```php
    /** GET /fediverse/actor/space/<guid> — the Group actor for a federated Space. */
    public function actionSpace($guid)
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new NotFoundHttpException();
        }
        $space = Space::findOne(['guid' => $guid]);
        // Only a coupled, guest-visible Space is a valid federated Group actor.
        if ($space === null
            || $space->visibility != Space::VISIBILITY_ALL
            || SpaceCommunity::findOne(['space_id' => $space->id]) === null) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForSpace($space);

        $summary = trim((string) $space->description) !== ''
            ? \humhub\modules\content\widgets\richtext\RichText::convert($space->description, \humhub\modules\content\widgets\richtext\RichText::FORMAT_HTML)
            : null;
        $icon = ['type' => 'Image', 'url' => $space->getProfileImage()->getUrl(null, true)];

        $doc = (new PersonSerializer())->serialize($actor, $space->getDisplayName(), $space->getUrl(true), $summary, $icon, null);

        return $this->asActivityJson($doc);
    }
```

- [ ] **Step 3: Lint** — `php -l controllers/ActorController.php config.php`

- [ ] **Step 4: Verify (human/live)** — after coupling a Space (Task 9), `curl -H 'Accept: application/activity+json' https://test.fedihub.social/fediverse/actor/space/<guid>` returns a `"type":"Group"` document with `preferredUsername`, `inbox`, `publicKey`. Non-coupled or non-public Space → 404.

- [ ] **Step 5: Commit** (on the human's word)

---

## Task 6: `CommunityResolver` — handle → Group document

**Files:** Modify `services/RemoteActorResolver.php` (expose a webfinger→actorUrl helper), Create `services/CommunityResolver.php`, Test `tests/codeception/unit/CommunityResolverTest.php`

- [ ] **Step 1: Extract a public WebFinger→actor-URL helper in `RemoteActorResolver`** (DRY: `resolveByHandle` reuses it). Add this method, and change `resolveByHandle` to call it:

```php
    /**
     * WebFinger a query URL and return the self-link ActivityPub actor URL.
     * Follows redirects with SSRF re-validation (via webfingerGet). Throws on failure.
     */
    public function webfingerActorUrl(string $webfingerUrl): string
    {
        $response = $this->webfingerGet($webfingerUrl);
        if (!$response->getIsOk()) {
            throw new RuntimeException('WebFinger lookup failed (' . $response->getStatusCode() . '): ' . $webfingerUrl);
        }
        $jrd = json_decode($response->getContent(), true);
        foreach ($jrd['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'self'
                && in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json'], true)) {
                $href = (string) ($link['href'] ?? '');
                if ($href !== '') {
                    return $href;
                }
            }
        }
        throw new RuntimeException('No ActivityPub actor link in WebFinger for ' . $webfingerUrl);
    }
```
Then simplify the body of `resolveByHandle` — replace the inline WebFinger fetch + link loop (from `$response = $this->webfingerGet($wf);` through the `if ($actorUrl === '')` throw) with:
```php
        $actorUrl = $this->webfingerActorUrl($wf);
        return $this->resolve($actorUrl);
```

- [ ] **Step 2: Write the failing test** (pure handle-parsing + doc validation; no network)

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\CommunityResolver;
use tests\codeception\_support\HumHubDbTestCase;

class CommunityResolverTest extends HumHubDbTestCase
{
    public function testParseHandleStripsBangAndAt()
    {
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('!linux@lemmy.world'));
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('@linux@lemmy.world'));
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('linux@lemmy.world'));
        $this->assertNull(CommunityResolver::parseHandle('not a handle'));
        $this->assertNull(CommunityResolver::parseHandle('nodomain'));
    }

    public function testWebfingerUrlUsesAcctWithoutBang()
    {
        $this->assertSame(
            'https://lemmy.world/.well-known/webfinger?resource=acct:linux@lemmy.world',
            CommunityResolver::webfingerUrl('!linux@lemmy.world')
        );
        $this->assertSame('', CommunityResolver::webfingerUrl('garbage'));
    }

    public function testIsCommunityDocRejectsPerson()
    {
        $this->assertTrue(CommunityResolver::isCommunityDoc(['type' => 'Group', 'id' => 'x', 'inbox' => 'y']));
        $this->assertFalse(CommunityResolver::isCommunityDoc(['type' => 'Person', 'id' => 'x', 'inbox' => 'y']));
        $this->assertFalse(CommunityResolver::isCommunityDoc(['type' => 'Group'])); // missing inbox
    }
}
```

- [ ] **Step 3: Run to verify it fails** — `cd tests && codecept run unit CommunityResolverTest` → FAIL (class not found).

- [ ] **Step 4: Implement `CommunityResolver`**

```php
<?php

namespace humhub\modules\fediverse\services;

use RuntimeException;

/**
 * Resolves a Lemmy-family community handle (!name@host / name@host / actor URL)
 * to its verified Group actor document. Reuses RemoteActorResolver's
 * SSRF-guarded, signed WebFinger + actor fetch. Does NOT create any shadow
 * user — a community has no local user; its data is stored on SpaceCommunity.
 */
class CommunityResolver
{
    /** Split a community handle into [name, host]; null if malformed. Strips a leading '!' or '@'. */
    public static function parseHandle(string $handle): ?array
    {
        $handle = ltrim(trim($handle), '!@');
        if (!preg_match('/^([^@\s]+)@([^@\s]+\.[^@\s]+)$/', $handle, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /** WebFinger query URL for a community handle (acct: WITHOUT the '!'); '' if malformed. */
    public static function webfingerUrl(string $handle): string
    {
        $parts = self::parseHandle($handle);
        if ($parts === null) {
            return '';
        }
        [$name, $host] = $parts;
        return 'https://' . $host . '/.well-known/webfinger?resource=acct:' . $name . '@' . $host;
    }

    /** A usable community actor doc is a Group with an inbox + id. */
    public static function isCommunityDoc(array $doc): bool
    {
        $type = $doc['type'] ?? '';
        return $type === 'Group'
            && !empty($doc['id'])
            && !empty($doc['inbox']);
    }

    /**
     * Resolve a handle to its Group actor document (WebFinger → actor fetch).
     * Throws on unsafe/unreachable/non-Group input.
     */
    public function resolveByHandle(string $handle): array
    {
        $wf = self::webfingerUrl($handle);
        if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
            throw new RuntimeException('Invalid or unsafe community handle: ' . $handle);
        }
        $resolver = new RemoteActorResolver();
        $actorUrl = $resolver->webfingerActorUrl($wf);
        $doc = $resolver->fetchActorDocument($actorUrl); // SSRF-guarded, signed GET
        if (!self::isCommunityDoc($doc)) {
            throw new RuntimeException('Not a Lemmy-family community (Group) actor: ' . $handle);
        }
        return $doc;
    }
}
```

- [ ] **Step 5: Run to verify it passes** — `cd tests && codecept run unit CommunityResolverTest` → PASS.

- [ ] **Step 6: Lint + Commit** (`php -l services/CommunityResolver.php services/RemoteActorResolver.php`)

---

## Task 7: The "Fediverse" ghost user

**Files:** Create `services/GhostUser.php`, Test `tests/codeception/unit/GhostUserTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\GhostUser;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class GhostUserTest extends HumHubDbTestCase
{
    public function testGetOrCreateIsIdempotentAndStoredInSetting()
    {
        $u1 = GhostUser::getOrCreate();
        $this->assertInstanceOf(User::class, $u1);
        $this->assertSame((int) $u1->id, (int) \Yii::$app->getModule('fediverse')->settings->get('ghostUserId'));

        $u2 = GhostUser::getOrCreate();
        $this->assertSame($u1->id, $u2->id); // no duplicate
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `cd tests && codecept run unit GhostUserTest` → FAIL (class not found).

- [ ] **Step 3: Implement `GhostUser`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\models\User;
use RuntimeException;
use Yii;

/**
 * The single global, login-disabled local system user that owns/admins every
 * federated community Space. Its id is stored in the module setting
 * `ghostUserId`; an admin may point it at a different user. It is an ordinary
 * local user (never opts into federation, authors no content) — no shadow-user
 * or UserSource machinery.
 */
class GhostUser
{
    public const SETTING = 'ghostUserId';

    /** The configured ghost user, or null if unset/missing. */
    public static function find(): ?User
    {
        $id = (int) Yii::$app->getModule('fediverse')->settings->get(self::SETTING, 0);
        return $id > 0 ? User::findOne($id) : null;
    }

    /** Get the ghost user, creating + persisting it (and the setting) on first use. */
    public static function getOrCreate(): User
    {
        $existing = self::find();
        if ($existing !== null) {
            return $existing;
        }

        $user = new User();
        $user->username = self::uniqueUsername('Fediverse');
        $user->email = 'fediverse-system@' . FederationUrl::domain();
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_HIDDEN; // off the People directory
        $user->allowAssignDefaultGroup(false);
        if (!$user->save()) {
            throw new RuntimeException('Could not create Fediverse ghost user: ' . implode(', ', $user->getErrorSummary(true)));
        }

        $profile = $user->profile;
        $profile->firstname = 'Fediverse';
        $profile->lastname = 'System';
        $profile->save(false);

        // No password auth is created → the account cannot be logged into.

        Yii::$app->getModule('fediverse')->settings->set(self::SETTING, (int) $user->id);
        return $user;
    }

    private static function uniqueUsername(string $base): string
    {
        $candidate = $base;
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $candidate = $base . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `cd tests && codecept run unit GhostUserTest` → PASS.

- [ ] **Step 5: Lint + Commit** (`php -l services/GhostUser.php`)

---

## Task 8: `DeliverToInbox` — sign as any local actor

**Files:** Modify `jobs/DeliverToInbox.php`

- [ ] **Step 1: Add the `signerActorId` field** (below `public int $signerUserId = 0;`)

```php
    public int $signerActorId = 0; // LocalActor PK; takes precedence over signerUserId (e.g. a Space Group actor)
```

- [ ] **Step 2: Resolve the signer by actor id first** — replace the signer lookup line in `run()`:

Replace:
```php
        $signer = LocalActor::findOne(['user_id' => $this->signerUserId]);
```
with:
```php
        $signer = $this->signerActorId > 0
            ? LocalActor::findOne(['id' => $this->signerActorId])
            : LocalActor::findOne(['user_id' => $this->signerUserId]);
```
(Everything else — `ActivityDelivery::deliver($signer, ...)`, which signs with `$signer->ap_id` + `$signer->private_key` — works unchanged for a Space Group actor.)

- [ ] **Step 3: Lint** — `php -l jobs/DeliverToInbox.php`

- [ ] **Step 4: Commit** (on the human's word)

---

## Task 9: `CommunitySubscriptionService` — provision-or-join + Follow

**Files:** Create `services/CommunitySubscriptionService.php`, Test `tests/codeception/unit/CommunitySubscriptionServiceTest.php`

- [ ] **Step 1: Write the failing test** (drives `provisionOrJoin`, which is network-free — a hand-built community doc)

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\SpaceCommunity;
use humhub\modules\fediverse\services\CommunitySubscriptionService;
use humhub\modules\fediverse\services\GhostUser;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class CommunitySubscriptionServiceTest extends HumHubDbTestCase
{
    private function doc(): array
    {
        return [
            'id' => 'https://lemmy.example/c/linux',
            'type' => 'Group',
            'preferredUsername' => 'linux',
            'name' => 'Linux',
            'summary' => '<p>Everything Linux.</p>',
            'inbox' => 'https://lemmy.example/c/linux/inbox',
            'endpoints' => ['sharedInbox' => 'https://lemmy.example/inbox'],
            'followers' => 'https://lemmy.example/c/linux/followers',
            'outbox' => 'https://lemmy.example/c/linux/outbox',
        ];
    }

    public function testProvisionCreatesPublicSpaceOwnedByGhost()
    {
        $subscriber = User::findOne(['id' => 2]); // a normal user fixture
        $space = (new CommunitySubscriptionService())
            ->provisionOrJoin($subscriber, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');

        $this->assertInstanceOf(Space::class, $space);
        $this->assertEquals(Space::VISIBILITY_ALL, $space->visibility);

        // Owner is the ghost; the subscriber is a member.
        $ghost = GhostUser::find();
        $this->assertNotNull($ghost);
        $this->assertTrue($space->isSpaceOwner($ghost->id));
        $this->assertTrue($space->isMember($subscriber->id));

        // Coupling + Group actor exist.
        $coupling = SpaceCommunity::findOne(['community_ap_id' => 'https://lemmy.example/c/linux']);
        $this->assertNotNull($coupling);
        $this->assertSame((int) $space->id, (int) $coupling->space_id);
        $this->assertSame('https://lemmy.example/c/linux/inbox', $coupling->inbox_url);
        $this->assertNotNull(LocalActor::findOne(['space_id' => $space->id, 'type' => LocalActor::TYPE_GROUP]));
    }

    public function testSecondSubscriberJoinsExistingSpaceNoDuplicate()
    {
        $svc = new CommunitySubscriptionService();
        $a = User::findOne(['id' => 2]);
        $b = User::findOne(['id' => 3]);

        $space1 = $svc->provisionOrJoin($a, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');
        $space2 = $svc->provisionOrJoin($b, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');

        $this->assertSame($space1->id, $space2->id); // same Space, no duplicate
        $this->assertSame(1, (int) SpaceCommunity::find()->where(['community_ap_id' => 'https://lemmy.example/c/linux'])->count());
        $this->assertTrue($space2->isMember($b->id));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `cd tests && codecept run unit CommunitySubscriptionServiceTest` → FAIL (class not found).

- [ ] **Step 3: Implement the service**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\SpaceCommunity;
use humhub\modules\fediverse\jobs\DeliverToInbox;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use RuntimeException;
use Yii;

/**
 * Subscribes HumHub users to remote Lemmy-family communities. Each community is
 * mirrored 1:1 into a public HumHub Space owned by the global ghost user; the
 * first subscriber provisions it, later subscribers just join. The Space's Group
 * actor sends a single signed Follow to the community; the community's Accept
 * (handled in ProcessInboxActivity) flips follow_state to accepted.
 */
class CommunitySubscriptionService
{
    /** Resolve a handle and provision-or-join its Space. Network path (used by UI/console). */
    public function subscribe(User $subscriber, string $handle): Space
    {
        $doc = (new CommunityResolver())->resolveByHandle($handle);
        return $this->provisionOrJoin($subscriber, $doc, (string) $doc['id'], $handle);
    }

    /**
     * Given an already-resolved community Group document, either join the
     * existing coupled Space or provision a new one. Network-free except the
     * outbound Follow on first provisioning.
     */
    public function provisionOrJoin(User $subscriber, array $communityDoc, string $communityApId, string $handle): Space
    {
        $coupling = SpaceCommunity::findOne(['community_ap_id' => $communityApId]);
        if ($coupling !== null) {
            $space = Space::findOne(['id' => $coupling->space_id]);
            if ($space === null) {
                throw new RuntimeException('Coupling references a missing Space: ' . $communityApId);
            }
            $space->addMember((int) $subscriber->id);
            return $space;
        }

        $ghost = GhostUser::getOrCreate();
        $space = $this->createPublicSpace($communityDoc, $ghost);

        // Enable this module on the Space (per-container).
        $space->moduleManager->enable('fediverse');

        $coupling = new SpaceCommunity();
        $coupling->space_id = $space->id;
        $coupling->community_ap_id = $communityApId;
        $coupling->handle = $handle;
        $coupling->preferred_username = isset($communityDoc['preferredUsername']) ? (string) $communityDoc['preferredUsername'] : null;
        $coupling->name = isset($communityDoc['name']) ? mb_substr((string) $communityDoc['name'], 0, 255) : null;
        $coupling->summary = isset($communityDoc['summary']) ? (string) $communityDoc['summary'] : null;
        $coupling->icon_url = is_array($communityDoc['icon'] ?? null) ? ($communityDoc['icon']['url'] ?? null) : null;
        $coupling->image_url = is_array($communityDoc['image'] ?? null) ? ($communityDoc['image']['url'] ?? null) : null;
        $coupling->inbox_url = (string) ($communityDoc['inbox'] ?? '');
        $coupling->shared_inbox_url = $communityDoc['endpoints']['sharedInbox'] ?? null;
        $coupling->followers_url = $communityDoc['followers'] ?? null;
        $coupling->outbox_url = $communityDoc['outbox'] ?? null;
        $coupling->follow_state = SpaceCommunity::FOLLOW_STATE_PENDING;
        if (!$coupling->save()) {
            throw new RuntimeException('Could not save SpaceCommunity: ' . implode(', ', $coupling->getErrorSummary(true)));
        }

        $space->addMember((int) $subscriber->id);
        $this->sendFollow($space, $coupling);

        return $space;
    }

    /** Create a public (guest-visible) Space owned by the ghost user, from the community doc. */
    private function createPublicSpace(array $communityDoc, User $ghost): Space
    {
        $name = trim((string) ($communityDoc['name'] ?? '')) ?: (string) ($communityDoc['preferredUsername'] ?? 'Community');

        $space = new Space();
        $space->scenario = Space::SCENARIO_CREATE;
        $space->name = mb_substr($name, 0, 45); // Space.name max length
        $space->description = isset($communityDoc['summary']) ? mb_substr(strip_tags((string) $communityDoc['summary']), 0, 100) : '';
        $space->visibility = Space::VISIBILITY_ALL;       // public / guest-visible (§5 invariant)
        $space->join_policy = Space::JOIN_POLICY_FREE;
        $space->created_by = $ghost->id;                  // ownership seed (queue/console has no identity)
        if (!$space->save()) {
            throw new RuntimeException('Could not create community Space: ' . implode(', ', $space->getErrorSummary(true)));
        }
        // Ensure the ghost is the owner regardless of who triggered creation.
        $space->setSpaceOwner($ghost->id);
        return $space;
    }

    /** Send the Space Group actor's signed Follow to the community inbox. */
    private function sendFollow(Space $space, SpaceCommunity $coupling): void
    {
        $actor = (new KeyManager())->getOrCreateForSpace($space);
        $activityId = $actor->ap_id . '/follows/' . bin2hex(random_bytes(8));

        $follow = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Follow',
            'actor' => $actor->ap_id,
            'object' => $coupling->community_ap_id,
        ];

        $coupling->follow_activity_ap_id = $activityId;
        $coupling->save(false);

        Yii::$app->queue->push(new DeliverToInbox([
            'signerActorId' => (int) $actor->id,
            'inboxUrl' => $coupling->inbox_url,
            'activityJson' => json_encode($follow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `cd tests && codecept run unit CommunitySubscriptionServiceTest` → PASS. (`Space::isSpaceOwner`, `isMember`, `setSpaceOwner`, `moduleManager->enable`, `addMember` are core Space APIs.)

- [ ] **Step 5: Lint + Commit** (`php -l services/CommunitySubscriptionService.php`)

---

## Task 10: Inbound `Accept` → flip the community coupling

**Files:** Modify `jobs/ProcessInboxActivity.php`, Test `tests/codeception/unit/AcceptCommunityTest.php`

The community's `Accept` is signed by the community `Group`, which has **no** `RemoteActor` row — the existing `handleAccept` looks up `RemoteActor::findOne(['ap_id' => $signerId])` and returns early. Add a community branch first (no shadow user is created).

- [ ] **Step 1: Write the failing test** (drives the coupling-flip logic directly)

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\jobs\ProcessInboxActivity;
use humhub\modules\fediverse\models\SpaceCommunity;
use tests\codeception\_support\HumHubDbTestCase;

class AcceptCommunityTest extends HumHubDbTestCase
{
    public function testAcceptFromCommunityFlipsFollowStateToAccepted()
    {
        $c = new SpaceCommunity();
        $c->space_id = 1;
        $c->community_ap_id = 'https://lemmy.example/c/linux';
        $c->inbox_url = 'https://lemmy.example/c/linux/inbox';
        $c->follow_state = SpaceCommunity::FOLLOW_STATE_PENDING;
        $c->save(false);

        // Simulate the verified Accept from the community (signer == community_ap_id).
        $job = new ProcessInboxActivity();
        $handled = $job->acceptCommunityFollow('https://lemmy.example/c/linux');
        $this->assertTrue($handled);

        $c->refresh();
        $this->assertSame(SpaceCommunity::FOLLOW_STATE_ACCEPTED, $c->follow_state);
    }

    public function testAcceptFromUnknownSignerIsNotHandledAsCommunity()
    {
        $job = new ProcessInboxActivity();
        $this->assertFalse($job->acceptCommunityFollow('https://lemmy.example/c/unknown'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `cd tests && codecept run unit AcceptCommunityTest` → FAIL (method missing).

- [ ] **Step 3: Add the coupling-flip method + wire it into `handleAccept`**

Add the import near the top of `ProcessInboxActivity`:
```php
use humhub\modules\fediverse\models\SpaceCommunity;
```
Add the method (public so it is unit-testable in isolation):
```php
    /**
     * If the signer is a subscribed community, mark our Follow accepted and
     * report handled. Community Group actors have no RemoteActor/shadow user.
     */
    public function acceptCommunityFollow(string $signerId): bool
    {
        $coupling = SpaceCommunity::findOne(['community_ap_id' => $signerId]);
        if ($coupling === null) {
            return false;
        }
        if ($coupling->follow_state !== SpaceCommunity::FOLLOW_STATE_ACCEPTED) {
            $coupling->follow_state = SpaceCommunity::FOLLOW_STATE_ACCEPTED;
            $coupling->save(false);
        }
        return true;
    }
```
At the **top** of `handleAccept()`, before the existing `$remote = RemoteActor::findOne(...)` line, add:
```php
        // A community accepting our Space's Follow — no shadow user involved.
        if ($this->acceptCommunityFollow($signerId)) {
            return;
        }
```

- [ ] **Step 4: Run to verify it passes** — `cd tests && codecept run unit AcceptCommunityTest` → PASS.

- [ ] **Step 5: Lint** — `php -l jobs/ProcessInboxActivity.php`

- [ ] **Step 6: Live handshake verification (human)** — with a Space coupled to a real community (`!<community>@<lemmy-instance>`) via `subscribe()`:
  - the outbound `Follow` is delivered (worker log: DeliverToInbox, 2xx);
  - the community's `Accept` arrives at `/fediverse/inbox`, passes signature verification, and `fediverse_space_community.follow_state` becomes `accepted`;
  - **no** shadow user is created for the community Group.

- [ ] **Step 7: Commit** (on the human's word)

---

## Self-review checklist (run after implementing, before handoff)

- [ ] **Route order:** the `fediverse/actor/space/<guid>` rules precede the generic `fediverse/actor/<guid>` rule in `config.php`.
- [ ] **No shadow user for communities:** neither `CommunityResolver` nor `acceptCommunityFollow` calls `persistActor`/`resolveFromDocument`.
- [ ] **Signer generalization:** every Space-actor delivery passes `signerActorId` (not `signerUserId`).
- [ ] **Public gating:** `createPublicSpace` sets `VISIBILITY_ALL`; `actionSpace` 404s a non-public / non-coupled Space.
- [ ] **Ghost ownership:** `provisionOrJoin` sets the ghost as owner and the subscriber as member; a second subscriber joins the same Space (dedup on `community_ap_id`).
- [ ] **Type names consistent:** `SpaceCommunity`, `LocalActor::TYPE_GROUP`, `FOLLOW_STATE_*`, `signerActorId`, `getOrCreateForSpace`, `spaceActorUrl`, `webfingerActorUrl`, `acceptCommunityFollow` used identically across tasks.

## What this plan deliberately leaves for later

- **Plan 2 (Read ingest):** `Announce` unwrapping + routing by `Announce.actor` → the coupled Space; `PageIngestService` (`Create{Page}`→Post with `Page.name`→`Post.title`); `Create{Note}`→comment, `Like`/`Undo`, `Update`/`Delete`; member Persons → shadow users.
- **Plan 3 (Write-back):** `Create{Page}` (via `PageSerializer` + title-required validation in federated Spaces), comments, likes → community inbox; own-post `Update`/`Delete`; echo-dedup; loop-guard.
- **Plan 4 (Polish/UX):** subscribe UI + directory entry, unsubscribe/archive (`Undo{Follow}` when the last human member leaves), community avatar/banner → Space images, moderation reflection, global `titleMode` guidance, `docs/CHANGELOG.md` + `module.json` bump (→ `0.2.0`).
```
