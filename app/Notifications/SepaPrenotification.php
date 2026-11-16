<?php

namespace App\Notifications;

use App\Models\RecurringDonation;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pré-notification SEPA : montant et date du prochain prélèvement, RUM, ICS. */
class SepaPrenotification extends Notification
{
    use Queueable;

    public function __construct(public readonly RecurringDonation $recurring) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rd = $this->recurring;
        $method = $rd->paymentMethod;

        return (new MailMessage)
            ->subject('Prochain prélèvement de votre don le '.$rd->next_charge_on?->format('d/m/Y'))
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Nous prélèverons **'.Money::format($rd->amount_cents, $rd->currency).'** le **'.$rd->next_charge_on?->format('d/m/Y').'** sur '.$method?->displayName().'.')
            ->line('RUM : '.$method?->rum.' - ICS : '.config('payments.sepa.creditor_id').' ('.config('payments.sepa.creditor_name').')')
            ->line('Pour modifier ou suspendre ce prélèvement, rendez-vous dans votre espace avant cette date.')
            ->action('Mon espace donateur', route('dashboard'));
    }
}
