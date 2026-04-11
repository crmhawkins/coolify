<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\MonitorAlertsService;
use Illuminate\Http\JsonResponse;

/**
 * JSON endpoint consumed by the alerts bell in the top bar.
 *
 * The bell is an Alpine component that polls this route every
 * 30 seconds while closed and 10 seconds while open — the same
 * pattern the compression tasks dropdown uses. Returning a
 * pre-aggregated payload from the server keeps the frontend
 * dead simple (no Livewire, no model instances, no per-row
 * lazy loading).
 *
 * Auth: non-client users only. Clients never see the bell in
 * the first place (it is hidden behind an @unless in the
 * layout), and a hand-typed GET request would 403 here too.
 */
class MonitorAlertsController extends Controller
{
    public function __invoke(MonitorAlertsService $alerts): JsonResponse
    {
        if (auth()->user()?->isClient()) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $servers = Server::ownedByCurrentTeamCached();
        $teamId = (int) (currentTeam()?->id ?? 0);

        $payload = $alerts->summaryFor(collect($servers), $teamId);

        return response()->json($payload);
    }
}
