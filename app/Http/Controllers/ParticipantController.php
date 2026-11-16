<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupProgressHistory;
use App\Models\Participant;
use App\Models\User;
use App\Notifications\GroupFullNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ParticipantController extends Controller
{
    // GET /api/participants?page=1&search=...
    public function all(Request $request)
    {
        $query = Participant::select(['id', 'group_id', 'name', 'email', 'phone', 'created_at'])
            ->with('group:id,name')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->input('group_id'));
        }

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 15), 100);
            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    // GET /api/groups/{groupId}/participants?page=1
    public function index(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $query = $group->participants()
            ->select(['id', 'group_id', 'name', 'email', 'phone', 'created_at'])
            ->latest();

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 15), 100);
            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    // POST /api/groups/{groupId}/participants
    public function store(Request $request, $groupId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        $result = DB::transaction(function () use ($validated, $groupId) {
            $group = Group::whereKey($groupId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldCount = $group->participants()->count();
            $capacity = (int) $group->capacity;
            $oldPercentage = $capacity > 0 ? ($oldCount / $capacity) * 100 : 0;

            if ($oldCount >= $capacity) {
                $group->update([
                    'current_count' => $oldCount,
                    'status' => 'Completed',
                ]);
                return ['full' => true];
            }

            $participant = $group->participants()->create($validated);

            $newCount = $oldCount + 1;
            $percentage = $capacity > 0 ? ($newCount / $capacity) * 100 : 0;

            $group->current_count = $newCount;
            $group->status = $this->resolveStatus($percentage);
            $group->save();

            GroupProgressHistory::create([
                'group_id' => $group->id,
                'participant_count' => $newCount,
                'percentage' => round($percentage, 2),
            ]);

            return [
                'full' => false,
                'group' => $group,
                'participant' => $participant,
                'old_percentage' => $oldPercentage,
                'percentage' => $percentage,
                'new_count' => $newCount,
            ];
        });

        if ($result['full']) {
            return response()->json([
                'message' => 'This group has reached its maximum capacity.',
            ], 422);
        }

        $group = $result['group'];
        $oldPercentage = $result['old_percentage'];
        $percentage = $result['percentage'];

        if ($oldPercentage < 80 && $percentage >= 80 && $percentage < 100) {
            dispatch(fn () => $this->sendAlerts($group, 'WARNING_80'))->afterResponse();
        }
        if ($oldPercentage < 100 && $percentage >= 100) {
            dispatch(fn () => $this->sendAlerts($group, 'FULL_ALERT'))->afterResponse();
        }

        return response()->json([
            'message' => 'Participant added successfully.',
            'participant' => $result['participant'],
            'group_count' => $result['new_count'],
            'percentage' => round($percentage, 2) . '%',
            'status' => $group->status,
        ], 201);
    }

    // DELETE /api/participants/{id} (Safe transactional deletion keeping current_count synced)
    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $participant = Participant::findOrFail($id);
            $group = Group::whereKey($participant->group_id)
                ->lockForUpdate()
                ->first();

            $participant->delete();

            if ($group) {
                $newCount = $group->participants()->count();
                $percentage = $group->capacity > 0 ? ($newCount / $group->capacity) * 100 : 0;

                $group->current_count = $newCount;
                $group->status = $this->resolveStatus($percentage);
                $group->save();
            }
        });

        return response()->json([
            'message' => 'Participant deleted successfully.',
        ]);
    }

    private function resolveStatus(float $percentage): string
    {
        if ($percentage >= 100) return 'Completed';
        if ($percentage >= 80) return 'Almost Complete';
        if ($percentage >= 50) return 'In Progress';
        return 'Low';
    }

    private function sendAlerts(Group $group, string $alertType): void
    {
        $percentage = $group->capacity > 0
            ? round(($group->current_count / $group->capacity) * 100, 2)
            : 0;

        $n8nWebhookUrl = config('services.n8n.webhook_url', 'https://n8n-ecommerce.duckdns.org/webhook/292cae45-650b-451a-a8d9-dd2f595bcb09');

        try {
            $response = Http::timeout(8)->post($n8nWebhookUrl, [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'capacity' => $group->capacity,
                'current_count' => $group->current_count,
                'percentage' => $percentage,
                'alert_type' => $alertType,
            ]);

            if ($response->failed()) {
                Log::error('n8n webhook returned an error.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'group_id' => $group->id,
                    'alert_type' => $alertType,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('n8n webhook error.', [
                'message' => $e->getMessage(),
                'group_id' => $group->id,
                'alert_type' => $alertType,
            ]);
        }

        $admin = User::first();
        if ($admin) {
            $admin->notify(new GroupFullNotification($group, $alertType));
        }
    }
}