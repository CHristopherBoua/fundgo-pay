<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Data\ChargeResult;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'gateway' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $payments = Payment::query()->with(['user', 'campaign'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['gateway'] ?? null, fn ($q, $v) => $q->where('gateway', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper($v)))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('id', $v)->orWhere('gateway_reference', $v)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.addcslashes($v, '%_').'%'))))
            ->latest()->paginate(30)->withQueryString();

        return view('admin.payments.index', ['payments' => $payments, 'filters' => $filters, 'gateways' => array_keys(config('payments.gateways'))]);
    }

    /** Remboursement via l'API du PSP (Mollie, Stripe). */
    public function refund(Payment $payment, GatewayManager $gateways): RedirectResponse
    {
        abort_unless($payment->status === PaymentStatus::Paid, 409, 'Seul un paiement encaissé peut être remboursé.');

        try {
            $result = $gateways->driver($payment->gateway)->refund($payment);
        } catch (PaymentGatewayException $e) {
            return back()->withErrors(['refund' => 'Remboursement impossible via l\'API : '.$e->getMessage()
                .'. Effectuez-le depuis le tableau de bord du prestataire puis utilisez « Marquer remboursé ».']);
        }

        $this->processor->apply($payment, $result, actor: 'user:'.auth()->id());

        return back()->with('status', 'Paiement remboursé.');
    }

    /**
     * Super-admin : enregistre un remboursement effectué hors API (tableau de bord UBA, virement…).
     * Contre-passe le grand livre et annule / rectifie le reçu fiscal concerné.
     */
    public function markRefunded(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->status === PaymentStatus::Paid, 409);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        $this->processor->apply($payment, new ChargeResult(PaymentStatus::Refunded, $payment->gateway_reference), actor: 'user:'.auth()->id());
        $this->audit->log('payment.marked_refunded', $payment, after: ['reason' => $reason]);

        return back()->with('status', 'Remboursement enregistré.');
    }
}
