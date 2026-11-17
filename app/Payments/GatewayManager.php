<?php

namespace App\Payments;

use App\Enums\PaymentMethodType;
use App\Payments\Contracts\PaymentDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Résout l'adaptateur PSP à partir de config/payments.php.
 */
class GatewayManager
{
    /** @var array<string, PaymentDriver> */
    private array $drivers = [];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): PaymentDriver
    {
        $name ??= config('payments.default');

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Passerelle à utiliser pour un moyen de paiement et une devise.
     * Ordre : routing_by_currency.{DEVISE}.{type} > routing.{type} > default.
     */
    public function forMethod(PaymentMethodType $type, ?string $currency = null): PaymentDriver
    {
        $currency = strtoupper($currency ?? config('payments.currency'));
        $name = config("payments.routing_by_currency.$currency.{$type->value}")
            ?: config('payments.routing.'.$type->value)
            ?: null;

        $driver = $this->driver($name);

        if (! $driver->supports($type) || ! $driver->supportsCurrency($currency)) {
            throw new InvalidArgumentException("Gateway [{$driver->name()}] does not support [{$type->value}] in [$currency].");
        }

        return $driver;
    }

    /** Remplace un driver (tests). */
    public function extend(string $name, PaymentDriver $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    private function resolve(string $name): PaymentDriver
    {
        $config = config("payments.gateways.$name");

        if (! is_array($config) || ! isset($config['driver'])) {
            throw new InvalidArgumentException("Payment gateway [$name] is not configured.");
        }

        if (array_key_exists('enabled', $config) && ! $config['enabled']) {
            throw new InvalidArgumentException("Payment gateway [$name] is disabled.");
        }

        return $this->container->make($config['driver'], ['name' => $name, 'config' => $config]);
    }
}
