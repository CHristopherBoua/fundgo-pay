<?php

namespace App\Services\Payments;

use App\Models\GatewayEvent;
use App\Models\Payment;
use App\Payments\Data\WebhookEvent;
use App\Payments\GatewayManager;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks : stockage brut + traitement idempotent + rejeu.
 * Un même événement reçu N fois n'est appliqué qu'une fois (unique gateway + event_id).
 */
class GatewayEventIngestor
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentProcessor $processor,
    ) {}

    public function ingest(string $gateway, WebhookEvent $event): GatewayEvent
    {
        $json = json_encode($event->payload, JSON_UNESCAPED_SLASHES);

        $row = GatewayEvent::firstOrCreate(
            ['gateway' => $gateway, 'event_id' => $event->eventId],
            [
                'type' => $event->type,
                'gateway_reference' => $event->gatewayReference,
                'payload' => $event->payload,
                'payload_sha256' => hash('sha256', (string) $json),
            ],
        );

        if ($row->processed_at) {
            return $row; // doublon : déjà traité
        }

        if ($event->result === null) {
            $row->update(['processed_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1]);

            return $row; // événement non pertinent, conservé pour l'audit
        }

        try {
            $payment = $this->findPayment($gateway, $event->paymentId, $event->gatewayReference);
            if (! $payment) {
                throw new \RuntimeException('payment not found');
            }

            $this->processor->apply($payment, $event->result, $gateway.':'.$event->eventId, 'gateway:'.$gateway);
            $row->update(['processed_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1]);
        } catch (\Throwable $e) {
            Log::error('[payments] webhook processing failed', ['gateway' => $gateway, 'event' => $event->eventId, 'error' => $e->getMessage()]);
            $row->update(['error' => mb_substr($e->getMessage(), 0, 2000), 'attempts' => $row->attempts + 1]);
        }

        return $row;
    }

    /** Rejoue un événement : relit le statut de référence chez le PSP et l'applique. */
    public function replay(GatewayEvent $row): bool
    {
        $payload = $row->payload;
        $paymentId = $payload['metadata']['payment_id']
            ?? $payload['data']['object']['metadata']['payment_id']
            ?? $payload['payment_id']
            ?? null;

        $payment = $this->findPayment($row->gateway, $paymentId, $row->gateway_reference);
        if (! $payment || ! $payment->gateway_reference) {
            $row->update(['error' => 'replay: payment not found', 'attempts' => $row->attempts + 1]);

            return false;
        }

        try {
            $result = $this->gateways->driver($row->gateway)->fetchPayment($payment);
            $this->processor->apply($payment, $result, $row->gateway.':'.$row->event_id, 'replay');
            $row->update(['processed_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1]);

            return true;
        } catch (\Throwable $e) {
            $row->update(['error' => mb_substr('replay: '.$e->getMessage(), 0, 2000), 'attempts' => $row->attempts + 1]);

            return false;
        }
    }

    private function findPayment(string $gateway, ?string $paymentId, ?string $reference): ?Payment
    {
        if ($paymentId && ($payment = Payment::where('gateway', $gateway)->find($paymentId))) {
            return $payment;
        }

        return $reference ? Payment::where(['gateway' => $gateway, 'gateway_reference' => $reference])->first() : null;
    }
}
