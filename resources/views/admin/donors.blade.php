@extends('layouts.app')
@section('title', 'Donateurs - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Donateurs</h1>
<form method="get" class="row" style="margin:12px 0 16px">
    <input type="text" name="q" value="{{ $q }}" placeholder="Nom ou e-mail" style="max-width:320px"><button class="btn small">Rechercher</button>
</form>
<div class="card table-scroll"><table>
    <thead><tr><th>Nom</th><th>E-mail</th><th>Ville</th><th>Paiements</th><th>Réguliers actifs</th><th>Rôle</th><th>Inscrit le</th></tr></thead>
    <tbody>
    @foreach ($donors as $d)
        <tr>
            <td>{{ $d->name }}</td>
            <td><a href="{{ route('admin.payments.index', ['q' => $d->email]) }}">{{ $d->email }}</a></td>
            <td>{{ $d->city }}{{ $d->hasCompleteAddress() ? '' : ' ⚠' }}</td>
            <td>{{ $d->paid_count }}</td>
            <td>{{ $d->active_recurring }}</td>
            <td><span class="badge">{{ $d->role }}</span></td>
            <td>{{ $d->created_at->format('d/m/Y') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="fineprint">⚠ adresse incomplète : pas de reçu fiscal possible.</p>
<div style="margin-top:12px">{{ $donors->links() }}</div></div>
@endsection
