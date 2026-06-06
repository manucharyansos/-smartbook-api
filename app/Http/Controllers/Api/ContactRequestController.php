<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\ContactRequestReceived;

class ContactRequestController extends Controller
{
    public function store(Request $request)
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'message' => 'required|string|max:5000',
        ]);

        // Check if at least one of email or phone is provided
        $validator->after(function ($validator) use ($request) {
            if (!$request->email && !$request->phone) {
                $validator->errors()->add(
                    'contact',
                    'Email կամ հեռախոս դաշտերից գոնե մեկը պարտադիր է։'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Վավերացման սխալ',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get business_id if user is authenticated (փոխել salon_id-ից business_id)
        $businessId = auth()->check() && auth()->user()->business_id
            ? auth()->user()->business_id
            : null;

        // Create contact request
        $contactRequest = ContactRequest::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
            'business_id' => $businessId, // Փոխել salon_id-ից business_id
            'status' => 'new'
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
            'data' => $contactRequest
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
