<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DentalToothRecord;
use App\Models\DentalTreatmentRecord;
use Illuminate\Http\Request;

class DentalClientController extends Controller
{
    public function upsertProfile(Request $request, Client $client)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental profile is only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'chief_complaint' => ['nullable', 'string'],
            'dental_history' => ['nullable', 'string'],
            'current_medications' => ['nullable', 'string'],
            'treatment_alerts' => ['nullable', 'string'],
            'insurance_provider' => ['nullable', 'string', 'max:255'],
            'insurance_number' => ['nullable', 'string', 'max:255'],
            'preferred_doctor' => ['nullable', 'string', 'max:255'],
            'pain_level' => ['nullable', 'integer', 'min:0', 'max:10'],
            'oral_hygiene_status' => ['nullable', 'in:good,fair,poor'],
            'periodontal_risk' => ['nullable', 'in:low,medium,high'],
            'last_xray_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);

        $profile = $client->dentalProfile()->updateOrCreate(
            ['client_id' => $client->id],
            array_merge($data, ['business_id' => $business->id])
        );

        return response()->json([
            'data' => $this->serializeProfile($profile->fresh()),
        ]);
    }

    public function storeTreatment(Request $request, Client $client)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental records are only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $this->validateTreatment($request);
        $data['business_id'] = $business->id;
        $data['client_id'] = $client->id;
        $data['created_by_user_id'] = $request->user()->id;
        $data['performed_by_user_id'] = $data['performed_by_user_id'] ?? $request->user()->id;

        $record = DentalTreatmentRecord::create($data);

        return response()->json(['data' => $this->serializeTreatment($record->fresh())], 201);
    }

    public function updateTreatment(Request $request, Client $client, DentalTreatmentRecord $record)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental records are only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id || (int) $record->business_id !== (int) $business->id || (int) $record->client_id !== (int) $client->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $this->validateTreatment($request, true);
        $record->update($data);

        return response()->json(['data' => $this->serializeTreatment($record->fresh())]);
    }

    public function destroyTreatment(Request $request, Client $client, DentalTreatmentRecord $record)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental records are only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id || (int) $record->business_id !== (int) $business->id || (int) $record->client_id !== (int) $client->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $record->delete();

        return response()->json(['ok' => true]);
    }

    public function upsertToothRecord(Request $request, Client $client, string $tooth)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental chart is only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $tooth = strtoupper(trim($tooth));
        if (!preg_match('/^(?:[1-4][1-8]|[5-8][1-5])$/', $tooth)) {
            return response()->json(['message' => 'Invalid tooth number.'], 422);
        }

        $data = $request->validate([
            'status' => ['nullable', 'in:healthy,attention,planned,treated,monitoring,missing'],
            'condition_label' => ['nullable', 'string', 'max:255'],
            'surface_summary' => ['nullable', 'array'],
            'surface_summary.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'last_treated_at' => ['nullable', 'date'],
            'next_action_due_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:routine,urgent,emergency'],
        ]);

        $record = DentalToothRecord::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'tooth_number' => $tooth,
            ],
            array_merge($data, [
                'business_id' => $business->id,
                'tooth_number' => $tooth,
                'updated_by_user_id' => $request->user()->id,
                'created_by_user_id' => DentalToothRecord::query()
                    ->where('client_id', $client->id)
                    ->where('tooth_number', $tooth)
                    ->value('created_by_user_id') ?: $request->user()->id,
            ])
        );

        return response()->json(['data' => self::serializeToothRecord($record->fresh())]);
    }

    public function destroyToothRecord(Request $request, Client $client, DentalToothRecord $record)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Dental chart is only available for dental businesses.'], 422);
        }

        if ((int) $client->business_id !== (int) $business->id || (int) $record->business_id !== (int) $business->id || (int) $record->client_id !== (int) $client->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $record->delete();

        return response()->json(['ok' => true]);
    }

    private function validateTreatment(Request $request, bool $partial = false): array
    {
        $rules = [
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'performed_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'visit_date' => ['nullable', 'date'],
            'procedure_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'procedure_code' => ['nullable', 'string', 'max:255'],
            'diagnosis' => ['nullable', 'string', 'max:255'],
            'treated_teeth' => ['nullable', 'array'],
            'treated_teeth.*' => ['string', 'max:50'],
            'surfaces' => ['nullable', 'array'],
            'surfaces.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'treatment_status' => ['nullable', 'in:planned,in_progress,completed,cancelled'],
            'priority' => ['nullable', 'in:routine,urgent,emergency'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'follow_up_at' => ['nullable', 'date'],
        ];

        return $request->validate($rules);
    }

    public static function serializeProfile($profile): ?array
    {
        if (!$profile) {
            return null;
        }

        return [
            'id' => $profile->id,
            'chief_complaint' => $profile->chief_complaint,
            'dental_history' => $profile->dental_history,
            'current_medications' => $profile->current_medications,
            'treatment_alerts' => $profile->treatment_alerts,
            'insurance_provider' => $profile->insurance_provider,
            'insurance_number' => $profile->insurance_number,
            'preferred_doctor' => $profile->preferred_doctor,
            'pain_level' => $profile->pain_level,
            'oral_hygiene_status' => $profile->oral_hygiene_status,
            'periodontal_risk' => $profile->periodontal_risk,
            'last_xray_at' => optional($profile->last_xray_at)?->format('Y-m-d H:i:s'),
            'next_follow_up_at' => optional($profile->next_follow_up_at)?->format('Y-m-d H:i:s'),
            'created_at' => optional($profile->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($profile->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }

    public static function serializeTreatment(DentalTreatmentRecord $record): array
    {
        return [
            'id' => $record->id,
            'booking_id' => $record->booking_id,
            'performed_by_user_id' => $record->performed_by_user_id,
            'visit_date' => optional($record->visit_date)?->format('Y-m-d H:i:s'),
            'procedure_name' => $record->procedure_name,
            'procedure_code' => $record->procedure_code,
            'diagnosis' => $record->diagnosis,
            'treated_teeth' => collect($record->treated_teeth ?: [])->map(fn ($item) => (string) $item)->values(),
            'surfaces' => collect($record->surfaces ?: [])->map(fn ($item) => (string) $item)->values(),
            'notes' => $record->notes,
            'recommendation' => $record->recommendation,
            'treatment_status' => $record->treatment_status,
            'priority' => $record->priority,
            'cost' => $record->cost,
            'follow_up_at' => optional($record->follow_up_at)?->format('Y-m-d H:i:s'),
            'created_at' => optional($record->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($record->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }

    public static function serializeToothRecord(DentalToothRecord $record): array
    {
        return [
            'id' => $record->id,
            'tooth_number' => (string) $record->tooth_number,
            'status' => $record->status,
            'condition_label' => $record->condition_label,
            'surface_summary' => collect($record->surface_summary ?: [])->map(fn ($item) => (string) $item)->values(),
            'notes' => $record->notes,
            'recommendation' => $record->recommendation,
            'priority' => $record->priority,
            'last_treated_at' => optional($record->last_treated_at)?->format('Y-m-d H:i:s'),
            'next_action_due_at' => optional($record->next_action_due_at)?->format('Y-m-d H:i:s'),
            'created_at' => optional($record->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($record->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
