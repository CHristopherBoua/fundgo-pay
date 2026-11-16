@extends('layouts.app')
@section('title', 'Créer un compte - '.config('app.name'))

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Créer mon espace donateur</h1>
    <p class="muted">Suivez vos dons, gérez vos dons réguliers et retrouvez vos reçus.</p>
    <form method="post" action="{{ route('register') }}" class="stack" style="margin-top:16px">
        @csrf
        <div>
            <label for="name">Nom complet</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
        </div>
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </div>
        <div>
            <label for="password">Mot de passe (10 caractères min.)</label>
            <input id="password" type="password" name="password" required autocomplete="new-password">
        </div>
        <div>
            <label for="password_confirmation">Confirmation</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button class="btn" type="submit" style="width:100%">Créer mon compte</button>
        <p class="muted">Déjà inscrit ? <a href="{{ route('login') }}">Se connecter</a></p>
    </form>
</div>
@endsection
