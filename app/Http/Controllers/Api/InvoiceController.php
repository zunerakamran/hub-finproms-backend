<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()
            ->invoices()
            ->with([
                'subscription.plan:id,name,credits,price',
                'postPurchase.post:id,title,type,category,credits_cost',
            ])
            ->latest('issued_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->user_id !== $request->user()->id && ! $request->user()->isClientAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoice->load([
            'subscription.plan',
            'postPurchase.post',
            'user:id,name,email',
        ]);

        return response()->json([
            'invoice' => $invoice,
        ]);
    }
}
