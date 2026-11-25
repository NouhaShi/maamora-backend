<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupProgressHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1. Global statistics
        $stats = Cache::remember('dashboard:global_stats', 30, function () {
    return Group::selectRaw("
        COUNT(*) as total_groups,
        COALESCE(SUM(current_count), 0) as total_participants,
        COALESCE(SUM(capacity), 0) as total_capacity,

        COUNT(CASE
            WHEN capacity <= 0
            OR (current_count * 100.0 / NULLIF(capacity, 0)) < 50
            THEN 1
        END) as low_groups_count,

        COUNT(CASE
            WHEN capacity > 0
            AND (current_count * 100.0 / NULLIF(capacity, 0)) >= 50
            AND (current_count * 100.0 / NULLIF(capacity, 0)) < 80
            THEN 1
        END) as in_progress_groups_count,

        COUNT(CASE
            WHEN capacity > 0
            AND (current_count * 100.0 / NULLIF(capacity, 0)) >= 80
            AND (current_count * 100.0 / NULLIF(capacity, 0)) < 100
            THEN 1
        END) as almost_complete_groups_count,

        COUNT(CASE
            WHEN capacity > 0
            AND current_count >= capacity
            THEN 1
        END) as completed_groups_count
    ")->first()->toArray();
});

        $totalGroups = (int) ($stats['total_groups'] ?? 0);
        $totalParticipants = (int) ($stats['total_participants'] ?? 0);
        $totalCapacity = (int) ($stats['total_capacity'] ?? 0);
        $paginatedGroups = [];
        if (!$request->boolean('groups_only')) {
        // 2. Groups query with server-side search and status filter
        $groupsQuery = Group::select([
            'id',
            'name',
            'capacity',
            'current_count',
            'created_at',
        ])->latest();

        // Search by group name
        if ($request->filled('search')) {
            $search = $request->input('search');

            $groupsQuery->where(
                'name',
                'ilike',
                "%{$search}%"
            );
        }

        // Filter by status
        if (
            $request->filled('status') &&
            $request->input('status') !== 'All Status'
        ) {
            $status = $request->input('status');

            if ($status === 'Completed') {
                $groupsQuery
                    ->where('capacity', '>', 0)
                    ->whereColumn('current_count', '>=', 'capacity');

            } elseif ($status === 'Almost Complete') {
                $groupsQuery->whereRaw("
                    capacity > 0
                    AND (current_count * 100.0 / NULLIF(capacity, 0)) >= 80
                    AND (current_count * 100.0 / NULLIF(capacity, 0)) < 100
                ");

            } elseif ($status === 'In Progress') {
                $groupsQuery->whereRaw("
                    capacity > 0
                    AND (current_count * 100.0 / NULLIF(capacity, 0)) >= 50
                    AND (current_count * 100.0 / NULLIF(capacity, 0)) < 80
                ");

            } elseif ($status === 'Low') {
                $groupsQuery->where(function ($query) {
                    $query
                        ->where('capacity', '<=', 0)
                        ->orWhereRaw("
                            (current_count * 100.0 / NULLIF(capacity, 0)) < 50
                        ");
                });
            }
        }

        $paginatedGroups = $groupsQuery->paginate(5);

        // Return the same status format for every group
        $paginatedGroups->getCollection()->transform(
            function ($group) {
                $count = (int) $group->current_count;
                $capacity = (int) $group->capacity;

                $percentage = $capacity > 0
                    ? round(($count / $capacity) * 100, 2)
                    : 0;

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'capacity' => $capacity,
                    'current_count' => $count,
                    'percentage' => $percentage,
                    'status' => $this->getStatus($count, $capacity),
                    'created_at' => $group->created_at,
                ];
            }
        );
        }
        // 3. Latest 5 notifications
        $notifications = $request->boolean('groups_only')
            ? []
            : DB::table('notifications')
                ->select([
                    'id',
                    'type',
                    'data',
                    'read_at',
                    'created_at',
                ])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

        // 4. Dashboard response
        return response()->json([
            'total_groups' => $totalGroups,
            'total_participants' => $totalParticipants,
            'total_capacity' => $totalCapacity,

            'overall_fill_percentage' => $totalCapacity > 0
                ? round(($totalParticipants / $totalCapacity) * 100, 2)
                : 0,

            'low_groups_count' => (int) ($stats['low_groups_count'] ?? 0),
            'in_progress_groups_count' => (int) ($stats['in_progress_groups_count'] ?? 0),
            'almost_complete_groups_count' => (int) ($stats['almost_complete_groups_count'] ?? 0),
            'completed_groups_count' => (int) ($stats['completed_groups_count'] ?? 0),

            'groups' => $paginatedGroups,
            'notifications' => $notifications,
        ]);
    }

    // GET /api/dashboard/progress-history
    public function progressHistory(Request $request)
    {
        $query = GroupProgressHistory::with('group:id,name')
            ->orderByDesc('created_at')
            ->limit(50);

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->input('group_id'));
        }

        $history = $query->get()->reverse()->values();

        return response()->json(
            $history->map(function ($item) {
                return [
                    'group_id' => $item->group_id,
                    'group_name' => $item->group?->name,
                    'participant_count' => $item->participant_count,
                    'percentage' => $item->percentage,
                    'created_at' => $item->created_at,
                ];
            })
        );
    }

    // Calculate group status using the same rules as GroupController
    private function getStatus(int $count, int $capacity): string
    {
        $percentage = $capacity > 0
            ? ($count / $capacity) * 100
            : 0;

        if ($percentage >= 100) {
            return 'Completed';
        }

        if ($percentage >= 80) {
            return 'Almost Complete';
        }

        if ($percentage >= 50) {
            return 'In Progress';
        }

        return 'Low';
    }
}
