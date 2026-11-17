@php
    $currency = strtoupper($campaign->currency);
    $offers = collect($methods)->map(fn ($t) => match ($t) {
        \App\Enums\PaymentMethodType::SepaDebit => 'sepa',
        \App\Enums\PaymentMethodType::Card => 'card',
        \App\Enums\PaymentMethodType::EcobankAccount => 'ecobank',
    })->all();
    $defaultMethod = old('method', $savedMethods->isNotEmpty() ? 'saved' : ($offers[0] ?? 'card'));
    $frequency = old('frequency', $campaign->allows_recurring ? 'monthly' : 'once');
    $suggested = config("payments.currencies.$currency.suggested", []);
    $symbol = (new \NumberFormatter('fr_FR@currency='.$currency, \NumberFormatter::CURRENCY))->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
    $decimals = \App\Support\Money::decimals($currency);
@endphp
<form method="post" action="{{ route('donations.store') }}" class="stack" id="donate-form">
    @csrf
    <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">
    <h2>Faire un don</h2>

    <fieldset style="border:0;padding:0;margin:0">
        <legend><label>Fréquence</label></legend>
        <div class="chips">
            <label><input type="radio" name="frequency" value="once" @checked($frequency === 'once')><span>Une fois</span></label>
            @if ($campaign->allows_recurring)
                @foreach (\App\Enums\Frequency::cases() as $f)
                    <label><input type="radio" name="frequency" value="{{ $f->value }}" @checked($frequency === $f->value)><span>{{ $f->label() }}</span></label>
                @endforeach
            @endif
        </div>
    </fieldset>

    <fieldset style="border:0;padding:0;margin:0">
        <legend><label for="amount">Montant ({{ $symbol }})</label></legend>
        <div class="chips" style="margin-bottom:8px">
            @foreach ($suggested as $a)
                <label><input type="radio" name="amount_choice" value="{{ $a }}" data-amount @checked(old('amount') == $a)><span>{{ number_format($a, 0, ',', ' ') }} {{ $symbol }}</span></label>
            @endforeach
        </div>
        <input id="amount" type="text" inputmode="{{ $decimals > 0 ? 'decimal' : 'numeric' }}" name="amount" value="{{ old('amount', $suggested[1] ?? $suggested[0] ?? '') }}" required>
    </fieldset>

    <div data-recurring-only>
        <label for="day_of_month">Jour du prélèvement</label>
        <select id="day_of_month" name="day_of_month">
            @foreach (range(1, 28) as $d)
                <option value="{{ $d }}" @selected((int) old('day_of_month', 5) === $d)>le {{ $d }}</option>
            @endforeach
        </select>
    </div>

    <fieldset style="border:0;padding:0;margin:0">
        <legend><label>Moyen de paiement</label></legend>
        <div class="chips">
            @if ($savedMethods->isNotEmpty())
                <label><input type="radio" name="method" value="saved" @checked($defaultMethod === 'saved')><span>Enregistré</span></label>
            @endif
            @if (in_array('sepa', $offers, true))
                <label><input type="radio" name="method" value="sepa" @checked($defaultMethod === 'sepa')><span>Prélèvement SEPA</span></label>
            @endif
            @if (in_array('card', $offers, true))
                <label><input type="radio" name="method" value="card" @checked($defaultMethod === 'card')><span>Carte bancaire</span></label>
            @endif
            @if (in_array('ecobank', $offers, true))
                <label><input type="radio" name="method" value="ecobank" @checked($defaultMethod === 'ecobank')><span>Compte Ecobank</span></label>
            @endif
        </div>
    </fieldset>

    @if ($savedMethods->isNotEmpty())
        <div data-method="saved">
            <label for="payment_method_id">Moyen enregistré</label>
            <select id="payment_method_id" name="payment_method_id">
                @foreach ($savedMethods as $m)
                    <option value="{{ $m->id }}" @selected(old('payment_method_id') === $m->id)>{{ $m->displayName() }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div data-method="ecobank">
        <p class="fineprint">
            Vous validerez le débit de votre compte Ecobank avec le code de l'application <strong>Ecobank Authenticator</strong>.
            Pour un don régulier, chaque échéance vous sera envoyée par e-mail et sera validée de la même façon.
        </p>
    </div>

    @if (in_array('sepa', $offers, true))
    <div data-method="sepa" class="stack">
        <div>
            <label for="holder_name">Titulaire du compte</label>
            <input id="holder_name" type="text" name="holder_name" value="{{ old('holder_name', auth()->user()->name) }}" autocomplete="name">
        </div>
        <div>
            <label for="iban">IBAN</label>
            <input id="iban" type="text" name="iban" placeholder="FR76 …" autocomplete="off" spellcheck="false">
        </div>
        <details>
            <summary>BIC (facultatif)</summary>
            <input type="text" name="bic" value="{{ old('bic') }}" style="margin-top:8px">
        </details>
        <label style="font-weight:400;display:flex;gap:10px;align-items:flex-start">
            <input type="checkbox" name="mandate_accepted" value="1" style="margin-top:4px">
            <span class="fineprint">
                En signant ce mandat, vous autorisez {{ config('payments.sepa.creditor_name') }}
                (ICS {{ config('payments.sepa.creditor_id') }}) à envoyer des instructions à votre banque pour débiter votre compte,
                et votre banque à débiter votre compte conformément à ces instructions. Vous bénéficiez du droit d'être remboursé
                par votre banque selon les conditions de votre convention ; la demande doit être présentée dans les 8 semaines
                suivant la date du débit. Vous serez informé(e) du montant et de la date de chaque prélèvement au moins
                {{ config('payments.sepa.prenotification_days') }} jours à l'avance.
            </span>
        </label>
    </div>
    @endif

    <div data-method="card">
        <p class="fineprint">Vous serez redirigé(e) vers la page sécurisée de notre prestataire (3-D Secure).</p>
        <label style="font-weight:400;display:flex;gap:10px;align-items:flex-start" data-recurring-only>
            <input type="checkbox" name="recurring_consent" value="1" style="margin-top:4px">
            <span class="fineprint">J'autorise l'enregistrement de ma carte et les débits récurrents du montant choisi à la fréquence indiquée, jusqu'à ce que j'arrête mon don depuis mon espace.</span>
        </label>
    </div>

    <div>
        <label for="message">Un mot (facultatif)</label>
        <textarea id="message" name="message" rows="2" maxlength="500">{{ old('message') }}</textarea>
    </div>
    <label style="font-weight:400"><input type="checkbox" name="anonymous" value="1" @checked(old('anonymous'))> Don anonyme</label>

    <button class="btn" type="submit" style="width:100%">Je donne</button>
</form>

<script>
(() => {
    const form = document.getElementById('donate-form');
    const sync = () => {
        const method = form.querySelector('input[name=method]:checked')?.value;
        const recurring = form.querySelector('input[name=frequency]:checked')?.value !== 'once';
        form.querySelectorAll('[data-method]').forEach(el => el.hidden = el.dataset.method !== method);
        form.querySelectorAll('[data-recurring-only]').forEach(el => el.hidden = !recurring);
    };
    form.addEventListener('change', e => {
        if (e.target.matches('[data-amount]')) form.querySelector('#amount').value = e.target.value;
        sync();
    });
    sync();
})();
</script>
