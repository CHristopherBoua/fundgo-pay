@extends('layouts.app')
@section('title', 'Paiements - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Paiements</h1>
<form method="get" class="row" style="margin:12px 0 16px">
    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="E-mail, id ou référence PSP" style="max-width:280px">
    <select name="status" style="width:auto"><option value="">Tous statuts</option>
        @foreach (\App\Enums\PaymentStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach
    </select>
    <select name="gateway" style="width:auto"><option value="">Toutes passerelles</option>
        @foreach ($gateways as $g)<option value="{{ $g }}" @selected(($filters['gateway'] ?? '') === $g)>{{ $g }}</option>@endforeach
    </select>
    <select name="currency" style="width:auto"><option value="">Toutes devises</option>
        @foreach (\App\Support\Money::supported() as $c)<option value="{{ $c }}" @selected(($filters['currency'] ?? '') === $c)>{{ $c }}</option>@endforeach
    </select>
    <button class="btn small">Filtrer</button>
</form>
<div class="card">
    @include('admin.partials.payments-table', ['payments' => $payments, 'actions' => true])
    <div style="margin-top:12px">{{ $payments->links() }}</div>
</div>
@endsection
