<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DonationReceipt extends Notification
{
    use Queueable;

    public function __construct(public readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;

        return (new MailMessage)
            ->subject('Merci pour votre don de '.Money::format($p->amount_cents, $p->currency))
            ->greeting('Merci '.$notifiable->name.' !')
            ->line('Nous avons bien reçu votre don de **'.Money::format($p->amount_cents, $p->currency).'**'
                .($p->campaign ? ' pour « '.$p->campaign->title.' »' : '').'.')
            ->line('Référence : '.$p->id)
            ->action('Voir mes dons', route('dashboard'))
            ->line('Votre reçu fiscal annuel sera disponible dans votre espace donateur.');
    }
}
