<?php

namespace App\Notifications;

use App\Models\RecurringDonation;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation d'un don régulier. Pour un mandat SEPA, cet e-mail contient les mentions
 * de pré-notification (montant, date, RUM, ICS) de la première échéance.
 */
class RecurringDonationConfirmed extends Notification
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

        $mail = (new MailMessage)
            ->subject('Votre don régulier est enregistré')
            ->greeting('Merci '.$notifiable->name.' !')
            ->line('Don de **'.Money::format($rd->amount_cents, $rd->currency).' '.$rd->frequency->perLabel().'**'
                .($rd->campaign ? ' pour « '.$rd->campaign->title.' »' : '').'.')
            ->line('Première échéance : '.$rd->next_charge_on?->format('d/m/Y').', puis le '.$rd->day_of_month.' '.mb_strtolower($rd->frequency->label()).'.');

        if ($method?->isSepa()) {
            $mail->line('Prélèvement SEPA sur '.$method->displayName().'.')
                ->line('Référence unique de mandat (RUM) : '.$method->rum)
                ->line('Identifiant créancier SEPA (ICS) : '.config('payments.sepa.creditor_id').' - '.config('payments.sepa.creditor_name'))
                ->line('Vous pouvez demander le remboursement d\'un prélèvement auprès de votre banque dans les 8 semaines suivant le débit.');
        }

        return $mail
            ->line('Vous pouvez suspendre, modifier ou arrêter ce don à tout moment.')
            ->action('Gérer mon don', route('dashboard'));
    }
}
