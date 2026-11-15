<?php

namespace App\Payments\Data;

/**
 * Événement PSP normalisé. `eventId` sert de clé d'idempotence (unique par passerelle).
 * `result` est renseigné lorsque le PSP fournit un statut fiable (payload signé ou relu par API).
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $gatewayReference,
        public ?string $paymentId,
        public ?ChargeResult $result,
        public array $payload,
    ) {}
}
