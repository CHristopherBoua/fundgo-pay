@extends('layouts.app')
@section('title', 'Paiement simulé')

@section('content')
<div class="card" style="max-width:480px;margin:0 auto">
    <span class="badge pending">Passerelle simulée - aucun argent réel</span>
    <h1 style="margin-top:12px">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</h1>
    <p class="muted">{{ $payment->description() }}</p>
    @if ($payment->sequence_type === \App\Enums\SequenceType::First)
        <p class="fineprint">Premier paiement d'un don régulier : la carte (simulée •••• 4242) sera enregistrée pour les prochaines échéances.</p>
    @endif
    <form method="post" action="{{ route('fake-gateway.complete', ['payment' => $payment->id]) }}" class="row" style="margin-top:16px">
        @csrf
        <input type="hidden" name="return" value="{{ $return }}">
        <button class="btn" name="decision" value="pay">Payer (3DS OK)</button>
        <button class="btn danger" name="decision" value="decline">Refuser</button>
        <button class="btn secondary" name="decision" value="cancel">Annuler</button>
    </form>
</div>
@endsection
