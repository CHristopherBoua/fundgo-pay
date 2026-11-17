<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly Payment $payment, public readonly bool $retryScheduled) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;

        if ($p->failure_code === 'not_paid') {
            return (new MailMessage)
                ->subject('Échéance de votre don non réglée')
                ->greeting('Bonjour '.$notifiable->name.',')
                ->line('L\'échéance du '.$p->period?->format('d/m/Y').' ('.Money::format($p->amount_cents, $p->currency).') n\'a pas été réglée et a été annulée.')
                ->line('Votre don régulier continue : la prochaine échéance vous sera envoyée. Vous pouvez aussi le suspendre depuis votre espace.')
                ->action('Mon espace donateur', route('dashboard'));
        }

        $mail = (new MailMessage)
            ->subject('Votre don n\'a pas pu être prélevé')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Le paiement de '.Money::format($p->amount_cents, $p->currency).' n\'a pas abouti ('.$p->failure_message.').');

        if ($this->retryScheduled && $p->next_retry_at) {
            $mail->line('Nous ferons une nouvelle tentative le '.$p->next_retry_at->format('d/m/Y').'. Aucune action n\'est nécessaire si votre compte est approvisionné.');
        } elseif ($p->recurring_donation_id) {
            $mail->line('Votre don régulier est suspendu. Vous pouvez le réactiver ou changer de moyen de paiement depuis votre espace.');
        }

        return $mail->action('Mon espace donateur', route('dashboard'));
    }
}
