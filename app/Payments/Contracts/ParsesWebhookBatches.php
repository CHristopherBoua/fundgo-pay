<?php

namespace App\Payments\Contracts;

use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use Illuminate\Http\Request;

/** PSP qui regroupe plusieurs événements dans une même notification (ex. SeerBit `notificationItems`). */
interface ParsesWebhookBatches
{
    /**
     * @return list<WebhookEvent>
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhookBatch(Request $request): array;
}
