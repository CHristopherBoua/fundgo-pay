<?php

namespace App\Enums;

enum PaymentMethodStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
