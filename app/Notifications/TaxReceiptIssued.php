<?php

namespace App\Notifications;

use App\Models\TaxReceipt;
use App\Services\TaxReceipts\TaxReceiptRenderer;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaxReceiptIssued extends Notification
{
    use Queueable;

    public function __construct(public readonly TaxReceipt $receipt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->receipt;
        $mail = (new MailMessage)
            ->subject('Votre reçu fiscal '.$r->fiscal_year.' (n° '.$r->number.')')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Merci pour votre générosité en '.$r->fiscal_year.' : '.Money::format($r->amount_cents, $r->currency).' de dons.');

        if ($r->replaces_id) {
            $mail->line('Ce reçu rectificatif annule et remplace le reçu n° '.$r->replaces?->number.'.');
        }

        return $mail
            ->line('Réduction d\'impôt estimée : '.Money::format($r->estimatedReductionCents(), $r->currency).' (à titre indicatif, dans la limite de 20 % du revenu imposable).')
            ->line('Votre reçu est joint à cet e-mail et reste disponible dans votre espace donateur.')
            ->action('Mon espace donateur', route('dashboard'))
            ->attachData(app(TaxReceiptRenderer::class)->render($r), $r->filename(), ['mime' => 'application/pdf']);
    }
}
