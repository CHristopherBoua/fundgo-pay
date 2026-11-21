<?php

namespace App\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Services\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Paramètres modifiables depuis le back-office (clés de liaison PSP, routage).
 *
 * - Liste blanche stricte : seules les clés déclarées ici sont modifiables.
 * - Valeurs chiffrées au repos (APP_KEY) ; les secrets ne sont jamais réaffichés.
 * - Une valeur saisie dans l'admin remplace celle du .env ; l'effacer revient au .env.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'app_settings.v1';

    /** Champs par passerelle : chemin relatif à payments.gateways.{gw}. */
    public const GATEWAYS = [
        'uba' => [
            'label' => 'UBA (SeerBit)',
            'docs' => 'https://docs-uba.seerbitapi.com',
            'fields' => [
                'options.publicKey' => ['label' => 'Clé publique (public key)', 'secret' => false],
                'options.secretKey' => ['label' => 'Clé secrète (private key)', 'secret' => true],
                'options.baseUrl' => ['label' => 'URL de l\'API', 'secret' => false, 'type' => 'url'],
                'currencies' => ['label' => 'Devises acceptées', 'secret' => false, 'type' => 'list'],
                'country_by_currency.XOF' => ['label' => 'Pays pour XOF', 'secret' => false],
                'country_by_currency.XAF' => ['label' => 'Pays pour XAF', 'secret' => false],
                'hosted_tokenization' => ['label' => 'Tokenisation hébergée confirmée par SeerBit (débits automatiques)', 'secret' => false, 'type' => 'bool'],
            ],
        ],
        'ecobank' => [
            'label' => 'Ecobank Direct Debit',
            'docs' => 'https://apimuat-developer.ecobank.com/documentation',
            'fields' => [
                'options.baseUrl' => ['label' => 'URL de l\'API (base_url)', 'secret' => false, 'type' => 'url'],
                'options.tokenPath' => ['label' => 'Chemin du service de jeton', 'secret' => false],
                'options.subscriptionKey' => ['label' => 'Subscription key (Ocp-Apim)', 'secret' => true],
                'options.serviceCode' => ['label' => 'Service code', 'secret' => false],
                'options.clientId' => ['label' => 'Client ID', 'secret' => false],
                'options.secretKey' => ['label' => 'Secret key (hash SHA-512)', 'secret' => true],
                'options.affiliateCode' => ['label' => 'Affiliate code', 'secret' => false],
                'options.sourceCode' => ['label' => 'Source code', 'secret' => false],
                'options.ipAddress' => ['label' => 'IP publique du serveur', 'secret' => false],
                'options.userType' => ['label' => 'User type', 'secret' => false],
                'options.tokenCredentials.userId' => ['label' => 'Identifiant du jeton', 'secret' => false],
                'options.tokenCredentials.password' => ['label' => 'Mot de passe du jeton', 'secret' => true],
                'currencies' => ['label' => 'Devises acceptées', 'secret' => false, 'type' => 'list'],
            ],
        ],
        'mollie' => [
            'label' => 'Mollie',
            'docs' => 'https://docs.mollie.com',
            'fields' => [
                'options.apiKey' => ['label' => 'Clé API (live_… / test_…)', 'secret' => true],
            ],
        ],
        'stripe' => [
            'label' => 'Stripe',
            'docs' => 'https://docs.stripe.com/keys',
            'fields' => [
                'options.apiKey' => ['label' => 'Clé secrète (sk_…)', 'secret' => true],
                'webhook_secret' => ['label' => 'Secret de signature des webhooks (whsec_…)', 'secret' => true],
            ],
        ],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Applique les valeurs stockées par-dessus la config (.env). Silencieux si la table n'existe pas encore. */
    public function apply(): void
    {
        try {
            // Le cache ne contient que les valeurs CHIFFRÉES : jamais un secret en clair dans le store de cache.
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()->get(['key', 'value'])
                ->mapWithKeys(fn (AppSetting $s) => [$s->key => $s->getRawOriginal('value')])->all());
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table') && ! str_contains($e->getMessage(), 'does not exist')) {
                Log::warning('[settings] could not load admin settings', ['error' => $e->getMessage()]);
            }

            return;
        }

        foreach ($rows as $key => $encrypted) {
            if (! $this->isAllowed($key)) {
                continue;
            }
            try {
                config([$key => $this->cast($key, Crypt::decryptString((string) $encrypted))]);
            } catch (DecryptException) {
                Log::error('[settings] cannot decrypt setting (APP_KEY changed?)', ['key' => $key]);
            }
        }
    }

    /** @return list<string> */
    public function allowedKeys(): array
    {
        $keys = ['payments.default'];
        foreach (self::GATEWAYS as $gw => $def) {
            foreach (array_keys($def['fields']) as $field) {
                $keys[] = "payments.gateways.$gw.$field";
            }
        }
        foreach (['sepa_debit', 'card', 'ecobank_account'] as $type) {
            $keys[] = "payments.routing.$type";
            foreach (['XOF', 'XAF', 'NGN', 'USD'] as $cur) {
                $keys[] = "payments.routing_by_currency.$cur.$type";
            }
        }

        return $keys;
    }

    public function isAllowed(string $key): bool
    {
        return in_array($key, $this->allowedKeys(), true);
    }

    public function isSecret(string $key): bool
    {
        foreach (self::GATEWAYS as $gw => $def) {
            foreach ($def['fields'] as $field => $meta) {
                if ("payments.gateways.$gw.$field" === $key) {
                    return (bool) $meta['secret'];
                }
            }
        }

        return false;
    }

    public function type(string $key): string
    {
        foreach (self::GATEWAYS as $gw => $def) {
            foreach ($def['fields'] as $field => $meta) {
                if ("payments.gateways.$gw.$field" === $key) {
                    return $meta['type'] ?? 'string';
                }
            }
        }

        return 'string';
    }

    /**
     * Enregistre une série de valeurs. Pour un secret, une valeur vide = inchangé.
     * $clear : clés à effacer (retour à la valeur du .env).
     *
     * @param  array<string, string|bool|null>  $values
     * @param  list<string>  $clear
     * @return list<string> clés effectivement modifiées
     */
    public function save(array $values, array $clear, User $by): array
    {
        $changed = [];

        foreach ($clear as $key) {
            $this->assertAllowed($key);
            if (AppSetting::where('key', $key)->delete()) {
                $changed[] = $key;
            }
        }

        foreach ($values as $key => $value) {
            $this->assertAllowed($key);
            if (in_array($key, $clear, true)) {
                continue;
            }

            $value = $this->normalize($key, $value);
            $stored = AppSetting::where('key', $key)->first();

            if ($value === null) {
                // Champ non secret laissé vide : retour au .env. Secret vide : on ne touche à rien.
                if (! $this->isSecret($key) && $stored) {
                    $stored->delete();
                    $changed[] = $key;
                }

                continue;
            }

            if ($stored?->value === $value) {
                continue;
            }

            // Valeur identique à celle du .env : on ne la fige pas en base (le .env reste la source).
            if (! $stored && $value === $this->stringify($key, config($key))) {
                continue;
            }

            AppSetting::updateOrCreate(['key' => $key], [
                'value' => $value,
                'is_secret' => $this->isSecret($key),
                'updated_by' => $by->id,
            ]);
            $changed[] = $key;
        }

        if ($changed !== []) {
            // Journal : quelles clés, jamais les valeurs.
            $this->audit->log('settings.updated', 'AppSetting', null, after: ['keys' => $changed]);
            $this->flush();
        }

        return $changed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
        app()->forgetInstance(GatewayManager::class);
    }

    /** Valeur effective à afficher : en clair pour un champ public, masquée pour un secret. */
    public function display(string $key): array
    {
        $stored = AppSetting::where('key', $key)->first();
        $effective = config($key);
        $source = $stored ? 'admin' : (filled($effective) ? '.env' : null);

        if ($this->isSecret($key)) {
            $value = is_string($effective) && $effective !== '' ? '••••'.substr($effective, -4) : null;
        } elseif ($this->type($key) === 'list') {
            $value = is_array($effective) ? implode(',', $effective) : (string) $effective;
        } elseif ($this->type($key) === 'bool') {
            $value = (bool) $effective;
        } else {
            $value = is_scalar($effective) ? (string) $effective : null;
        }

        return ['value' => $value, 'source' => $source, 'updated_at' => $stored?->updated_at, 'editor' => $stored?->editor?->email];
    }

    private function normalize(string $key, mixed $value): ?string
    {
        if ($this->type($key) === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if ($this->type($key) === 'list') {
            $value = implode(',', array_values(array_filter(array_map(fn ($v) => strtoupper(trim($v)), explode(',', $value)))));
        }
        if ($this->type($key) === 'url' && ! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("URL invalide pour $key");
        }

        return mb_substr($value, 0, 2000);
    }

    private function stringify(string $key, mixed $effective): ?string
    {
        return match ($this->type($key)) {
            'bool' => $effective ? '1' : '0',
            'list' => is_array($effective) ? implode(',', $effective) : (string) $effective,
            default => is_scalar($effective) ? (string) $effective : null,
        };
    }

    private function cast(string $key, string $value): mixed
    {
        return match ($this->type($key)) {
            'bool' => $value === '1',
            'list' => array_values(array_filter(explode(',', $value))),
            default => $value,
        };
    }

    private function assertAllowed(string $key): void
    {
        if (! $this->isAllowed($key)) {
            throw new InvalidArgumentException("Setting [$key] is not editable");
        }
    }
}
