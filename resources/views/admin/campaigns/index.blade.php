@extends('layouts.app')
@section('title', 'Campagnes - Administration')

@section('content')
@include('admin.partials.nav')
<div class="row" style="justify-content:space-between"><h1>Campagnes</h1><a class="btn" href="{{ route('admin.campaigns.create') }}">Nouvelle campagne</a></div>
<div class="card table-scroll" style="margin-top:16px"><table>
    <thead><tr><th>Campagne</th><th>Devise</th><th>Statut</th><th>Collecté</th><th>Dons</th><th>Options</th></tr></thead>
    <tbody>
    @foreach ($campaigns as $c)
        <tr>
            <td><a href="{{ route('admin.campaigns.edit', $c) }}">{{ $c->title }}</a><div class="fineprint">/{{ $c->slug }}</div></td>
            <td>{{ $c->currency }}</td>
            <td><span class="badge {{ $c->status === 'open' ? 'active' : '' }}">{{ $c->status }}</span></td>
            <td>{{ \App\Support\Money::format((int) ($c->raised ?? 0), $c->currency) }}@if ($c->goal_cents)<div class="fineprint">sur {{ \App\Support\Money::format($c->goal_cents, $c->currency) }}</div>@endif</td>
            <td>{{ $c->donations }}</td>
            <td class="fineprint">{{ $c->allows_recurring ? 'dons réguliers' : 'ponctuel seul' }}{{ $c->tax_deductible ? ' · reçu fiscal' : '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div style="margin-top:12px">{{ $campaigns->links() }}</div></div>
@endsection
