<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Restricts queries on the model to only projects assigned to the authenticated user
 * when that user is a client (User::is_client = true).
 *
 * The trait is a no-op for non-client users (admin, owner, member) and for unauthenticated
 * contexts (queue jobs, console commands, etc.). This makes it safe to apply broadly.
 *
 * Models that use this trait must implement scopeAccessibleByClient(): a closure
 * applied to a query builder that filters rows whose related project_id is in the given array.
 */
trait RestrictsToClientProjects
{
    /**
     * Per-request memoization of the assigned project id list for each user.
     * Avoids hitting the database on every single query and — critically —
     * avoids re-entering the global scope while computing the id list
     * itself, which would cause infinite recursion.
     *
     * @var array<int, array<int, int>>
     */
    protected static array $clientProjectIdsCache = [];

    protected static function bootRestrictsToClientProjects(): void
    {
        static::addGlobalScope('clientProjectAccess', function (Builder $builder): void {
            $user = Auth::user();

            if (! $user || ! method_exists($user, 'isClient') || ! $user->isClient()) {
                return;
            }

            $projectIds = self::clientProjectIdsFor($user->id);

            if (empty($projectIds)) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $model = $builder->getModel();
            $model->scopeAccessibleByClient($builder, $projectIds);
        });
    }

    /**
     * Resolves the assigned project ids for a given client user, hitting the
     * project_user pivot table directly with the query builder so the lookup
     * does NOT go through the Project Eloquent model. That matters because
     * the global scope on Project is what calls this method — if we used
     * $user->assignedProjects()->pluck() here, the belongsToMany relation
     * would fire the same scope recursively and the request would hang in
     * an infinite loop until PHP-FPM killed it.
     *
     * @return array<int, int>
     */
    protected static function clientProjectIdsFor(int $userId): array
    {
        if (array_key_exists($userId, self::$clientProjectIdsCache)) {
            return self::$clientProjectIdsCache[$userId];
        }

        $ids = DB::table('project_user')
            ->where('user_id', $userId)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return self::$clientProjectIdsCache[$userId] = $ids;
    }

    /**
     * Flushes the per-request memoization cache. Call this from tests and
     * from any code path that attaches or detaches a project_user pivot row
     * inside the same request, so the next query sees the fresh ids.
     */
    public static function flushClientProjectIdsCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$clientProjectIdsCache = [];

            return;
        }

        unset(self::$clientProjectIdsCache[$userId]);
    }

    /**
     * Each model defines how it relates to a project so the global scope can apply
     * the correct constraint. Models with a direct project_id column override this
     * to a simpler whereIn; models nested through environment use whereHas chains.
     */
    public function scopeAccessibleByClient(Builder $query, array $projectIds): Builder
    {
        return $query->whereHas('environment.project', function (Builder $q) use ($projectIds): void {
            $q->whereIn('id', $projectIds);
        });
    }
}
