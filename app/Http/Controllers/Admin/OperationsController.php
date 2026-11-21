<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GatewayEvent;
use App\Models\TaxReceipt;
use App\Models\User;
use App\Services\TaxReceipts\TaxReceiptService;
use App\Services\Payments\GatewayEventIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/** Donateurs, reçus fiscaux, webhooks et journal d'audit. */
class OperationsController extends Controller
{
    public function donors(Request $request): View
    {
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '');

        return view('admin.donors', [
            'q' => $q,
            'donors' => User::query()
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('email', 'like', '%'.addcslashes($q, '%_').'%')
                    ->orWhere('name', 'like', '%'.addcslashes($q, '%_').'%')))
                ->withCount(['payments as paid_count' => fn ($p) => $p->where('status', 'paid')])
                ->withCount(['recurringDonations as active_recurring' => fn ($r) => $r->where('status', 'active')])
                ->latest()->paginate(30)->withQueryString(),
        ]);
    }

    public function receipts(): View
    {
        return view('admin.receipts', [
            'receipts' => TaxReceipt::query()->with('user')->latest('issued_at')->paginate(30),
            'years' => range(now()->year - 1, now()->year - 4),
        ]);
    }

    public function issueReceipts(Request $request): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year - 1)]])['year'];
        Artisan::call('tax-receipts:issue', ['year' => $year]);

        return back()->with('status', 'Émission des reçus '.$year.' lancée : '.TaxReceipt::where('fiscal_year', $year)->issued()->count().' reçu(s) valides pour cette année.');
    }

    public function cancelReceipt(Request $request, TaxReceipt $receipt, TaxReceiptService $service): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];
        $replacement = $service->replace($receipt, 'admin:'.$reason);

        return back()->with('status', $replacement ? 'Reçu remplacé par le n° '.$replacement->number.'.' : 'Reçu annulé.');
    }

    public function webhooks(): View
    {
        return view('admin.webhooks', [
            'events' => GatewayEvent::query()->latest()->paginate(30),
        ]);
    }

    public function replay(GatewayEvent $event, GatewayEventIngestor $ingestor): RedirectResponse
    {
        return $ingestor->replay($event)
            ? back()->with('status', 'Événement rejoué.')
            : back()->withErrors(['replay' => 'Rejeu impossible : '.$event->fresh()->error]);
    }

    public function audit(Request $request): View
    {
        $filters = $request->validate(['action' => ['nullable', 'string', 'max:64'], 'entity' => ['nullable', 'string', 'max:64']]);

        return view('admin.audit', [
            'filters' => $filters,
            'logs' => AuditLog::query()
                ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', addcslashes($v, '%_').'%'))
                ->when($filters['entity'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
        ]);
    }
}
