@extends('layouts.app')
@section('title', 'Vérification - Administration')

@section('content')
<div class="card stack" style="max-width:420px;margin:0 auto">
    <h1>Vérification</h1>
    <p class="muted" style="margin:0">Saisissez le code de votre application d'authentification.</p>
    <form method="post" action="{{ route('admin.2fa.verify') }}" class="stack">
        @csrf
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus aria-label="Code à 6 chiffres">
        <button class="btn" style="width:100%">Continuer</button>
    </form>
    <p class="fineprint">Téléphone perdu ? Un administrateur ayant accès au serveur peut lancer <code>php artisan admin:reset-2fa votre@email</code>.</p>
</div>
@endsection
