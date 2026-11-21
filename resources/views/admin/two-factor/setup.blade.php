@extends('layouts.app')
@section('title', 'Double authentification - Administration')

@section('content')
<div class="card stack" style="max-width:560px;margin:0 auto">
    <h1>Activer la double authentification</h1>
    <p class="muted" style="margin:0">Obligatoire pour accéder à l'administration (clés de paiement, remboursements).</p>
    <ol>
        <li>Ouvrez une application d'authentification (Google Authenticator, Microsoft Authenticator, 1Password…).</li>
        <li>Ajoutez un compte avec cette clé (saisie manuelle, type « basé sur l'heure ») :
            <p><code class="secret" style="font-size:1.1rem">{{ $secret }}</code></p>
            <details><summary>Lien otpauth (gestionnaires de mots de passe)</summary><p class="fineprint" style="word-break:break-all">{{ $uri }}</p></details>
        </li>
        <li>Saisissez le code à 6 chiffres affiché :</li>
    </ol>
    <form method="post" action="{{ route('admin.2fa.confirm') }}" class="row">
        @csrf
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus style="max-width:160px" aria-label="Code à 6 chiffres">
        <button class="btn">Activer</button>
    </form>
</div>
@endsection
