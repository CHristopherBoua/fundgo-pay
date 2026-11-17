@extends('layouts.app')
@section('title', 'Payer avec mon compte Ecobank')

@section('content')
<div class="card stack" style="max-width:560px;margin:0 auto">
    <h1>{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</h1>
    <p class="muted" style="margin:0">{{ $payment->description() }} · Compte Ecobank</p>

    @unless ($linked)
        <h2>1. Lier votre compte Ecobank <span class="muted">(une seule fois)</span></h2>

        @if ($linkStep === 'start')
            <form method="post" action="{{ route('ecobank.link.send', $payment->id) }}" class="stack">
                @csrf
                <p class="fineprint">Nous allons vous envoyer un code de vérification (e-mail {{ auth()->user()->email }}, ou SMS).</p>
                <div><label for="phone">Téléphone (facultatif)</label><input id="phone" type="text" name="phone" inputmode="tel" placeholder="+221 …" value="{{ old('phone', auth()->user()->phone) }}"></div>
                <div><label for="account_number">N° de compte Ecobank (facultatif)</label><input id="account_number" type="text" name="account_number" inputmode="numeric" autocomplete="off"></div>
                <button class="btn">Recevoir mon code</button>
            </form>
        @elseif ($linkStep === 'otp_sent')
            <form method="post" action="{{ route('ecobank.link.verify', $payment->id) }}" class="stack">
                @csrf
                <div><label for="code">Code reçu</label><input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
                <button class="btn">Vérifier</button>
            </form>
        @elseif ($linkStep === 'profile_created')
            <ol class="fineprint">
                <li>Installez l'application <strong>Ecobank Authenticator</strong> (App Store / Google Play).</li>
                <li>Connectez-vous et suivez l'activation du service de paiement.</li>
                <li>Saisissez ci-dessous le code affiché par l'application.</li>
            </ol>
            <form method="post" action="{{ route('ecobank.link.complete', $payment->id) }}" class="stack">
                @csrf
                <div><label for="code">Code Ecobank Authenticator</label><input id="code" type="text" name="code" autocomplete="one-time-code" required autofocus></div>
                <button class="btn">Activer</button>
            </form>
        @endif
    @else
        <h2>Valider votre don</h2>
        @unless ($initiated)
            <p class="fineprint">Nous envoyons la demande de débit à Ecobank, puis vous la confirmez avec le code de votre application Ecobank Authenticator.</p>
            <form method="post" action="{{ route('ecobank.initiate', $payment->id) }}">
                @csrf<button class="btn" style="width:100%">Envoyer la demande de débit</button>
            </form>
        @else
            <form method="post" action="{{ route('ecobank.confirm', $payment->id) }}" class="stack">
                @csrf
                <div><label for="code">Code Ecobank Authenticator</label><input id="code" type="text" name="code" autocomplete="one-time-code" required autofocus></div>
                <button class="btn" style="width:100%">Confirmer le paiement</button>
            </form>
        @endunless
    @endunless
</div>
@endsection
