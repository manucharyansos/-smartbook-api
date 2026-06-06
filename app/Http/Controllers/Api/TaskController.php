<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $this->ensureBusinessContext($actor);

        $base = Task::query()
            ->with([
                'assignee:id,name,email,avatar_url',
                'creator:id,name',
                'client:id,name,phone',
                'booking:id,starts_at,status',
            ]);

        if ($actor->role === User::ROLE_STAFF) {
            $base->where('assignee_id', $actor->id);
        }

        $summaryBase = clone $base;
        $q = clone $base;

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        if ($priority = $request->string('priority')->toString()) {
            $q->where('priority', $priority);
        }

        if ($assigneeId = (int)$request->integer('assignee_id')) {
            $q->where('assignee_id', $assigneeId);
        }

        if ($request->boolean('overdue')) {
            $q->whereIn('status', [Task::STATUS_OPEN, Task::STATUS_IN_PROGRESS])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now());
        }

        if ($search = trim((string)$request->query('search', ''))) {
            $q->where(function (Builder $sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tasks = $q
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'open' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $tasks->map(fn (Task $task) => $this->serializeTask($task)),
            'meta' => [
                'summary' => [
                    'total' => (clone $summaryBase)->count(),
                    'open' => (clone $summaryBase)->where('status', Task::STATUS_OPEN)->count(),
                    'in_progress' => (clone $summaryBase)->where('status', Task::STATUS_IN_PROGRESS)->count(),
                    'completed' => (clone $summaryBase)->where('status', Task::STATUS_COMPLETED)->count(),
                    'overdue' => (clone $summaryBase)
                        ->whereIn('status', [Task::STATUS_OPEN, Task::STATUS_IN_PROGRESS])
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now())
                        ->count(),
                    'due_today' => (clone $summaryBase)
                        ->whereDate('due_at', now()->toDateString())
                        ->whereIn('status', [Task::STATUS_OPEN, Task::STATUS_IN_PROGRESS])
                        ->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $this->ensureBusinessContext($actor);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in($this->statuses())],
            'priority' => ['nullable', Rule::in($this->priorities())],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $this->assertRelatedBelongsToBusiness($actor, $data);

        $task = new Task();
        $task->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Task::STATUS_OPEN,
            'priority' => $data['priority'] ?? Task::PRIORITY_MEDIUM,
            'assignee_id' => $data['assignee_id'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'created_by_user_id' => $actor->id,
        ]);

        if ($task->status === Task::STATUS_COMPLETED && !$task->completed_at) {
            $task->completed_at = now();
        }

        $task->save();
        $task->load(['assignee:id,name,email,avatar_url', 'creator:id,name', 'client:id,name,phone', 'booking:id,starts_at,status']);

        return response()->json([
            'data' => $this->serializeTask($task),
        ], 201);
    }

    public function update(Request $request, Task $task)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $this->ensureBusinessContext($actor);

        if (!$actor->isSuperAdmin() && (int)$task->business_id !== (int)$actor->business_id) {
            abort(404);
        }

        if ($actor->role === User::ROLE_STAFF) {
            if ((int)$task->assignee_id !== (int)$actor->id) {
                abort(403);
            }

            $data = $request->validate([
                'status' => ['required', Rule::in($this->statuses())],
            ]);

            $task->status = $data['status'];
            $task->completed_at = $task->status === Task::STATUS_COMPLETED ? now() : null;
            $task->save();
        } else {
            $data = $request->validate([
                'title' => ['sometimes', 'required', 'string', 'max:160'],
                'description' => ['nullable', 'string', 'max:5000'],
                'status' => ['nullable', Rule::in($this->statuses())],
                'priority' => ['nullable', Rule::in($this->priorities())],
                'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
                'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
                'client_id' => ['nullable', 'integer', 'exists:clients,id'],
                'due_at' => ['nullable', 'date'],
            ]);

            $this->assertRelatedBelongsToBusiness($actor, $data);

            $task->fill($data);
            if (array_key_exists('status', $data)) {
                $task->completed_at = $data['status'] === Task::STATUS_COMPLETED ? now() : null;
            }
            $task->save();
        }

        $task->load(['assignee:id,name,email,avatar_url', 'creator:id,name', 'client:id,name,phone', 'booking:id,starts_at,status']);

        return response()->json([
            'data' => $this->serializeTask($task),
        ]);
    }

    public function destroy(Request $request, Task $task)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $this->ensureBusinessContext($actor);

        if (!$actor->isSuperAdmin() && (int)$task->business_id !== (int)$actor->business_id) {
            abort(404);
        }

        $task->delete();

        return response()->json(['ok' => true]);
    }

    private function statuses(): array
    {
        return [Task::STATUS_OPEN, Task::STATUS_IN_PROGRESS, Task::STATUS_COMPLETED, Task::STATUS_CANCELED];
    }

    private function priorities(): array
    {
        return [Task::PRIORITY_LOW, Task::PRIORITY_MEDIUM, Task::PRIORITY_HIGH, Task::PRIORITY_URGENT];
    }

    private function ensureBusinessContext($actor): void
    {
        if (!empty($actor->business_id)) {
            return;
        }

        abort(403, 'Tasks are available only inside business context.');
    }

    private function assertRelatedBelongsToBusiness($actor, array $data): void
    {
        if ($actor->isSuperAdmin()) return;

        foreach (['assignee_id' => User::class, 'booking_id' => \App\Models\Booking::class, 'client_id' => \App\Models\Client::class] as $field => $model) {
            if (empty($data[$field])) continue;
            $row = $model::query()->find($data[$field]);
            if (!$row || (int)$row->business_id !== (int)$actor->business_id) {
                abort(404);
            }
        }
    }

    private function serializeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => optional($task->due_at)->toISOString(),
            'completed_at' => optional($task->completed_at)->toISOString(),
            'is_overdue' => $task->due_at && !$task->completed_at && in_array($task->status, [Task::STATUS_OPEN, Task::STATUS_IN_PROGRESS], true) && $task->due_at->isPast(),
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
                'email' => $task->assignee->email,
                'avatar_url' => $task->assignee->avatar_url,
            ] : null,
            'creator' => $task->creator ? [
                'id' => $task->creator->id,
                'name' => $task->creator->name,
            ] : null,
            'client' => $task->client ? [
                'id' => $task->client->id,
                'name' => $task->client->name,
                'phone' => $task->client->phone,
            ] : null,
            'booking' => $task->booking ? [
                'id' => $task->booking->id,
                'starts_at' => optional($task->booking->starts_at)->toISOString(),
                'status' => $task->booking->status,
            ] : null,
            'created_at' => optional($task->created_at)->toISOString(),
            'updated_at' => optional($task->updated_at)->toISOString(),
        ];
    }
}
