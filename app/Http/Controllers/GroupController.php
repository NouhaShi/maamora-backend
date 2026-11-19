<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    // GET /api/groups
    public function index(Request $request)
    {
        $query = Group::select([
    'id',
    'name',
    'capacity',
    'current_count',
    'status',
    'created_at'
           ])->latest();

        // Optional server-side search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Optional server-side status filter
        if ($request->filled('status') && $request->input('status') !== 'All Status') {
            $query->where('status', $request->input('status'));
        }

        $transform = function ($group) {
            $count = (int) $group->current_count;
            $capacity = (int) $group->capacity;

            return [
                'id' => $group->id,
                'name' => $group->name,
                'capacity' => $capacity,
                'current_count' => $count,
                'percentage' => $capacity > 0
                    ? round(($count / $capacity) * 100, 2)
                    : 0,
                'created_at' => $group->created_at,
                'status' => $this->getStatus($count, $capacity),
            ];
        };

        // Support pagination when ?page= or ?per_page= is passed, while keeping backward compatibility
       $perPage = max(1, min((int) $request->input('per_page', 10), 100));

       return response()->json($query->paginate($perPage));
       }

    // GET /api/groups/{id}
    public function show($id)
    {
        $group = Group::select([
    'id',
    'name',
    'capacity',
    'current_count',
    'status',
    'created_at'
])->findOrFail($id);

        $count = (int) $group->current_count;
        $capacity = (int) $group->capacity;

        return response()->json([
            'id' => $group->id,
            'name' => $group->name,
            'capacity' => $capacity,
            'current_count' => $count,
            'percentage' => $capacity > 0
                ? round(($count / $capacity) * 100, 2)
                : 0,
            'created_at' => $group->created_at,
            'status' => $this->getStatus($count, $capacity),
        ]);
    }

    // POST /api/groups
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
        ]);

        $group = Group::create([
            'name' => $validated['name'],
            'capacity' => $validated['capacity'],
            'current_count' => 0,
            'status' => 'Low',
        ]);

        return response()->json([
            'message' => 'Group created successfully.',
            'group' => $group,
            'current_count' => 0,
            'percentage' => 0,
            'status' => 'Low',
        ], 201);
    }

    // PUT /api/groups/{id}
      public function update(Request $request, $id)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'capacity' => 'required|integer|min:1',
    ]);

    return DB::transaction(function () use ($validated, $id) {

        $group = Group::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();

        $actualCount = $group->participants()->count();

        if ($validated['capacity'] < $actualCount) {
            return response()->json([
                'message' => 'Capacity cannot be lower than the current number of participants.',
            ], 422);
        }

        $group->name = $validated['name'];
        $group->capacity = $validated['capacity'];
        $group->current_count = $actualCount;
        $group->status = $this->getStatus($actualCount, $group->capacity);
        $group->save();

        return response()->json([
            'message' => 'Group updated successfully.',
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'capacity' => (int) $group->capacity,
                'current_count' => $actualCount,
                'percentage' => $group->capacity > 0
                    ? round(($actualCount / $group->capacity) * 100, 2)
                    : 0,
                'created_at' => $group->created_at,
                'status' => $group->status,
            ],
        ]);
    });
}

    // DELETE /api/groups/{id}
    public function destroy($id)
    {
        $group = Group::findOrFail($id);

        // Check actual participants existence with fast EXISTS query instead of full COUNT
        if ($group->participants()->exists()) {
            return response()->json([
                'message' => 'This group cannot be deleted because it has participants.',
            ], 422);
        }

        $group->delete();

        return response()->json([
            'message' => 'Group deleted successfully.',
        ]);
    }

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
