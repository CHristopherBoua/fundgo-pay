<?php

namespace App\Http\Controllers\Donor;

use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Http\Controllers\Controller;
use App\Models\TaxReceipt;
use App\Services\TaxReceipts\TaxReceiptService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TaxReceiptService $taxReceipts): View
    {
        $user = $request->user();
        $paid = $user->payments()->where('status', PaymentStatus::Paid);

        $receipts = $user->taxReceipts()->with('replaces')->orderByDesc('fiscal_year')->orderByDesc('issued_at')->get();
        $issuedYears = $receipts->where('status', TaxReceipt::STATUS_ISSUED)->pluck('fiscal_year')->all();

        // Années closes avec des dons éligibles mais sans reçu (ex. adresse complétée après l'envoi annuel).
        $claimableYears = collect(range(now()->year - 3, now()->year - 1))
            ->reject(fn (int $y) => in_array($y, $issuedYears, true))
            ->filter(fn (int $y) => config('tax_receipts.enabled') && $taxReceipts->eligiblePayments($user, $y)->isNotEmpty())
            ->sortDesc()->values();

        // Totaux par devise : on n'additionne jamais des euros et des francs CFA.
        $totals = fn ($query) => $query->selectRaw('currency, sum(amount_cents) as total')->groupBy('currency')
            ->pluck('total', 'currency')->map(fn ($v) => (int) $v)->all();

        return view('donor.dashboard', [
            'user' => $user,
            'totalThisYear' => $totals((clone $paid)->where('paid_at', '>=', now()->startOfYear())),
            'totalLifetime' => $totals(clone $paid),
            'installments' => $user->payments()->with('campaign')
                ->where('status', PaymentStatus::Pending)
                ->whereNotNull('recurring_donation_id')
                ->get()->filter(fn ($p) => ! empty($p->metadata['pay_by_link']))->values(),
            'deductibleThisYear' => (int) $taxReceipts->eligiblePayments($user, now()->year)->sum('amount_cents'),
            'recurring' => $user->recurringDonations()
                ->with(['campaign', 'paymentMethod'])
                ->where('status', '!=', RecurringStatus::Cancelled)
                ->latest()->get(),
            'payments' => $user->payments()->with('campaign')->latest()->limit(50)->get(),
            'methods' => $user->paymentMethods()->active()->latest()->get(),
            'receipts' => $receipts,
            'claimableYears' => $claimableYears,
        ]);
    }
}
