<?php

return [
    // Durée de validité de la double authentification dans une session (minutes).
    'two_factor_ttl' => (int) env('ADMIN_2FA_TTL', 720),
    'issuer' => env('ADMIN_2FA_ISSUER', env('APP_NAME', 'FundGo')),
];
