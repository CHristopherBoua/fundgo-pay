@extends('layouts.app')
@section('title', 'Administration')
@php($fmt = fn ($c, $cur) => \App\Support\Money::format((int) $c, $cur))

@section('content')
@include('admin.partials.nav')
<h1>Tableau de bord</h1>

<div class="grid" style="margin:16px 0 24px">
    <div class="card">
        <div class="muted">Collecté (total)</div>
        @forelse ($byCurrency as $row)
            <div class="stat">{{ $fmt($row->total, $row->currency) }}</div>
            <div class="fineprint">{{ $row->n }} paiement(s) · ce mois : {{ $fmt($thisMonth[$row->currency] ?? 0, $row->currency) }}</div>
        @empty
            <div class="stat">-</div>
        @endforelse
    </div>
    <div class="card"><div class="muted">Donateurs</div><div class="stat">{{ $donors }}</div>
        <div class="fineprint">{{ $activeRecurring }} don(s) régulier(s) actif(s) · {{ $pastDue }} à régulariser</div></div>
    <div class="card">
        <div class="muted">Contrôles</div>
        <div class="stack" style="margin-top:8px">
            <div><span class="badge {{ $ledger['ok'] ? 'paid' : 'failed' }}">Grand livre {{ $ledger['ok'] ? 'intègre' : 'ALTÉRÉ' }}</span>
                <span class="fineprint">{{ $ledger['entries'] }} écritures{{ $ledger['ok'] ? '' : ' · seq '.$ledger['broken_seq'].' : '.$ledger['reason'] }}</span></div>
            <div><a href="{{ route('admin.webhooks') }}"><span class="badge {{ $failedWebhooks ? 'failed' : 'paid' }}">{{ $failedWebhooks }} webhook(s) en erreur</span></a></div>
        </div>
    </div>
</div>

<section class="card stack" style="margin-bottom:24px">
    <div class="row" style="justify-content:space-between"><h2 style="margin:0">Campagnes</h2><a class="btn small" href="{{ route('admin.campaigns.create') }}">Nouvelle campagne</a></div>
    <div class="table-scroll"><table>
        <thead><tr><th>Campagne</th><th>Statut</th><th>Collecté</th><th>Objectif</th></tr></thead>
        <tbody>@foreach ($campaigns as $c)
            <tr><td><a href="{{ route('admin.campaigns.edit', $c) }}">{{ $c->title }}</a></td><td><span class="badge">{{ $c->status }}</span></td>
                <td>{{ $fmt($c->raised ?? 0, $c->currency) }}</td><td>{{ $c->goal_cents ? $fmt($c->goal_cents, $c->currency) : '-' }}</td></tr>
        @endforeach</tbody>
    </table></div>
</section>

<section class="card stack">
    <h2 style="margin:0">Derniers paiements</h2>
    @include('admin.partials.payments-table', ['payments' => $recent, 'actions' => false])
</section>
@endsection
