# Fediverse — Outbox paging + avatar-change Update hook

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`). **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** (1) Make the actor `outbox` a proper paged `OrderedCollection` (it currently advertises `first`/`?page=1` but never serves the items). (2) Send an `Update{Person}` to followers when a local user changes their avatar/banner (no core event exists for image changes → listen on the user `ImageController`'s `afterAction`).

**Tech Stack:** PHP 8, Yii2, existing `PersonSerializer`/`DeliverActivity`/`FederationObject`. No core change.

---

## Task 1: Outbox paging

**Files:** Modify `controllers/OutboxController.php`

- [ ] Replace `actionIndex` so it serves both the collection and pages. Keep the existing discoverable/actor/`$postIds` logic; build a reusable query and branch on `?page`:

```php
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor === null) {
            throw new NotFoundHttpException();
        }

        $postIds = Post::find()
            ->joinWith('content')
            ->where(['content.created_by' => $user->id])
            ->select('post.id')->column();

        $query = FederationObject::find()
            ->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])
            ->andWhere(['in', 'model_id', $postIds ?: [0]])
            ->orderBy(['id' => SORT_DESC]);

        $total = (int) $query->count();
        $pageSize = 20;
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $outbox = $actor->ap_id . '/outbox';
        $page = (int) Yii::$app->request->get('page', 0);

        if ($page < 1) {
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $outbox,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $outbox . '?page=1',
                'last' => $outbox . '?page=' . $lastPage,
            ];
        } else {
            $objects = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->all();
            $items = [];
            foreach ($objects as $obj) {
                $items[] = [
                    'id' => $obj->ap_id . '/activity',
                    'type' => 'Create',
                    'actor' => $actor->ap_id,
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'object' => $obj->ap_id,
                ];
            }
            $doc = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $outbox . '?page=' . $page,
                'type' => 'OrderedCollectionPage',
                'partOf' => $outbox,
                'orderedItems' => $items,
            ];
            if ($page < $lastPage) {
                $doc['next'] = $outbox . '?page=' . ($page + 1);
            }
            if ($page > 1) {
                $doc['prev'] = $outbox . '?page=' . ($page - 1);
            }
        }

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->content = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
```

- [ ] **Verify** `php -l controllers/OutboxController.php`. (Live: GET `/fediverse/actor/<guid>/outbox` → collection with `first`/`last`; `?page=1` → `OrderedCollectionPage` with `orderedItems` (Create activities) + `next`.)

---

## Task 2: avatar/banner-change → `Update{Person}`

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Extract `sendActorUpdate` from `onProfileUpdated`.** Replace the tail of `onProfileUpdated` (the `$user` resolution onward) so it delegates, and add the shared helper:

```php
    public static function onProfileUpdated($event): void
    {
        $profile = $event->sender;
        if (!$profile instanceof Profile) {
            return;
        }
        $changed = $event->changedAttributes ?? [];
        if (!array_key_exists('firstname', $changed)
            && !array_key_exists('lastname', $changed)
            && !array_key_exists('about', $changed)) {
            return; // nothing federatable changed (name or bio)
        }
        $user = $profile->user;
        if ($user !== null) {
            self::sendActorUpdate($user);
        }
    }

    /** Local user changed avatar/banner → Update{Person} (no core image event, so via ImageController afterAction). */
    public static function onProfileImageChanged($event): void
    {
        $imageActions = ['upload', 'banner-upload', 'crop', 'crop-banner', 'delete'];
        if (!in_array($event->action->id ?? '', $imageActions, true)) {
            return;
        }
        $container = $event->sender->contentContainer ?? null;
        if ($container instanceof User) {
            self::sendActorUpdate($container);
        }
    }

    /** Build + fan out an Update{Person} for a local user (no-op if not federatable or has no followers). */
    private static function sendActorUpdate(User $user): void
    {
        $actor = self::federatableActor((int) $user->id);
        if ($actor === null) {
            return; // shadow user / not federatable
        }
        $hasFollowers = FederationFollow::find()->where([
            'local_user_id' => $user->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
            'state' => FederationFollow::STATE_ACCEPTED,
        ])->exists();
        if (!$hasFollowers) {
            return;
        }
        $serializer = new PersonSerializer();
        $extras = PersonSerializer::profileExtras($user);
        $document = $serializer->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon']);
        $activity = $serializer->updateActivity($actor, $document, $actor->ap_id . '#updates/' . bin2hex(random_bytes(8)));
        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => (int) $user->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
```
(Note: the existing `onProfileUpdated` body that built the document inline is now in `sendActorUpdate`; remove the old inline build from `onProfileUpdated`, leaving only the change-detection + delegate above. `User`, `Profile`, `PersonSerializer`, `FederationFollow`, `DeliverActivity` are already imported.)

- [ ] **Step 2: Register the image controller hook in `config.php`** events:

```php
        ['class' => 'humhub\modules\user\controllers\ImageController', 'event' => 'afterAction', 'callback' => ['humhub\modules\fediverse\Events', 'onProfileImageChanged']],
```

- [ ] **Step 3: Verify** `php -l Events.php && php -l config.php`. Confirm one `sendActorUpdate`, one `onProfileImageChanged`, `onProfileUpdated` no longer builds the doc inline.

---

## Deploy + verification (controller + human)

1. (controller) Flush cache (new `config.php` event). `php -l` all.
2. **Outbox:** GET `https://test.fedihub.social/fediverse/actor/<admin-guid>/outbox` → `OrderedCollection` with `first`/`last`; append `?page=1` → `OrderedCollectionPage` with `orderedItems` + `next`.
3. **Avatar:** **Admin** changes profile avatar (or banner) in HumHub → a follower (`@cmburns`) sees the avatar update (Mastodon refreshes on the `Update{Person}`); controller confirms no `DeliverActivity` warning.

## Self-review risks

- **afterAction breadth:** the hook fires on the listed ImageController actions even on a no-op/failed one → at worst a redundant `Update{Person}` with identical data (harmless). `sendActorUpdate` still no-ops for non-federatable users / users without followers.
- **No re-federation loop:** `sendActorUpdate` uses `federatableActor` (null for shadow users), so an inbound-driven profile/image change on a shadow user emits nothing.
- **Outbox page bounds:** `page<1` → collection; out-of-range page → empty `orderedItems` (no `next`). `object` is the Note's AP id (a URI; valid AP — not embedded, lighter).
- **Console context:** the image hook is web-only (ImageController) — fine; `getUrl(true)` in `sendActorUpdate` runs in the web request.
