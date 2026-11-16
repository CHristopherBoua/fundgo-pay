@extends('layouts.app')
@section('title', 'Mon espace - '.config('app.name'))

@php($fmt = fn ($c, $cur = 'EUR') => \App\Support\Money::format($c, $cur))

@section('content')
<h1>Bonjour {{ $user->name }}</h1>
<p class="muted">Merci pour votre engagement. Tout se gère ici, sans appeler personne.</p>

<div class="grid" style="margin:24px 0">
    <div class="card"><div class="muted">Donné en {{ now()->year }}</div><div class="stat">{{ $fmt($totalThisYear) }}</div></div>
    <div class="card"><div class="muted">Depuis le début</div><div class="stat">{{ $fmt($totalLifetime) }}</div></div>
    <div class="card"><div class="muted">Dons réguliers actifs</div><div class="stat">{{ $recurring->where('status', \App\Enums\RecurringStatus::Active)->count() }}</div></div>
</div>

<section class="card stack" style="margin-bottom:24px">
    <h2>Mes dons réguliers</h2>
    @forelse ($recurring as $rd)
        <article style="border:1px solid var(--line);border-radius:10px;padding:16px" class="stack">
            <div class="row" style="justify-content:space-between">
                <div>
                    <strong>{{ $fmt($rd->amount_cents, $rd->currency) }} {{ $rd->frequency->perLabel() }}</strong>
                    <span class="muted">- {{ $rd->campaign?->title ?? 'Don libre' }}</span>
                </div>
                <span class="badge {{ $rd->status->value }}">{{ $rd->status->label() }}</span>
            </div>
            <div class="fineprint">
                @if ($rd->next_charge_on && $rd->status === \App\Enums\RecurringStatus::Active)
                    Prochain prélèvement le {{ $rd->next_charge_on->format('d/m/Y') }}
                @elseif ($rd->status === \App\Enums\RecurringStatus::Paused && $rd->paused_until)
                    En pause jusqu'au {{ $rd->paused_until->format('d/m/Y') }}
                @endif
                @if ($rd->paymentMethod) · {{ $rd->paymentMethod->displayName() }} @endif
                @if ($rd->status === \App\Enums\RecurringStatus::PastDue) · Le dernier prélèvement a échoué : reprenez ou changez de moyen de paiement. @endif
            </div>

            @if ($rd->isEditable())
                <div class="row">
                    <form method="post" action="{{ route('recurring.amount', $rd->id) }}" class="row">
                        @csrf @method('patch')
                        <label class="sr-only" for="amount-{{ $rd->id }}" hidden>Nouveau montant</label>
                        <input id="amount-{{ $rd->id }}" type="text" inputmode="decimal" name="amount" value="{{ number_format($rd->amount_cents / 100, 2, ',', '') }}" style="width:110px" aria-label="Nouveau montant">
                        <button class="btn small secondary">Modifier le montant</button>
                    </form>

                    @if ($methods->count() > 1)
                        <form method="post" action="{{ route('recurring.method', $rd->id) }}" class="row">
                            @csrf @method('patch')
                            <select name="payment_method_id" aria-label="Moyen de paiement" style="width:auto">
                                @foreach ($methods as $m)
                                    <option value="{{ $m->id }}" @selected($m->id === $rd->payment_method_id)>{{ $m->displayName() }}</option>
                                @endforeach
                            </select>
                            <button class="btn small secondary">Changer</button>
                        </form>
                    @endif
                </div>
                <div class="row">
                    @if ($rd->status === \App\Enums\RecurringStatus::Active)
                        <form method="post" action="{{ route('recurring.pause', $rd->id) }}" class="row">
                            @csrf
                            <input type="date" name="until" aria-label="Pause jusqu'au (facultatif)" style="width:auto" min="{{ now()->addDay()->toDateString() }}">
                            <button class="btn small secondary">Mettre en pause</button>
                        </form>
                    @else
                        <form method="post" action="{{ route('recurring.resume', $rd->id) }}">@csrf<button class="btn small">Reprendre</button></form>
                    @endif
                    <form method="post" action="{{ route('recurring.cancel', $rd->id) }}" onsubmit="return confirm('Arrêter ce don régulier ?')">
                        @csrf<button class="btn small danger">Arrêter</button>
                    </form>
                </div>
            @endif
        </article>
    @empty
        <p class="muted">Aucun don régulier. Un don mensuel, même modeste, donne de la visibilité aux projets. <a href="{{ route('home') }}">Choisir une campagne</a></p>
    @endforelse
</section>

<section class="card stack" style="margin-bottom:24px">
    <h2>Mes moyens de paiement</h2>
    @forelse ($methods as $m)
        <div class="row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px">
            <div>
                <strong>{{ $m->displayName() }}</strong>
                <div class="fineprint">{{ $m->type->label() }}@if ($m->rum) · RUM {{ $m->rum }}@endif · ajouté le {{ $m->created_at->format('d/m/Y') }}</div>
            </div>
            <form method="post" action="{{ route('payment-methods.destroy', $m->id) }}" onsubmit="return confirm('Supprimer ce moyen de paiement ?')">
                @csrf @method('delete')<button class="btn small danger">Supprimer</button>
            </form>
        </div>
    @empty
        <p class="muted">Aucun moyen de paiement enregistré.</p>
    @endforelse

    <details>
        <summary>Ajouter un mandat de prélèvement SEPA</summary>
        <form method="post" action="{{ route('payment-methods.sepa') }}" class="stack" style="margin-top:12px;max-width:480px">
            @csrf
            <div><label for="pm-holder">Titulaire</label><input id="pm-holder" type="text" name="holder_name" value="{{ $user->name }}" required></div>
            <div><label for="pm-iban">IBAN</label><input id="pm-iban" type="text" name="iban" required autocomplete="off" spellcheck="false"></div>
            <label style="font-weight:400;display:flex;gap:10px;align-items:flex-start">
                <input type="checkbox" name="mandate_accepted" value="1" required style="margin-top:4px">
                <span class="fineprint">J'autorise {{ config('payments.sepa.creditor_name') }} (ICS {{ config('payments.sepa.creditor_id') }}) à prélever mon compte selon les dons que je programme. Remboursement possible auprès de ma banque sous 8 semaines.</span>
            </label>
            <button class="btn small">Signer le mandat</button>
        </form>
    </details>
</section>

<section class="card stack" style="margin-bottom:24px" id="recus">
    <h2>Mes reçus fiscaux</h2>
    @if (config('tax_receipts.enabled'))
        @if ($deductibleThisYear > 0)
            <p class="muted" style="margin:0">
                Dons {{ now()->year }} ouvrant droit à réduction : <strong>{{ $fmt($deductibleThisYear) }}</strong>,
                soit environ <strong>{{ $fmt((int) floor($deductibleThisYear * config('tax_receipts.reduction_rate_percent') / 100)) }}</strong> de réduction d'impôt.
                Votre reçu {{ now()->year }} vous sera envoyé en janvier.
            </p>
        @endif
        @if (! $user->hasCompleteAddress() && ($deductibleThisYear > 0 || $claimableYears->isNotEmpty()))
            <div class="alert err" style="margin:0">Votre adresse postale est incomplète : elle est obligatoire sur le reçu fiscal. <a href="#coordonnees">Compléter mes coordonnées</a></div>
        @endif

        @if ($receipts->isNotEmpty())
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Année</th><th>N°</th><th>Montant</th><th>Statut</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($receipts as $receipt)
                        <tr>
                            <td>{{ $receipt->fiscal_year }}</td>
                            <td>{{ $receipt->number }}@if ($receipt->replaces)<div class="fineprint">remplace {{ $receipt->replaces->number }}</div>@endif</td>
                            <td>{{ $fmt($receipt->amount_cents) }}</td>
                            <td><span class="badge {{ $receipt->isIssued() ? 'paid' : 'failed' }}">{{ $receipt->isIssued() ? 'Valide' : 'Annulé' }}</span></td>
                            <td><a class="btn small secondary" href="{{ route('tax-receipts.download', $receipt->id) }}">Télécharger (PDF)</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($claimableYears->isEmpty())
            <p class="muted" style="margin:0">Aucun reçu pour l'instant.</p>
        @endif

        @foreach ($claimableYears as $year)
            <form method="post" action="{{ route('tax-receipts.store') }}" class="row">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <button class="btn small">Obtenir mon reçu {{ $year }}</button>
            </form>
        @endforeach
    @else
        <p class="muted" style="margin:0">Les reçus fiscaux ne sont pas proposés.</p>
    @endif
</section>

<section class="card stack" style="margin-bottom:24px">
    <h2>Historique</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Date</th><th>Campagne</th><th>Type</th><th>Montant</th><th>Statut</th></tr></thead>
            <tbody>
            @forelse ($payments as $p)
                <tr>
                    <td>{{ ($p->paid_at ?? $p->created_at)->format('d/m/Y') }}</td>
                    <td>{{ $p->campaign?->title ?? 'Don libre' }}</td>
                    <td>{{ $p->recurring_donation_id ? 'Régulier' : 'Ponctuel' }} · {{ $p->method_type->label() }}</td>
                    <td>{{ $fmt($p->amount_cents, $p->currency) }}</td>
                    <td>
                        <span class="badge {{ $p->status->value }}">{{ $p->status->label() }}</span>
                        @if ($p->next_retry_at)<div class="fineprint">Nouvelle tentative le {{ $p->next_retry_at->format('d/m') }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Aucun paiement pour l'instant.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="coordonnees">
    <h2>Mes coordonnées</h2>
    <p class="fineprint">Nécessaires pour votre reçu fiscal.</p>
    <form method="post" action="{{ route('profile.update') }}" class="grid">
        @csrf @method('patch')
        <div><label for="p-name">Nom</label><input id="p-name" type="text" name="name" value="{{ old('name', $user->name) }}" required></div>
        <div><label for="p-phone">Téléphone</label><input id="p-phone" type="text" name="phone" value="{{ old('phone', $user->phone) }}"></div>
        <div><label for="p-a1">Adresse</label><input id="p-a1" type="text" name="address_line1" value="{{ old('address_line1', $user->address_line1) }}"></div>
        <div><label for="p-a2">Complément</label><input id="p-a2" type="text" name="address_line2" value="{{ old('address_line2', $user->address_line2) }}"></div>
        <div><label for="p-pc">Code postal</label><input id="p-pc" type="text" name="postal_code" value="{{ old('postal_code', $user->postal_code) }}"></div>
        <div><label for="p-city">Ville</label><input id="p-city" type="text" name="city" value="{{ old('city', $user->city) }}"></div>
        <div><label for="p-country">Pays (ISO)</label><input id="p-country" type="text" name="country" value="{{ old('country', $user->country) }}" maxlength="2" required></div>
        <div style="align-self:end"><button class="btn">Enregistrer</button></div>
    </form>
</section>
@endsection
