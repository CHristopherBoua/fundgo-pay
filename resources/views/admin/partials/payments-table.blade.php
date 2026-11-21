<div class="table-scroll"><table>
    <thead><tr><th>Date</th><th>Donateur</th><th>Campagne</th><th>Montant</th><th>Passerelle</th><th>Statut</th>@if ($actions)<th></th>@endif</tr></thead>
    <tbody>
    @forelse ($payments as $p)
        <tr>
            <td>{{ ($p->paid_at ?? $p->created_at)->format('d/m/Y H:i') }}</td>
            <td>{{ $p->user?->email }}</td>
            <td>{{ $p->campaign?->title ?? 'Fonds général' }}</td>
            <td>{{ \App\Support\Money::format($p->amount_cents, $p->currency) }}
                <div class="fineprint">{{ $p->recurring_donation_id ? 'régulier' : 'ponctuel' }} · {{ $p->method_type->label() }}</div></td>
            <td>{{ $p->gateway }}<div class="fineprint" style="word-break:break-all">{{ $p->gateway_reference }}</div></td>
            <td><span class="badge {{ $p->status->value }}">{{ $p->status->label() }}</span>
                @if ($p->failure_code)<div class="fineprint">{{ $p->failure_code }}</div>@endif</td>
            @if ($actions)
                <td>
                    @if ($p->status === \App\Enums\PaymentStatus::Paid)
                        <form method="post" action="{{ route('admin.payments.refund', $p->id) }}" onsubmit="return confirm('Rembourser {{ \App\Support\Money::format($p->amount_cents, $p->currency) }} via {{ $p->gateway }} ?')">
                            @csrf<button class="btn small danger">Rembourser</button>
                        </form>
                        @if (auth()->user()->isSuperAdmin())
                            <details class="fineprint"><summary>Marquer remboursé</summary>
                                <form method="post" action="{{ route('admin.payments.mark-refunded', $p->id) }}" class="stack" style="margin-top:6px">
                                    @csrf
                                    <input type="text" name="reason" placeholder="Motif / référence du remboursement" required minlength="5">
                                    <button class="btn small secondary">Confirmer</button>
                                </form>
                            </details>
                        @endif
                    @endif
                </td>
            @endif
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Aucun paiement.</td></tr>
    @endforelse
    </tbody>
</table></div>
