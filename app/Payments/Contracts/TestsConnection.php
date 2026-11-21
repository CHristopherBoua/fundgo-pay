<?php

namespace App\Payments\Contracts;

use App\Payments\Exceptions\PaymentGatewayException;

/** Passerelle capable de vérifier ses identifiants (bouton « Tester la connexion » de l'admin). */
interface TestsConnection
{
    /**
     * @return string message de succès
     *
     * @throws PaymentGatewayException
     */
    public function testConnection(): string;
}
