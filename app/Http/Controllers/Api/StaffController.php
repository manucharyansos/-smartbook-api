<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use App\Support\InteractsWithOptionalLocationColumns;

class StaffController extends Controller
{
    use InteractsWithOptionalLocationColumns;
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $q = User::query()->with('location')->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_STAFF]);
        if ($actor->role === User::ROLE_SUPER_ADMIN) {
            abort_if(empty($validated['business_id']), 422, 'business_id is required');
            $q->where('business_id', (int) $validated['business_id']);
        } else {
            $q->where('business_id', $actor->business_id);
        }

        if (!empty($validated['location_id'])) {
            $this->applyTableLocationCompatibility($q, (int) $validated['location_id'], 'users');
        }

        if ($request->has('only_active') && $request->boolean('only_active', true)) {
            $q->where('is_active', true);
        }

        return response()->json([
            'data' => $q->orderBy('id')->get($this->filterColumnsForOptionalLocation('users', ['id','name','email','phone','whatsapp_phone','telegram_chat_id','avatar_url','bio','role','business_id','location_id','is_active','show_in_public_team','is_bookable','deactivated_at']))
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required','string','min:2','max:120'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','string','min:8','max:255'],
            'role' => ['nullable','in:staff,manager'],
            'phone' => ['nullable','string','max:40'],
            'whatsapp_phone' => ['nullable','string','max:40'],
            'telegram_chat_id' => ['nullable','string','max:80'],
            'avatar_url' => ['nullable','string','max:2048'],
            'bio' => ['nullable','string','max:2000'],
            'show_in_public_team' => ['nullable', 'boolean'],
            'is_bookable' => ['nullable', 'boolean'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $role = $data['role'] ?? User::ROLE_STAFF;
        $business = $actor->business()->with('subscription.plan')->first();

        if ($role === User::ROLE_STAFF && $business && !$business->hasAvailableSeat()) {
            return response()->json([
                'message' => 'Active staff limit reached. Upgrade the plan or deactivate another staff member.',
                'limit' => $business->seatLimit(),
                'current' => $business->activeSeatCount(),
            ], 409);
        }

        $showInPublicTeam = array_key_exists('show_in_public_team', $data)
            ? (bool) $data['show_in_public_team']
            : $role === User::ROLE_STAFF;

        $isBookable = array_key_exists('is_bookable', $data)
            ? (bool) $data['is_bookable']
            : $role === User::ROLE_STAFF;

        if ($isBookable) {
            $showInPublicTeam = true;
        }

        $locationId = $this->resolveLocationId((int) $actor->business_id, $data['location_id'] ?? null, true);

        $staffPayload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'business_id' => $actor->business_id,
            'location_id' => $locationId,
            'phone' => $data['phone'] ?? null,
            'whatsapp_phone' => $data['whatsapp_phone'] ?? null,
            'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'bio' => $data['bio'] ?? null,
            'show_in_public_team' => $showInPublicTeam,
            'is_bookable' => $isBookable,
        ];

        $staff = User::create($this->withoutUnavailableLocationAttribute($staffPayload, 'users'));

        return response()->json(['data' => $staff->load('location')], 201);
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            abort(403);
        }
        if (!$actor->isSuperAdmin() && (int) $user->business_id !== (int) $actor->business_id) {
            abort(404);
        }
        abort_unless(in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_STAFF], true), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'role' => ['sometimes', 'required', 'in:staff,manager,owner'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone' => ['nullable', 'string', 'max:40'],
            'telegram_chat_id' => ['nullable', 'string', 'max:80'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'show_in_public_team' => ['nullable', 'boolean'],
            'is_bookable' => ['nullable', 'boolean'],
            'location_id' => ['nullable', 'integer'],
        ]);

        if (($data['role'] ?? null) === User::ROLE_OWNER && !$actor->isSuperAdmin()) {
            unset($data['role']);
        }

        $incomingRole = $data['role'] ?? $user->role;
        $willConsumeSeat = $incomingRole === User::ROLE_STAFF;
        $currentlyConsumesSeat = $user->role === User::ROLE_STAFF && $user->is_active;
        $needsExtraSeat = $willConsumeSeat && !$currentlyConsumesSeat;

        if ($needsExtraSeat) {
            $business = $actor->business()->with('subscription.plan')->first();
            if ($business && !$business->hasAvailableSeat()) {
                return response()->json([
                    'message' => 'Active staff limit reached. Upgrade the plan or deactivate another staff member.',
                    'limit' => $business->seatLimit(),
                    'current' => $business->activeSeatCount(),
                ], 409);
            }
        }

        if (array_key_exists('is_bookable', $data) && (bool) $data['is_bookable']) {
            $data['show_in_public_team'] = true;
        }

        if (array_key_exists('show_in_public_team', $data) && !(bool) $data['show_in_public_team']) {
            $data['is_bookable'] = false;
        }

        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = $this->resolveLocationId((int) $user->business_id, $data['location_id'], true);
        }

        $data = $this->withoutUnavailableLocationAttribute($data, 'users');

        $user->update($data);
        return response()->json(['data' => $user->fresh()->load('location')]);
    }

    public function deactivate(Request $request, User $user)
    {
        Gate::authorize('deactivate', $user);
        if ($user->is_active === false) return response()->json(['ok' => true]);
        $user->update(['is_active' => false, 'deactivated_at' => now()]);
        $user->tokens()?->delete();
        return response()->json(['ok' => true]);
    }

    public function activate(Request $request, User $user)
    {
        Gate::authorize('deactivate', $user);

        $actor = $request->user();
        $business = $actor?->business()->with('subscription.plan')->first();

        if ($user->role === User::ROLE_STAFF && $business && !$business->hasAvailableSeat()) {
            return response()->json([
                'message' => 'Active staff limit reached. Upgrade the plan or deactivate another staff member.',
                'limit' => $business->seatLimit(),
                'current' => $business->activeSeatCount(),
            ], 409);
        }

        $user->update(['is_active' => true, 'deactivated_at' => null]);
        return response()->json(['ok' => true]);
    }


    private function resolveLocationId(int $businessId, ?int $locationId, bool $requireSpecificWhenMultiple = false): ?int
    {
        if (!$this->usersHaveLocationColumn()) {
            return null;
        }

        $locations = BusinessLocation::query()
            ->where('business_id', $businessId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id']);

        if (!$locationId) {
            if ($locations->count() === 1) {
                return (int) $locations->first()->id;
            }

            if ($requireSpecificWhenMultiple && $locations->count() > 1) {
                abort(422, 'Choose a specific location for this staff member.');
            }

            return null;
        }

        $exists = $locations->contains(fn ($location) => (int) $location->id === (int) $locationId);

        abort_unless($exists, 422, 'Invalid location');

        return (int) $locationId;
    }
}
