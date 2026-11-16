@extends('layouts.app')
@section('title', 'Paiement - '.config('app.name'))

@section('content')
@php($s = $payment->status)
<div class="card" style="max-width:560px;margin:0 auto" role="status">
    @if ($s === \App\Enums\PaymentStatus::Paid)
        <h1>Merci !</h1>
        <p>Votre don de <strong>{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</strong> a bien été reçu.</p>
        @if ($payment->recurringDonation?->next_charge_on)
            <p class="muted">Votre don régulier est actif. Prochaine échéance : {{ $payment->recurringDonation->next_charge_on->format('d/m/Y') }}.</p>
        @endif
    @elseif (in_array($s, [\App\Enums\PaymentStatus::Pending, \App\Enums\PaymentStatus::Processing], true))
        <h1>Paiement en cours</h1>
        <p>Nous attendons la confirmation de notre prestataire. Vous recevrez un e-mail dès qu'elle arrive.</p>
    @else
        <h1>Le paiement n'a pas abouti</h1>
        <p>{{ $payment->failure_message ?? 'Le paiement a été annulé.' }}</p>
        @if ($payment->campaign)
            <a class="btn" href="{{ route('campaigns.show', $payment->campaign) }}">Réessayer</a>
        @endif
    @endif
    <p style="margin-top:24px"><a href="{{ route('dashboard') }}">Aller à mon espace donateur</a></p>
</div>
@endsection
