@extends('layouts.app')
@section('title', 'Campagnes - '.config('app.name'))

@section('content')
    <h1>Soutenez nos projets</h1>
    <p class="muted">Un don ponctuel ou un don régulier, prélevé automatiquement. Vous gardez la main à tout moment depuis votre espace.</p>

    <div class="grid" style="margin-top:24px">
        @forelse ($campaigns as $campaign)
            <article class="card stack">
                <h2 style="margin:0">{{ $campaign->title }}</h2>
                <p class="muted" style="margin:8px 0 0">{{ $campaign->summary }}</p>
                @php($percent = $campaign->progressPercent())
                <div>
                    <strong>{{ \App\Support\Money::format($campaign->raisedCents(), $campaign->currency) }}</strong>
                    @if ($campaign->goal_cents)
                        <span class="muted">sur {{ \App\Support\Money::format($campaign->goal_cents, $campaign->currency) }}</span>
                        <div class="progress" style="margin-top:8px" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><div style="width: {{ $percent }}%"></div></div>
                    @endif
                </div>
                <a class="btn" href="{{ route('campaigns.show', $campaign) }}">Faire un don</a>
            </article>
        @empty
            <p class="muted">Aucune campagne ouverte pour le moment.</p>
        @endforelse
    </div>
@endsection
