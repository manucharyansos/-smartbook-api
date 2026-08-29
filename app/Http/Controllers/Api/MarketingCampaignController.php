<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Services\MarketingCampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MarketingCampaignController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $campaigns = MarketingCampaign::query()
            ->where('business_id', $actor->business_id)
            ->withCount('deliveries')
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request, MarketingCampaignService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $data = $this->validated($request);
        $status = !empty($data['scheduled_for']) ? 'scheduled' : 'draft';
        $campaign = MarketingCampaign::query()->create([
            'business_id' => $actor->business_id,
            'created_by' => $actor->id,
            'name' => $data['name'],
            'channel' => 'email',
            'segment' => $data['segment'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => $status,
            'scheduled_for' => $data['scheduled_for'] ?? null,
        ]);
        $campaign->update(['recipient_count' => $service->recipientCount($campaign)]);

        return response()->json(['data' => $campaign->fresh(), 'meta' => ['recipient_count' => $campaign->recipient_count]], 201);
    }

    public function update(Request $request, MarketingCampaign $campaign, MarketingCampaignService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $campaign->business_id === (int) $actor->business_id, 404);
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'Sent campaigns cannot be edited.');
        $data = $this->validated($request, true);
        $campaign->fill($data);
        if (array_key_exists('scheduled_for', $data)) {
            $campaign->status = $data['scheduled_for'] ? 'scheduled' : 'draft';
        }
        $campaign->save();
        $campaign->update(['recipient_count' => $service->recipientCount($campaign)]);

        return response()->json(['data' => $campaign->fresh(), 'meta' => ['recipient_count' => $campaign->recipient_count]]);
    }

    public function preview(Request $request, MarketingCampaign $campaign, MarketingCampaignService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $campaign->business_id === (int) $actor->business_id, 404);

        return response()->json(['data' => [
            'recipient_count' => $service->recipientCount($campaign),
            'segment' => $campaign->segment,
            'channel' => 'email',
        ]]);
    }

    public function send(Request $request, MarketingCampaign $campaign, MarketingCampaignService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $campaign->business_id === (int) $actor->business_id, 404);
        abort_if(in_array($campaign->status, ['sending', 'sent', 'cancelled'], true), 422, 'This campaign cannot be sent.');

        return response()->json(['data' => $service->dispatch($campaign)]);
    }

    public function cancel(Request $request, MarketingCampaign $campaign)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $campaign->business_id === (int) $actor->business_id, 404);
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'This campaign can no longer be cancelled.');
        $campaign->update(['status' => 'cancelled']);

        return response()->json(['data' => $campaign->fresh()]);
    }

    public function deliveries(Request $request, MarketingCampaign $campaign)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $campaign->business_id === (int) $actor->business_id, 404);
        return response()->json(['data' => MarketingDelivery::query()
            ->where('campaign_id', $campaign->id)
            ->latest('id')
            ->limit(1000)
            ->get()]);
    }

    public function unsubscribe(Request $request, MarketingDelivery $delivery, MarketingCampaignService $service)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $service->unsubscribe($delivery, $data['token']);
        return response()->json(['ok' => true, 'message' => 'You have been unsubscribed.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'segment' => [$required, 'in:all,new,returning,inactive,vip'],
            'subject' => [$required, 'string', 'min:2', 'max:180'],
            'body' => [$required, 'string', 'min:2', 'max:10000'],
            'scheduled_for' => ['nullable', 'date'],
        ]);
        if (!empty($data['scheduled_for'])) {
            $data['scheduled_for'] = Carbon::parse($data['scheduled_for']);
        }
        return $data;
    }
}
