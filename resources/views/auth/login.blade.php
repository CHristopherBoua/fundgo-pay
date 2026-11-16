@extends('layouts.app')
@section('title', 'Connexion - '.config('app.name'))

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Connexion</h1>
    <form method="post" action="{{ route('login') }}" class="stack" style="margin-top:16px">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>
        <div>
            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <label style="font-weight:400"><input type="checkbox" name="remember" value="1"> Rester connecté</label>
        <button class="btn" type="submit" style="width:100%">Se connecter</button>
        <p class="muted">Pas encore de compte ? <a href="{{ route('register') }}">Créer un compte</a></p>
    </form>
</div>
@endsection
