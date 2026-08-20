<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ClientReminder;
use App\Services\ClientReminderDispatchService;
use Illuminate\Http\Request;

class ClientActivityController extends Controller
{
    private function assertBusiness(Client $client, Request $request): void
    {
        abort_unless((int) $client->business_id === (int) $request->user()->business_id, 404);
    }

    public function storeNote(Client $client, Request $request)
    {
        $this->assertBusiness($client, $request);

        $data = $request->validate([
            'body' => ['required', 'string'],
            'note_type' => ['nullable', 'string', 'max:50'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note = $client->clientNotes()->create([
            'business_id' => $client->business_id,
            'user_id' => $request->user()->id,
            'body' => trim((string) $data['body']),
            'note_type' => trim((string) ($data['note_type'] ?? 'general')) ?: 'general',
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);

        return response()->json(['data' => $this->serializeNote($note->fresh('user'))], 201);
    }

    public function updateNote(Client $client, ClientNote $note, Request $request)
    {
        $this->assertBusiness($client, $request);
        abort_unless((int) $note->client_id === (int) $client->id && (int) $note->business_id === (int) $client->business_id, 404);

        $data = $request->validate([
            'body' => ['sometimes', 'string'],
            'note_type' => ['nullable', 'string', 'max:50'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note->update([
            'body' => array_key_exists('body', $data) ? trim((string) $data['body']) : $note->body,
            'note_type' => array_key_exists('note_type', $data) ? (trim((string) ($data['note_type'] ?? 'general')) ?: 'general') : $note->note_type,
            'is_pinned' => array_key_exists('is_pinned', $data) ? (bool) $data['is_pinned'] : $note->is_pinned,
        ]);

        return response()->json(['data' => $this->serializeNote($note->fresh('user'))]);
    }

    public function destroyNote(Client $client, ClientNote $note, Request $request)
    {
        $this->assertBusiness($client, $request);
        abort_unless((int) $note->client_id === (int) $client->id && (int) $note->business_id === (int) $client->business_id, 404);
        $note->delete();
        return response()->json(['ok' => true]);
    }

    public function storeReminder(Client $client, Request $request)
    {
        $this->assertBusiness($client, $request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'remind_at' => ['required', 'date'],
            'channel' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'in:pending,queued,done,canceled'],
            'is_enabled' => ['sometimes', 'boolean'],
            'lead_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'notify_via' => ['nullable', 'array'],
            'notify_via.*' => ['string', 'in:internal,sms,whatsapp,email'],
        ]);

        $status = (string) ($data['status'] ?? 'pending');
        $reminder = $client->clientReminders()->create([
            'business_id' => $client->business_id,
            'user_id' => $request->user()->id,
            'title' => trim((string) $data['title']),
            'note' => isset($data['note']) ? trim((string) $data['note']) : null,
            'remind_at' => $data['remind_at'],
            'channel' => trim((string) ($data['channel'] ?? 'internal')) ?: 'internal',
            'status' => $status,
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : true,
            'lead_minutes' => isset($data['lead_minutes']) ? max(0, (int) $data['lead_minutes']) : 0,
            'notify_via' => !empty($data['notify_via']) ? array_values(array_unique($data['notify_via'])) : ['internal'],
            'completed_at' => $status === 'done' ? now() : null,
        ]);

        return response()->json(['data' => $this->serializeReminder($reminder->fresh('user'))], 201);
    }

    public function updateReminder(Client $client, ClientReminder $reminder, Request $request)
    {
        $this->assertBusiness($client, $request);
        abort_unless((int) $reminder->client_id === (int) $client->id && (int) $reminder->business_id === (int) $client->business_id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'remind_at' => ['sometimes', 'date'],
            'channel' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'string', 'in:pending,queued,done,canceled'],
            'is_enabled' => ['sometimes', 'boolean'],
            'lead_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'notify_via' => ['nullable', 'array'],
            'notify_via.*' => ['string', 'in:internal,sms,whatsapp,email'],
        ]);

        if (array_key_exists('title', $data)) $reminder->title = trim((string) $data['title']);
        if (array_key_exists('note', $data)) $reminder->note = isset($data['note']) ? trim((string) $data['note']) : null;
        if (array_key_exists('remind_at', $data)) $reminder->remind_at = $data['remind_at'];
        if (array_key_exists('channel', $data)) $reminder->channel = trim((string) ($data['channel'] ?? 'internal')) ?: 'internal';
        if (array_key_exists('status', $data)) {
            $reminder->status = (string) $data['status'];
            $reminder->completed_at = $reminder->status === 'done' ? now() : null;
        }
        if (array_key_exists('is_enabled', $data)) $reminder->is_enabled = (bool) $data['is_enabled'];
        if (array_key_exists('lead_minutes', $data)) $reminder->lead_minutes = isset($data['lead_minutes']) ? max(0, (int) $data['lead_minutes']) : 0;
        if (array_key_exists('notify_via', $data)) $reminder->notify_via = !empty($data['notify_via']) ? array_values(array_unique($data['notify_via'])) : ['internal'];
        $reminder->save();

        return response()->json(['data' => $this->serializeReminder($reminder->fresh('user'))]);
    }

    public function dispatchReminder(Client $client, ClientReminder $reminder, Request $request, ClientReminderDispatchService $dispatchService)
    {
        $this->assertBusiness($client, $request);
        abort_unless((int) $reminder->client_id === (int) $client->id && (int) $reminder->business_id === (int) $client->business_id, 404);

        $result = $dispatchService->dispatch($reminder->fresh(['client.business', 'deliveries']), true);

        return response()->json([
            'ok' => (bool) ($result['processed'] ?? false),
            'message' => $result['message'] ?? null,
            'data' => $this->serializeReminder($reminder->fresh(['user', 'deliveries'])),
        ]);
    }

    public function destroyReminder(Client $client, ClientReminder $reminder, Request $request)
    {
        $this->assertBusiness($client, $request);
        abort_unless((int) $reminder->client_id === (int) $client->id && (int) $reminder->business_id === (int) $client->business_id, 404);
        $reminder->delete();
        return response()->json(['ok' => true]);
    }

    private function serializeNote(ClientNote $note): array
    {
        return [
            'id' => $note->id,
            'client_id' => $note->client_id,
            'business_id' => $note->business_id,
            'user_id' => $note->user_id,
            'author_name' => $note->user?->name,
            'note_type' => $note->note_type,
            'is_pinned' => (bool) $note->is_pinned,
            'body' => $note->body,
            'created_at' => optional($note->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($note->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }

    private function serializeReminder(ClientReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'client_id' => $reminder->client_id,
            'business_id' => $reminder->business_id,
            'user_id' => $reminder->user_id,
            'author_name' => $reminder->user?->name,
            'title' => $reminder->title,
            'note' => $reminder->note,
            'remind_at' => optional($reminder->remind_at)?->format('Y-m-d H:i:s') ?? (string) $reminder->remind_at,
            'channel' => $reminder->channel,
            'status' => $reminder->status,
            'is_enabled' => (bool) $reminder->is_enabled,
            'lead_minutes' => (int) ($reminder->lead_minutes ?? 0),
            'notify_via' => $reminder->notify_via ?: ['internal'],
            'completed_at' => optional($reminder->completed_at)?->format('Y-m-d H:i:s'),
            'deliveries' => $reminder->relationLoaded('deliveries') ? $reminder->deliveries->map(fn ($delivery) => [
                'id' => $delivery->id,
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'recipient' => $delivery->recipient,
                'provider' => $delivery->provider,
                'scheduled_for' => optional($delivery->scheduled_for)?->format('Y-m-d H:i:s'),
                'sent_at' => optional($delivery->sent_at)?->format('Y-m-d H:i:s'),
                'failed_at' => optional($delivery->failed_at)?->format('Y-m-d H:i:s'),
                'error_message' => $delivery->error_message,
            ])->values() : [],
            'created_at' => optional($reminder->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($reminder->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
