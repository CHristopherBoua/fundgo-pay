@extends('layouts.app')
@section('title', 'Reçus fiscaux - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Reçus fiscaux</h1>
<form method="post" action="{{ route('admin.receipts.issue') }}" class="row card" style="margin:12px 0 16px" onsubmit="return confirm('Émettre les reçus manquants pour cette année ?')">
    @csrf
    <label for="year" style="margin:0">Émettre les reçus de l'année</label>
    <select id="year" name="year" style="width:auto">@foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
    <button class="btn small">Émettre</button>
    <span class="fineprint">Idempotent : les donateurs déjà couverts ne reçoivent pas de doublon. Envoi automatique chaque 15 janvier.</span>
</form>
<div class="card table-scroll"><table>
    <thead><tr><th>N°</th><th>Année</th><th>Donateur</th><th>Montant</th><th>Statut</th><th>Émis le</th>@if (auth()->user()->isSuperAdmin())<th></th>@endif</tr></thead>
    <tbody>
    @forelse ($receipts as $r)
        <tr>
            <td>{{ $r->number }}</td>
            <td>{{ $r->fiscal_year }}</td>
            <td>{{ $r->donor['name'] ?? '' }}<div class="fineprint">{{ $r->user?->email }}</div></td>
            <td>{{ \App\Support\Money::format($r->amount_cents, $r->currency) }}</td>
            <td><span class="badge {{ $r->isIssued() ? 'paid' : 'failed' }}">{{ $r->isIssued() ? 'Valide' : 'Annulé' }}</span>
                @if ($r->cancellation_reason)<div class="fineprint">{{ $r->cancellation_reason }}</div>@endif</td>
            <td>{{ $r->issued_at->format('d/m/Y') }}</td>
            @if (auth()->user()->isSuperAdmin())
                <td>@if ($r->isIssued())
                    <details class="fineprint"><summary>Annuler / rectifier</summary>
                        <form method="post" action="{{ route('admin.receipts.cancel', $r->id) }}" class="stack" style="margin-top:6px">
                            @csrf<input type="text" name="reason" required minlength="5" placeholder="Motif"><button class="btn small danger">Annuler et réémettre</button>
                        </form>
                    </details>
                @endif</td>
            @endif
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Aucun reçu émis.</td></tr>
    @endforelse
    </tbody>
</table>
<div style="margin-top:12px">{{ $receipts->links() }}</div></div>
@endsection
