<?php

namespace App\Services\Payments;

use App\Models\GatewayCustomer;
use App\Models\User;
use App\Payments\Contracts\PaymentDriver;

/** Un client PSP par donateur et par passerelle, créé à la demande. */
class CustomerService
{
    public function referenceFor(User $user, PaymentDriver $driver): string
    {
        $existing = GatewayCustomer::where(['user_id' => $user->id, 'gateway' => $driver->name()])->value('reference');

        if ($existing) {
            return $existing;
        }

        $reference = $driver->createCustomer($user);

        return GatewayCustomer::firstOrCreate(
            ['user_id' => $user->id, 'gateway' => $driver->name()],
            ['reference' => $reference],
        )->reference;
    }
}
