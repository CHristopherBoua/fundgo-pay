<?php

namespace App\Payments\Drivers;

use App\Enums\PaymentMethodType;
use App\Payments\Contracts\PaymentDriver;
use App\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\Log;
use Omnipay\Common\GatewayFactory;
use Omnipay\Common\GatewayInterface;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Common\Message\ResponseInterface;

/**
 * Socle commun des adaptateurs Omnipay : construction paresseuse de la passerelle,
 * envoi avec traduction des erreurs, conversion des montants.
 *
 * Le client HTTP peut être injecté via le conteneur (`omnipay.http_client`) : c'est ce
 * qui permet de tester les drivers sans réseau, avec des réponses PSP enregistrées.
 */
abstract class AbstractOmnipayDriver implements PaymentDriver
{
    private ?GatewayInterface $gateway = null;

    public function __construct(
        protected readonly string $name,
        protected readonly array $config,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /** Devises déclarées dans la config de la passerelle (`currencies`), EUR par défaut. */
    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->config['currencies'] ?? ['EUR'], true);
    }

    public function supportsOffSession(PaymentMethodType $type): bool
    {
        return $this->supports($type);
    }

    protected function gateway(): GatewayInterface
    {
        if ($this->gateway === null) {
            $httpClient = app()->bound('omnipay.http_client') ? app('omnipay.http_client') : null;
            $gateway = (new GatewayFactory)->create($this->config['omnipay'], $httpClient);
            $gateway->initialize($this->config['options'] ?? []);
            $this->gateway = $gateway;
        }

        return $this->gateway;
    }

    /** @throws PaymentGatewayException */
    protected function send(RequestInterface $request, string $operation): ResponseInterface
    {
        try {
            return $request->send();
        } catch (\Throwable $e) {
            Log::warning("[payments:{$this->name}] $operation transport error", ['error' => $e->getMessage()]);

            throw new PaymentGatewayException("[{$this->name}] $operation failed: {$e->getMessage()}", 'gateway_unavailable', [], $e);
        }
    }

    /** @throws PaymentGatewayException */
    protected function sendOrFail(RequestInterface $request, string $operation): ResponseInterface
    {
        $response = $this->send($request, $operation);

        if (! $response->isSuccessful() && ! $response->isRedirect()) {
            $data = (array) $response->getData();
            Log::warning("[payments:{$this->name}] $operation rejected", ['message' => $response->getMessage(), 'code' => $response->getCode()]);

            throw new PaymentGatewayException(
                "[{$this->name}] $operation rejected: ".($response->getMessage() ?: 'unknown error'),
                $response->getCode() ? (string) $response->getCode() : null,
                $data,
            );
        }

        return $response;
    }

    protected function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * URL de notification envoyée au PSP. Omise pour les hôtes locaux, que le PSP
     * ne peut pas joindre (Mollie refuse d'ailleurs ces URL).
     */
    protected function webhookUrl(): ?string
    {
        $url = route('webhooks.gateway', ['gateway' => $this->name]);
        $host = (string) parse_url($url, PHP_URL_HOST);

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');

        return $isLocal ? null : $url;
    }
}
