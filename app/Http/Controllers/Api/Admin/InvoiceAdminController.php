<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingLifecycleService;
use Illuminate\Http\Request;

class InvoiceAdminController extends Controller
{
    public function __construct(private BillingLifecycleService $lifecycle)
    {
    }

    private function requireSuperAdmin(Request $request): void
    {
        $u = $request->user();
        if (!$u || !method_exists($u,'isSuperAdmin') || !$u->isSuperAdmin()) abort(403);
    }

    // GET /api/admin/invoices?status=pending
    public function index(Request $request)
    {
        $this->requireSuperAdmin($request);

        $status = $request->query('status', 'pending');

        $items = Invoice::query()
            ->where('status', $status)
            ->with(['business:id,name,slug', 'plan:id,name,code,price,currency,seats']) // Փոխել salon-ից business
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    // PATCH /api/admin/invoices/{invoice}/approve
    public function approve(Request $request, Invoice $invoice)
    {
        $this->requireSuperAdmin($request);

        if ($invoice->status !== 'pending') {
            return response()->json(['ok' => true]);
        }

        $this->lifecycle->approveInvoice($invoice, [
            'provider' => 'admin_manual',
            'note' => 'Approved manually by super admin.',
        ]);

        return response()->json(['ok' => true]);
    }

    // PATCH /api/admin/invoices/{invoice}/reject
    public function reject(Request $request, Invoice $invoice)
    {
        $this->requireSuperAdmin($request);

        if ($invoice->status !== 'pending') {
            return response()->json(['ok' => true]);
        }

        $invoice->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
