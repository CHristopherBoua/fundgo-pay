<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Échéance d'un don régulier à régler par le donateur (lien de paiement). */
class InstallmentDue extends Notification
{
    use Queueable;

    public function __construct(public readonly Payment $payment, public readonly bool $reminder = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;
        $amount = Money::format($p->amount_cents, $p->currency);
        $expires = $p->metadata['link_expires_on'] ?? null;

        return (new MailMessage)
            ->subject(($this->reminder ? 'Rappel : ' : '').'votre don régulier de '.$amount.' est à régler')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('L\'échéance du '.$p->period?->format('d/m/Y').' de votre don régulier'
                .($p->campaign ? ' pour « '.$p->campaign->title.' »' : '').' est prête : '.$amount.'.')
            ->line('Réglez-la en quelques secondes ('.$p->method_type->label().').')
            ->action('Régler mon don', route('installments.pay', $p->id))
            ->line($expires ? 'Ce lien reste valable jusqu\'au '.\Carbon\CarbonImmutable::parse($expires)->format('d/m/Y').'.' : '')
            ->line('Vous pouvez suspendre ou arrêter ce don à tout moment depuis votre espace.');
    }
}
