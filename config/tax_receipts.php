<?php

/*
|--------------------------------------------------------------------------
| Reçus fiscaux (cerfa n° 11580*05)
|--------------------------------------------------------------------------
| À faire valider par votre expert-comptable : l'organisme engage sa
| responsabilité (amende fiscale de 20 % des sommes indûment mentionnées,
| art. 1740 A du CGI) s'il délivre des reçus sans y être éligible.
*/
return [
    'enabled' => (bool) env('TAX_RECEIPTS_ENABLED', true),

    'organization' => [
        'name' => env('ORG_NAME', env('APP_NAME', 'FundGo')),
        'address_line1' => env('ORG_ADDRESS_LINE1', '1 rue de l\'Exemple'),
        'postal_code' => env('ORG_POSTAL_CODE', '75000'),
        'city' => env('ORG_CITY', 'Paris'),
        'country' => env('ORG_COUNTRY', 'France'),
        'identifier' => env('ORG_IDENTIFIER', 'RNA W000000000'), // RNA ou SIREN
        'purpose' => env('ORG_PURPOSE', 'Objet de l\'organisme, tel que figurant dans les statuts.'),
        // Qualité de l'organisme (une des cases du cerfa), ex. :
        // « Association ou fondation reconnue d'utilité publique »,
        // « Œuvre ou organisme d'intérêt général », « Fondation universitaire »…
        'quality' => env('ORG_QUALITY', 'Œuvre ou organisme d\'intérêt général'),
        'signatory_name' => env('ORG_SIGNATORY_NAME', 'Prénom Nom'),
        'signatory_role' => env('ORG_SIGNATORY_ROLE', 'Président(e)'),
        // Signature scannée (PNG) facultative, chemin absolu ou relatif à base_path().
        'signature_image' => env('ORG_SIGNATURE_IMAGE'),
    ],

    // Article du CGI : 200 (particuliers), 238 bis (entreprises), 978 (IFI).
    'cgi_article' => env('TAX_RECEIPT_CGI_ARTICLE', '200'),

    // Taux de réduction affiché à titre indicatif (66 % art. 200 ; 75 % aide aux personnes en difficulté).
    'reduction_rate_percent' => (int) env('TAX_RECEIPT_REDUCTION_RATE', 66),

    'form' => 'Déclaration de don manuel',
    'nature' => 'Numéraire',
    'payment_mode' => 'Virement, prélèvement, carte bancaire',

    // Un reçu n'est émis que si l'adresse du donateur est complète (mention obligatoire du cerfa).
    'require_donor_address' => true,

    // Cache des métriques de polices de dompdf (doit être inscriptible ; jamais dans vendor/).
    'font_cache' => env('TAX_RECEIPT_FONT_CACHE', storage_path('fonts')),
];
