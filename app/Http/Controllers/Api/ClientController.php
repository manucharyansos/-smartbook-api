<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ClientReminder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $business = $request->user()->business;
        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $segment = trim((string) $request->string('segment', ''));
        $group = trim((string) $request->string('group', ''));
        $status = trim((string) $request->string('status', ''));
        $lostThreshold = Carbon::now()->subDays(60)->startOfDay();

        $query = $business->clients()
            ->select('clients.*')
            ->withCount([
                'bookings as bookings_count' => fn (Builder $q) => $q
                    ->where('business_id', $business->id)
                    ->where('status', '!=', 'cancelled'),
            ])
            ->addSelect([
                'last_booking_at' => Booking::query()
                    ->select('starts_at')
                    ->whereColumn('client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->where('status', '!=', 'cancelled')
                    ->orderByDesc('starts_at')
                    ->limit(1),
                'next_booking_at' => Booking::query()
                    ->select('starts_at')
                    ->whereColumn('client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->where('starts_at', '>=', now())
                    ->orderBy('starts_at')
                    ->limit(1),
                'total_spent' => Booking::query()
                    ->selectRaw('COALESCE(SUM(final_price), 0)')
                    ->whereColumn('client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->where('status', '!=', 'cancelled'),
            ]);

        $search = trim((string) $request->string('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('group_name', 'like', "%{$search}%");
            });
        }

        if ($segment === 'vip') {
            $query->where('is_vip', true);
        } elseif ($segment === 'blacklist') {
            $query->where('is_blacklisted', true);
        } elseif ($segment === 'grouped') {
            $query->whereNotNull('group_name')->where('group_name', '!=', '');
        }

        if ($group !== '') {
            $query->where('group_name', $group);
        }

        if ($status === 'new') {
            $query->having('bookings_count', '=', 1);
        } elseif ($status === 'returning') {
            $query->having('bookings_count', '>=', 2);
        } elseif ($status === 'upcoming') {
            $query->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->whereIn('bookings.status', ['pending', 'confirmed'])
                    ->where('bookings.starts_at', '>=', now());
            });
        } elseif ($status === 'lost') {
            $query->whereExists(function ($sub) use ($lostThreshold) {
                $sub->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->where('bookings.status', '!=', 'cancelled')
                    ->where('bookings.starts_at', '<', $lostThreshold);
            })->whereNotExists(function ($sub) use ($lostThreshold) {
                $sub->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->whereColumn('bookings.business_id', 'clients.business_id')
                    ->where('bookings.status', '!=', 'cancelled')
                    ->where('bookings.starts_at', '>=', $lostThreshold);
            });
        }

        $paginator = $query
            ->orderByRaw('CASE WHEN last_booking_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_booking_at')
            ->orderBy('name')
            ->paginate($perPage);

        $data = collect($paginator->items())
            ->map(fn (Client $client) => $this->serializeClient($client))
            ->values();

        $groupCounts = $business->clients()
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->select('group_name', DB::raw('COUNT(*) as total'))
            ->groupBy('group_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'group_name' => (string) $row->group_name,
                'total' => (int) ($row->total ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'meta' => [
                'group_counts' => $groupCounts,
                'vip_count' => (int) $business->clients()->where('is_vip', true)->count(),
                'blacklisted_count' => (int) $business->clients()->where('is_blacklisted', true)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'is_vip' => ['sometimes', 'boolean'],
            'is_blacklisted' => ['sometimes', 'boolean'],
            'blacklist_reason' => ['nullable', 'string'],
            'birth_date' => ['nullable', 'date'],
            'blood_type' => ['nullable', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'medical_history' => ['nullable', 'string'],
            'allergies' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $data['business_id'] = $business->id;
        $data['is_vip'] = (bool) ($data['is_vip'] ?? false);
        $data['is_blacklisted'] = (bool) ($data['is_blacklisted'] ?? false);
        if (!$data['is_blacklisted']) {
            $data['blacklist_reason'] = null;
        }

        $client = Client::create($data);

        return response()->json(['data' => $this->serializeClient($client->fresh())], 201);
    }

    public function show(Client $client, Request $request)
    {
        $business = $request->user()->business;

        if ((int) $client->business_id !== (int) $business->id) {
            abort(404);
        }

        $client->loadCount([
            'bookings as bookings_count' => fn (Builder $q) => $q
                ->where('business_id', $business->id)
                ->where('status', '!=', 'cancelled'),
        ]);

        $recentBookings = $client->bookings()
            ->where('business_id', $business->id)
            ->with(['service', 'staff', 'items.service'])
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get();

        $upcoming = $client->bookings()
            ->where('business_id', $business->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        $lastVisit = $client->bookings()
            ->where('business_id', $business->id)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('starts_at')
            ->first();

        $totalSpent = (int) ($client->bookings()
            ->where('business_id', $business->id)
            ->where('status', '!=', 'cancelled')
            ->sum('final_price') ?? 0);

        $completedCount = (int) $client->bookings()->where('business_id', $business->id)->where('status', 'done')->count();
        $cancelledCount = (int) $client->bookings()->where('business_id', $business->id)->where('status', 'cancelled')->count();
        $noShowCount = (int) $client->bookings()->where('business_id', $business->id)->where('status', 'no_show')->count();
        $avgTicket = $completedCount > 0 ? round($totalSpent / $completedCount, 1) : 0.0;

        $clientNotes = $client->clientNotes()
            ->where('business_id', $business->id)
            ->with('user')
            ->limit(12)
            ->get();
        $clientReminders = $client->clientReminders()
            ->where('business_id', $business->id)
            ->with(['user', 'deliveries'])
            ->limit(12)
            ->get();

        $timeline = collect()
            ->merge($recentBookings->map(function ($booking) {
                return [
                    'id' => 'booking-'.$booking->id,
                    'type' => 'booking',
                    'title' => optional($booking->service)->name ?: 'Booking',
                    'subtitle' => $booking->staff?->name,
                    'status' => $booking->status,
                    'body' => $booking->notes,
                    'occurred_at' => optional($booking->starts_at)->format('Y-m-d H:i:s') ?? (string) $booking->starts_at,
                ];
            }))
            ->merge($clientNotes->map(function (ClientNote $note) {
                return [
                    'id' => 'note-'.$note->id,
                    'type' => 'note',
                    'title' => $note->is_pinned ? 'Pinned note' : 'Client note',
                    'subtitle' => $note->user?->name,
                    'status' => $note->note_type,
                    'body' => $note->body,
                    'occurred_at' => optional($note->created_at)->format('Y-m-d H:i:s'),
                ];
            }))
            ->merge($clientReminders->map(function (ClientReminder $reminder) {
                return [
                    'id' => 'reminder-'.$reminder->id,
                    'type' => 'reminder',
                    'title' => $reminder->title,
                    'subtitle' => $reminder->channel,
                    'status' => $reminder->status,
                    'body' => $reminder->note,
                    'occurred_at' => optional($reminder->remind_at)->format('Y-m-d H:i:s') ?? (string) $reminder->remind_at,
                ];
            }))
            ->sortByDesc('occurred_at')
            ->take(20)
            ->values();

        $favoriteServiceRow = $client->bookings()
            ->where('business_id', $business->id)
            ->select('service_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('service_id')
            ->where('status', '!=', 'cancelled')
            ->groupBy('service_id')
            ->orderByDesc('total')
            ->first();

        $favoriteStaffRow = $client->bookings()
            ->where('business_id', $business->id)
            ->select('staff_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('staff_id')
            ->where('status', '!=', 'cancelled')
            ->groupBy('staff_id')
            ->orderByDesc('total')
            ->first();

        $favoriteSource = $client->bookings()
            ->where('business_id', $business->id)
            ->pluck('source')
            ->map(fn ($source) => trim((string) $source))
            ->map(fn ($source) => $source !== '' ? $source : 'unknown')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $favoriteServiceName = null;
        if ($favoriteServiceRow?->service_id) {
            $favoriteServiceName = optional($client->bookings()
                ->where('business_id', $business->id)
                ->with('service')
                ->where('service_id', $favoriteServiceRow->service_id)
                ->first()?->service)->name;
        }

        $favoriteStaffName = null;
        if ($favoriteStaffRow?->staff_id) {
            $favoriteStaffName = optional($client->bookings()
                ->where('business_id', $business->id)
                ->with('staff')
                ->where('staff_id', $favoriteStaffRow->staff_id)
                ->first()?->staff)->name;
        }

        return response()->json([
            'data' => array_merge($this->serializeClient($client), [
                'recent_bookings' => BookingResource::collection($recentBookings),
                'recent_notes' => $clientNotes->map(fn (ClientNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'note_type' => $note->note_type,
                    'is_pinned' => (bool) $note->is_pinned,
                    'author_name' => $note->user?->name,
                    'created_at' => optional($note->created_at)?->format('Y-m-d H:i:s'),
                ])->values(),
                'reminders' => $clientReminders->map(fn (ClientReminder $reminder) => [
                    'id' => $reminder->id,
                    'title' => $reminder->title,
                    'note' => $reminder->note,
                    'channel' => $reminder->channel,
                    'status' => $reminder->status,
                    'is_enabled' => (bool) $reminder->is_enabled,
                    'lead_minutes' => (int) ($reminder->lead_minutes ?? 0),
                    'notify_via' => $reminder->notify_via ?: ['internal'],
                    'author_name' => $reminder->user?->name,
                    'remind_at' => optional($reminder->remind_at)?->format('Y-m-d H:i:s') ?? (string) $reminder->remind_at,
                    'completed_at' => optional($reminder->completed_at)?->format('Y-m-d H:i:s'),
                    'deliveries' => $reminder->deliveries->map(fn ($delivery) => [
                        'id' => $delivery->id,
                        'channel' => $delivery->channel,
                        'status' => $delivery->status,
                        'recipient' => $delivery->recipient,
                        'provider' => $delivery->provider,
                        'scheduled_for' => optional($delivery->scheduled_for)?->format('Y-m-d H:i:s'),
                        'sent_at' => optional($delivery->sent_at)?->format('Y-m-d H:i:s'),
                        'failed_at' => optional($delivery->failed_at)?->format('Y-m-d H:i:s'),
                        'error_message' => $delivery->error_message,
                    ])->values(),
                ])->values(),
                'timeline' => $timeline,
                'last_booking_at' => optional($lastVisit?->starts_at)->format('Y-m-d H:i:s') ?? ($lastVisit?->starts_at),
                'next_booking_at' => optional($upcoming?->starts_at)->format('Y-m-d H:i:s') ?? ($upcoming?->starts_at),
                'total_spent' => $totalSpent,
                'status_segment' => $this->statusSegment($client, $lastVisit?->starts_at, $upcoming?->starts_at),
                'crm' => [
                    'completed_count' => $completedCount,
                    'cancelled_count' => $cancelledCount,
                    'no_show_count' => $noShowCount,
                    'avg_ticket' => $avgTicket,
                    'favorite_service_name' => $favoriteServiceName,
                    'favorite_staff_name' => $favoriteStaffName,
                    'favorite_source' => $favoriteSource ? (string) $favoriteSource : null,
                    'last_source' => $lastVisit?->source,
                    'linked_account' => (bool) $client->client_account_id,
                ],
            ]),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $business = $request->user()->business;

        if ((int) $client->business_id !== (int) $business->id) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'is_vip' => ['sometimes', 'boolean'],
            'is_blacklisted' => ['sometimes', 'boolean'],
            'blacklist_reason' => ['nullable', 'string'],
            'birth_date' => ['nullable', 'date'],
            'blood_type' => ['nullable', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'medical_history' => ['nullable', 'string'],
            'allergies' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ]);

        if (array_key_exists('is_blacklisted', $data) && !$data['is_blacklisted']) {
            $data['blacklist_reason'] = null;
        }

        $client->update($data);

        return response()->json(['data' => $this->serializeClient($client->fresh())]);
    }

    public function bookings(Client $client, Request $request)
    {
        $business = $request->user()->business;

        if ((int) $client->business_id !== (int) $business->id) {
            abort(404);
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $bookings = $client->bookings()
            ->where('business_id', $business->id)
            ->with(['service', 'staff', 'items.service'])
            ->orderByDesc('starts_at')
            ->paginate($perPage);

        $bookings->setCollection(
            BookingResource::collection($bookings->getCollection())->collection
        );

        return response()->json($bookings);
    }

    private function statusSegment(Client $client, $lastBookingAt, $nextBookingAt): string
    {
        $last = $lastBookingAt ? Carbon::parse($lastBookingAt) : null;
        $next = $nextBookingAt ? Carbon::parse($nextBookingAt) : null;

        if ($client->is_blacklisted) {
            return 'blacklisted';
        }
        if ($next) {
            return 'upcoming';
        }
        if (($client->bookings_count ?? 0) <= 1) {
            return 'new';
        }
        if ($last && $last->lt(now()->subDays(60))) {
            return 'lost';
        }
        if (($client->bookings_count ?? 0) >= 2) {
            return 'returning';
        }

        return 'active';
    }

    private function serializeClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'business_id' => $client->business_id,
            'client_account_id' => $client->client_account_id,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'notes' => $client->notes,
            'group_name' => $client->group_name,
            'is_vip' => (bool) $client->is_vip,
            'is_blacklisted' => (bool) $client->is_blacklisted,
            'blacklist_reason' => $client->blacklist_reason,
            'birth_date' => optional($client->birth_date)?->format('Y-m-d'),
            'blood_type' => $client->blood_type,
            'medical_history' => $client->medical_history,
            'allergies' => $client->allergies,
            'emergency_contact_name' => $client->emergency_contact_name,
            'emergency_contact_phone' => $client->emergency_contact_phone,
            'loyalty_points_balance' => (int) ($client->loyalty_points_balance ?? 0),
            'bookings_count' => (int) ($client->bookings_count ?? 0),
            'total_spent' => (int) ($client->total_spent ?? 0),
            'last_booking_at' => isset($client->last_booking_at) ? (string) $client->last_booking_at : null,
            'next_booking_at' => isset($client->next_booking_at) ? (string) $client->next_booking_at : null,
            'status_segment' => $this->statusSegment($client, $client->last_booking_at ?? null, $client->next_booking_at ?? null),
            'created_at' => optional($client->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($client->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
