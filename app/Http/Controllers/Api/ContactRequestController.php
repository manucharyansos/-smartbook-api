<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\ContactRequestReceived;

class ContactRequestController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'min:5', 'max:40'],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $businessId = $request->user()?->business_id;

        $contactRequest = ContactRequest::create([
            'name' => trim($data['name']),
            'email' => isset($data['email']) ? mb_strtolower(trim($data['email'])) : null,
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'message' => trim($data['message']),
            'business_id' => $businessId,
            'status' => 'new',
        ]);

        // Queue admin email so the public contact form stays fast.
        try {
            $adminAddress = config('mail.admin_address');
            if ($adminAddress) {
                Mail::to($adminAddress)->queue(new ContactRequestReceived($contactRequest));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to queue contact email', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Հաղորդագրությունը հաջողությամբ ուղարկվեց',
        ], 201);
    }

    // Admin endpoints for managing contact requests
    public function index(Request $request)
    {
        $this->authorize('viewAny', ContactRequest::class);

        $query = ContactRequest::with('business') // Փոխել salon-ից business
        ->orderBy('created_at', 'desc');

        // Filter by business_id if provided
        if ($request->has('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->paginate(20);

        return response()->json($requests);
    }

    public function markAsRead(ContactRequest $contactRequest)
    {
        $this->authorize('update', $contactRequest);

        $contactRequest->update(['status' => 'read']);

        return response()->json([
            'message' => 'Հաղորդագրությունը նշվեց որպես կարդացված'
        ]);
    }

    public function show(ContactRequest $contactRequest)
    {
        $this->authorize('view', $contactRequest);

        return response()->json([
            'data' => $contactRequest->load('business') // Փոխել salon-ից business
        ]);
    }

    public function destroy(ContactRequest $contactRequest)
    {
        $this->authorize('delete', $contactRequest);

        $contactRequest->delete();

        return response()->json([
            'message' => 'Հաղորդագրությունը ջնջվեց'
        ]);
    }
}
