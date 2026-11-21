<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Payments\Contracts\TestsConnection;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Paramètres (super-admin) : clés de liaison des PSP et routage des paiements. */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function index(): View
    {
        $gateways = [];
        foreach (SettingsRepository::GATEWAYS as $gw => $def) {
            $fields = [];
            foreach ($def['fields'] as $field => $meta) {
                $key = "payments.gateways.$gw.$field";
                $fields[] = $meta + ['key' => $key, 'name' => $this->inputName($key), 'type' => $meta['type'] ?? 'string']
                    + $this->settings->display($key);
            }
            $gateways[$gw] = array_merge($def, ['fields' => $fields, 'testable' => $this->testable($gw)]);
        }

        $routing = [];
        foreach (['sepa_debit' => 'SEPA', 'card' => 'Carte', 'ecobank_account' => 'Compte Ecobank'] as $type => $label) {
            $row = ['label' => $label, 'default' => $this->routingCell("payments.routing.$type")];
            foreach (['XOF', 'XAF', 'NGN', 'USD'] as $cur) {
                $row[$cur] = $type === 'sepa_debit' ? null : $this->routingCell("payments.routing_by_currency.$cur.$type");
            }
            $routing[$type] = $row;
        }

        return view('admin.settings.index', [
            'gateways' => $gateways,
            'routing' => $routing,
            'defaultGateway' => $this->routingCell('payments.default'),
            'gatewayNames' => array_keys(config('payments.gateways')),
        ]);
    }

    public function updateGateway(Request $request, string $gateway): RedirectResponse
    {
        abort_unless(isset(SettingsRepository::GATEWAYS[$gateway]), 404);

        $values = [];
        $clear = [];
        foreach (array_keys(SettingsRepository::GATEWAYS[$gateway]['fields']) as $field) {
            $key = "payments.gateways.$gateway.$field";
            $name = $this->inputName($key);
            if ($this->settings->type($key) === 'bool') {
                $values[$key] = $request->boolean($name);
            } elseif ($request->has($name)) {
                $values[$key] = $request->input($name);
            }
            if ($request->boolean('clear.'.$name)) {
                $clear[] = $key;
            }
        }

        try {
            $changed = $this->settings->save($values, $clear, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['settings' => $e->getMessage()]);
        }

        return back()->with('status', $changed ? count($changed).' paramètre(s) '.SettingsRepository::GATEWAYS[$gateway]['label'].' enregistré(s).' : 'Aucun changement.');
    }

    public function updateRouting(Request $request): RedirectResponse
    {
        $names = array_merge(['default'], array_keys(config('payments.gateways')));
        $values = [];
        foreach ($this->settings->allowedKeys() as $key) {
            if (! str_starts_with($key, 'payments.routing') && $key !== 'payments.default') {
                continue;
            }
            $input = $this->inputName($key);
            if ($request->has($input)) {
                $value = (string) $request->input($input);
                abort_unless($value === '' || in_array($value, $names, true), 422);
                $values[$key] = $value;
            }
        }

        $changed = $this->settings->save($values, [], $request->user());

        return back()->with('status', $changed ? 'Routage mis à jour.' : 'Aucun changement.');
    }

    public function test(string $gateway, GatewayManager $gateways): RedirectResponse
    {
        abort_unless($this->testable($gateway), 404);

        try {
            /** @var TestsConnection $driver */
            $driver = $gateways->driver($gateway);
            $message = $driver->testConnection();
        } catch (PaymentGatewayException|\InvalidArgumentException $e) {
            return back()->withErrors(['test' => 'Échec de connexion à '.SettingsRepository::GATEWAYS[$gateway]['label'].' : '.$e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    private function testable(string $gateway): bool
    {
        $class = config("payments.gateways.$gateway.driver");

        return is_string($class) && is_subclass_of($class, TestsConnection::class);
    }

    private function routingCell(string $key): array
    {
        return ['name' => $this->inputName($key)] + $this->settings->display($key);
    }

    /** Nom de champ HTML sûr pour une clé de config (les points sont interprétés par PHP). */
    private function inputName(string $key): string
    {
        return 's_'.str_replace(['.', '-'], '__', $key);
    }
}
