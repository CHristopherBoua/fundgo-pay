@extends('layouts.app')
@section('title', $campaign->title.' - '.config('app.name'))

@section('content')
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); align-items:start">
    <section class="stack">
        <h1>{{ $campaign->title }}</h1>
        <p class="muted">{{ $campaign->summary }}</p>
        <div>{!! nl2br(e($campaign->description)) !!}</div>
        @if ($campaign->goal_cents)
            @php($percent = $campaign->progressPercent())
            <div class="card">
                <div class="stat">{{ \App\Support\Money::format($campaign->raisedCents(), $campaign->currency) }}</div>
                <div class="muted">collectés sur {{ \App\Support\Money::format($campaign->goal_cents, $campaign->currency) }}</div>
                <div class="progress" style="margin-top:12px"><div style="width: {{ $percent }}%"></div></div>
            </div>
        @endif
    </section>

    <section class="card">
        @auth
            @include('campaigns.partials.donate-form')
        @else
            <h2>Faire un don</h2>
            <p>La plateforme est privée : connectez-vous pour donner et suivre vos dons.</p>
            <div class="row">
                <a class="btn" href="{{ route('register') }}">Créer un compte</a>
                <a class="btn secondary" href="{{ route('login') }}">Se connecter</a>
            </div>
        @endauth
    </section>
</div>
@endsection
