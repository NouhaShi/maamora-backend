<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // GET /api/notifications?page=1&tab=all&search=...
    public function index(Request $request)
    {
        // 1. Aggregate stats across all notifications in one fast SQL query
        $stats = DB::table('notifications')->selectRaw("
            COUNT(*) as total,
            COUNT(CASE WHEN (data::jsonb->>'alert_type') = 'FULL_ALERT' OR COALESCE((data::jsonb->>'percentage')::numeric, 0) >= 100 THEN 1 END) as completed,
            COUNT(CASE WHEN COALESCE((data::jsonb->>'alert_type'), '') != 'FULL_ALERT' AND COALESCE((data::jsonb->>'percentage')::numeric, 0) < 100 THEN 1 END) as warning
        ")->first();

        // 2. Filtered & paginated query
        $query = DB::table('notifications')
            ->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->orderByDesc('created_at');

        $tab = $request->input('tab', 'all');
        if ($tab === 'completed') {
            $query->whereRaw("(data::jsonb->>'alert_type') = 'FULL_ALERT' OR COALESCE((data::jsonb->>'percentage')::numeric, 0) >= 100");
        } elseif ($tab === 'warning') {
            $query->whereRaw("COALESCE((data::jsonb->>'alert_type'), '') != 'FULL_ALERT' AND COALESCE((data::jsonb->>'percentage')::numeric, 0) < 100");
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereRaw("(data::jsonb->>'group_name') ILIKE ?", ["%{$search}%"]);
        }

        $perPage = min((int) $request->input('per_page', 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'warning' => (int) ($stats->warning ?? 0),
                'completed' => (int) ($stats->completed ?? 0),
            ],
            'notifications' => $paginated,
        ]);
    }

    // GET /api/dashboard/notifications
    public function latest()
    {
        $notifications = DB::table('notifications')
            ->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json($notifications);
    }
}