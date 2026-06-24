<?php

namespace humhub\modules\fediverse\commands;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederatedHtml;
use humhub\modules\like\models\Like;
use humhub\modules\fediverse\services\FederatedUserSource;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class FediverseController extends Controller
{
    /** Usage: php protected/yii fediverse/status */
    public function actionStatus(): int
    {
        $this->stdout('Federation enabled: ' . (DiscoveryPolicy::isGloballyEnabled() ? 'yes' : 'no') . "\n");
        $this->stdout('Domain: ' . FederationUrl::domain() . "\n");
        $this->stdout('Instance actor: ' . FederationUrl::instanceActorUrl() . "\n");
        $this->stdout('Local actors: ' . LocalActor::find()->count() . "\n");
        return ExitCode::OK;
    }

    /**
     * Usage: php protected/yii fediverse/prune-remote-actors [apply]
     * Deletes federated shadow users with NO follow relationship and NO authored
     * like/comment/content (safe orphans). Dry-run unless `apply` = 1.
     */
    public function actionPruneRemoteActors($apply = 0): int
    {
        $apply = ((int) $apply) === 1;
        $prunable = [];
        foreach (RemoteActor::find()->each() as $ra) {
            if ($this->isPrunableActor($ra)) {
                $prunable[] = $ra;
            }
        }
        $this->stdout(count($prunable) . ' prunable shadow users (no follow, no like/comment/post)'
            . ($apply ? "\n" : " [DRY-RUN]\n"));
        if (!$apply) {
            foreach (array_slice($prunable, 0, 20) as $ra) {
                $this->stdout('  - ' . $ra->handle . "\n");
            }
            if (count($prunable) > 20) {
                $this->stdout('  ... and ' . (count($prunable) - 20) . " more\n");
            }
            $this->stdout("Run `php protected/yii fediverse/prune-remote-actors 1` to delete.\n");
            return ExitCode::OK;
        }
        $deleted = 0;
        foreach ($prunable as $ra) {
            $user = User::findOne($ra->user_id);
            if ($user !== null) {
                if ($user->delete()) {
                    $deleted++;
                }
            } else {
                $ra->delete();
            }
        }
        $this->stdout("Deleted {$deleted} shadow users.\n");
        return ExitCode::OK;
    }

    /** A remote actor is safe to prune if it has no follow link and authored no like/comment/content. */
    private function isPrunableActor(RemoteActor $ra): bool
    {
        if (FederationFollow::find()->where(['remote_actor_id' => $ra->id])->exists()) {
            return false;
        }
        $uid = (int) $ra->user_id;
        if (Like::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        if (Comment::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        if (Content::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        return true;
    }

    /** Usage: php protected/yii fediverse/ensure-instance-actor */
    public function actionEnsureInstanceActor(): int
    {
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $this->stdout('Instance actor ready: ' . $actor->ap_id . "\n");
        return ExitCode::OK;
    }

    /**
     * Usage: php protected/yii fediverse/migrate-shadow-users
     * Brings existing federated shadow users onto the clean identity model
     * (user_source, @handle username, null email) and backfills avatar/banner/bio.
     * Idempotent.
     */
    public function actionMigrateShadowUsers(): int
    {
        $resolver = new RemoteActorResolver();
        $max = (int) (Yii::$app->getModule('user')->maximumUsernameLength ?: 50);
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach (RemoteActor::find()->each() as $remote) {
            $user = User::findOne($remote->user_id);
            if ($user === null) {
                $skipped++;
                continue;
            }
            try {
                $user->user_source = FederatedUserSource::ID;

                $desired = '@' . $remote->handle;
                if ($user->username !== $desired) {
                    $user->username = $this->uniqueShadowUsername($desired, (int) $user->id, $max);
                }
                if (is_string($user->email) && str_ends_with($user->email, '@federated.invalid')) {
                    $user->email = null;
                }
                // Normalise visibility: early shadow users were created HIDDEN; all federated
                // users should be REGISTERED_ONLY (visible to members in People, off guest directory).
                $user->visibility = User::VISIBILITY_REGISTERED_ONLY;
                if (!$user->save()) {
                    $this->stderr('  user ' . $user->id . ' save failed: ' . implode(', ', $user->getErrorSummary(true)) . "\n");
                    $errors++;
                    continue;
                }

                // Set fediverse identity fields from the cached remote record — runs even if the actor
                // fetch fails (e.g. 410 Gone), so identity/display is always fixed.
                $profile = $user->profile;
                if ($profile !== null) {
                    $profile->fediverse_handle = mb_substr('@' . $remote->handle, 0, 255);
                    $profile->fediverse_displayname = mb_substr((string) $remote->preferred_username, 0, 255);
                    $profile->firstname = null;
                    $profile->lastname = null;
                    $profile->save(false);
                }

                // Best-effort upgrade from a fresh actor fetch: better displayname, bio, images.
                try {
                    $doc = $resolver->fetchActorDocument($remote->ap_id);
                    $resolver->applyProfileImages($user, $doc);
                    if ($profile !== null) {
                        $name = trim((string) ($doc['name'] ?? '')) ?: (string) $remote->preferred_username;
                        $profile->fediverse_displayname = mb_substr($name, 0, 255);
                        $summary = FederatedHtml::toText($doc['summary'] ?? null);
                        $profile->about = $summary !== '' ? $summary : $profile->about;
                        $profile->save(false);
                    }
                } catch (\Throwable $e) {
                    $this->stdout('  backfill skipped for ' . $remote->handle . ': ' . $e->getMessage() . "\n");
                }

                $migrated++;
                $this->stdout('migrated user ' . $user->id . ' -> ' . $user->username . "\n");
            } catch (\Throwable $e) {
                $this->stderr('  error for remote ' . $remote->id . ' (user ' . $remote->user_id . '): ' . $e->getMessage() . "\n");
                $errors++;
            }
        }

        $this->stdout("Done. migrated={$migrated} skipped={$skipped} errors={$errors}\n");
        return ExitCode::OK;
    }

    /** A unique `@handle` username for a shadow user, capped to $max, excluding the user's own row. */
    private function uniqueShadowUsername(string $base, int $ownUserId, int $max): string
    {
        $candidate = mb_substr($base, 0, $max);
        $i = 1;
        while (
            User::find()->where(['username' => $candidate])->andWhere(['!=', 'id', $ownUserId])->exists()
        ) {
            $suffix = '_' . $i;
            $candidate = mb_substr($base, 0, $max - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        return $candidate;
    }
}
