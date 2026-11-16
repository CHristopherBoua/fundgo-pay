<?php

namespace App\Notifications;

use App\Models\TaxReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaxReceiptCancelled extends Notification
{
    use Queueable;

    public function __construct(public readonly TaxReceipt $receipt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Annulation de votre reçu fiscal n° '.$this->receipt->number)
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Suite au remboursement de votre don, le reçu fiscal n° '.$this->receipt->number.' ('.$this->receipt->fiscal_year.') est annulé.')
            ->line('Il ne doit pas être utilisé pour votre déclaration de revenus.')
            ->action('Mon espace donateur', route('dashboard'));
    }
}
