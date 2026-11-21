<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\GatewayEvent;
use App\Models\Payment;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(LedgerService $ledger): View
    {
        $paid = Payment::query()->where('status', PaymentStatus::Paid);

        return view('admin.dashboard', [
            'byCurrency' => (clone $paid)->selectRaw('currency, count(*) as n, sum(amount_cents) as total')->groupBy('currency')->get(),
            'thisMonth' => (clone $paid)->where('paid_at', '>=', now()->startOfMonth())
                ->selectRaw('currency, sum(amount_cents) as total')->groupBy('currency')->pluck('total', 'currency'),
            'campaigns' => Campaign::query()->withSum(['payments as raised' => fn ($q) => $q->where('status', PaymentStatus::Paid)], 'amount_cents')
                ->orderByDesc('created_at')->limit(10)->get(),
            'donors' => User::query()->where('role', User::ROLE_DONOR)->count(),
            'activeRecurring' => RecurringDonation::query()->where('status', RecurringStatus::Active)->count(),
            'pastDue' => RecurringDonation::query()->where('status', RecurringStatus::PastDue)->count(),
            'failedWebhooks' => GatewayEvent::query()->whereNull('processed_at')->whereNotNull('error')->count(),
            'recent' => Payment::query()->with(['user', 'campaign'])->latest()->limit(10)->get(),
            'ledger' => $ledger->verify(),
        ]);
    }
}
