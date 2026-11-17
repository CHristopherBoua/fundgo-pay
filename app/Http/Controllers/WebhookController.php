<?php

namespace App\Http\Controllers;

use App\Payments\Contracts\ParsesWebhookBatches;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Services\Payments\GatewayEventIngestor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, GatewayEventIngestor $ingestor): Response
    {
        try {
            $driver = $gateways->driver($gateway);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        try {
            $events = $driver instanceof ParsesWebhookBatches
                ? $driver->parseWebhookBatch($request)
                : [$driver->parseWebhook($request)];
        } catch (InvalidWebhookSignature $e) {
            Log::warning('[webhook] rejected', ['gateway' => $gateway, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response('invalid signature', 400);
        } catch (PaymentGatewayException $e) {
            // PSP injoignable pour la relecture : 503 => le PSP réessaiera plus tard.
            return response('temporarily unavailable', 503);
        }

        $errors = 0;
        foreach ($events as $event) {
            $errors += $ingestor->ingest($gateway, $event)->error ? 1 : 0;
        }

        // 200 même en cas d'erreur de traitement : l'événement est stocké et sera rejoué
        // (payments:replay-events --failed), inutile de faire boucler le PSP.
        return response($errors ? 'stored' : 'ok', 200);
    }
}
