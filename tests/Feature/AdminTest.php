<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Http\Middleware\EnsureAdmin;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Data\ChargeResult;
use App\Payments\GatewayManager;
use App\Services\Payments\PaymentProcessor;
use App\Settings\SettingsRepository;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakesPspHttp;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use FakesPspHttp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function admin(string $role = User::ROLE_SUPERADMIN): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    /** Admin connecté ET double authentification passée dans la session. */
    private function asAdmin(User $user): static
    {
        return $this->actingAs($user)->withSession([EnsureAdmin::SESSION_KEY => ['user' => $user->id, 'at' => now()->timestamp]]);
    }

    public function test_admin_area_is_hidden_from_donors_and_guests(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/admin/parametres')->assertNotFound();
    }

    public function test_create_superadmin_command_prompts_for_password(): void
    {
        $this->artisan('admin:create boss@fundgo.test --name=Boss')
            ->expectsQuestion('Mot de passe (12 caractères min.)', 'Tr3s-Solide-Mdp')
            ->expectsQuestion('Confirmation', 'Tr3s-Solide-Mdp')
            ->assertSuccessful();

        $user = User::where('email', 'boss@fundgo.test')->sole();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse($user->hasTwoFactor());

        $this->artisan('admin:create weak@fundgo.test')
            ->expectsQuestion('Mot de passe (12 caractères min.)', 'short')
            ->expectsQuestion('Confirmation', 'short')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'weak@fundgo.test']);
    }

    public function test_two_factor_is_enforced_on_first_visit_then_on_each_new_session(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_SUPERADMIN])->save();

        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.2fa.setup'));
        $this->actingAs($user)->get(route('admin.2fa.setup'))->assertOk()->assertSee('Activer la double authentification');
        $secret = session('admin.2fa_pending');

        $this->actingAs($user)->post(route('admin.2fa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('admin.2fa.confirm'), ['code' => Totp::code($secret)])->assertRedirect(route('admin.dashboard'));
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret')); // chiffré en base
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Tableau de bord');

        // Nouvelle session : code demandé à nouveau.
        $this->flushSession();
        $this->actingAs($user)->get('/admin/paiements')->assertRedirect(route('admin.2fa.challenge'));
        $this->actingAs($user)->post(route('admin.2fa.verify'), ['code' => Totp::code($secret)])->assertRedirect(url('/admin/paiements'));
    }

    public function test_regular_admin_cannot_open_settings_or_manage_admins(): void
    {
        $admin = $this->admin(User::ROLE_ADMIN);

        $this->asAdmin($admin)->get('/admin')->assertOk();
        $this->asAdmin($admin)->get(route('admin.settings'))->assertForbidden();
        $this->asAdmin($admin)->get(route('admin.admins'))->assertForbidden();
    }

    public function test_superadmin_sets_uba_keys_from_settings(): void
    {
        $boss = $this->admin();
        $this->asAdmin($boss)->get(route('admin.settings'))->assertOk()->assertSee('UBA (SeerBit)')->assertSee('Clé publique');

        $this->asAdmin($boss)->put(route('admin.settings.gateway', 'uba'), [
            's_payments__gateways__uba__options__publicKey' => 'SBPUBK_live_123',
            's_payments__gateways__uba__options__secretKey' => 'SBSECK_live_SECRET_9876',
            's_payments__gateways__uba__currencies' => 'xof, ngn',
            's_payments__gateways__uba__hosted_tokenization' => '0',
        ])->assertSessionHas('status');

        // Appliqué immédiatement à la config et aux drivers.
        $this->assertSame('SBPUBK_live_123', config('payments.gateways.uba.options.publicKey'));
        $this->assertSame('SBSECK_live_SECRET_9876', config('payments.gateways.uba.options.secretKey'));
        $this->assertSame(['XOF', 'NGN'], config('payments.gateways.uba.currencies'));
        $this->assertTrue(app(GatewayManager::class)->driver('uba')->supportsCurrency('XOF'));

        // Chiffré en base, absent du cache et du journal en clair.
        $raw = DB::table('app_settings')->where('key', 'payments.gateways.uba.options.secretKey')->value('value');
        $this->assertStringNotContainsString('SECRET_9876', $raw);
        $this->assertTrue(AppSetting::where('key', 'payments.gateways.uba.options.secretKey')->value('is_secret'));
        $this->assertStringNotContainsString('SECRET_9876', serialize(Cache::get('app_settings.v1')));
        $this->assertStringNotContainsString('SECRET_9876', json_encode(AuditLog::where('action', 'settings.updated')->sole()->after));

        // La page ne réaffiche jamais le secret.
        $page = $this->asAdmin($boss)->get(route('admin.settings'));
        $page->assertDontSee('SBSECK_live_SECRET_9876')->assertSee('••••9876')->assertSee('SBPUBK_live_123');

        // Secret laissé vide = conservé ; « revenir au .env » = supprimé.
        $this->asAdmin($boss)->put(route('admin.settings.gateway', 'uba'), ['s_payments__gateways__uba__options__secretKey' => '']);
        $this->assertSame('SBSECK_live_SECRET_9876', config('payments.gateways.uba.options.secretKey'));

        $this->asAdmin($boss)->put(route('admin.settings.gateway', 'uba'), ['clear' => ['s_payments__gateways__uba__options__secretKey' => '1']]);
        $this->assertDatabaseMissing('app_settings', ['key' => 'payments.gateways.uba.options.secretKey']);
    }

    public function test_settings_survive_a_reboot_from_the_encrypted_store(): void
    {
        $boss = $this->admin();
        $this->asAdmin($boss)->put(route('admin.settings.gateway', 'ecobank'), [
            's_payments__gateways__ecobank__options__clientId' => 'CLIENT-42',
            's_payments__gateways__ecobank__options__baseUrl' => 'https://api.ecobank.test',
        ]);

        config(['payments.gateways.ecobank.options.clientId' => null]);
        Cache::flush();
        app(SettingsRepository::class)->apply(); // comme au démarrage

        $this->assertSame('CLIENT-42', config('payments.gateways.ecobank.options.clientId'));

        $this->asAdmin($boss)->put(route('admin.settings.gateway', 'ecobank'), ['s_payments__gateways__ecobank__options__baseUrl' => 'pas une url'])
            ->assertSessionHasErrors('settings');
    }

    public function test_connection_test_button_checks_uba_credentials(): void
    {
        $boss = $this->admin();
        config(['payments.gateways.uba.options.publicKey' => 'PUB', 'payments.gateways.uba.options.secretKey' => 'SEC']);

        $this->fakePsp([[200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'EncryptedSecKey' => ['encryptedKey' => 'tok']]]]]);
        $this->asAdmin($boss)->post(route('admin.settings.test', 'uba'))->assertSessionHas('status', 'Clés UBA valides : jeton obtenu.');
        $this->assertSame(['key' => 'SEC.PUB'], $this->pspJson(0));

        $this->fakePsp([[401, ['status' => 'FAILED', 'data' => ['code' => 'S1', 'message' => 'Invalid credentials']]]]);
        $this->asAdmin($boss)->post(route('admin.settings.test', 'uba'))->assertSessionHasErrors('test');
    }

    public function test_routing_can_be_changed_from_settings(): void
    {
        $boss = $this->admin();
        $this->asAdmin($boss)->put(route('admin.settings.routing'), [
            's_payments__routing_by_currency__XOF__card' => 'uba',
            's_payments__routing_by_currency__XOF__ecobank_account' => 'ecobank',
        ])->assertSessionHas('status');

        $this->assertSame('uba', config('payments.routing_by_currency.XOF.card'));
        $this->assertSame('ecobank', config('payments.routing_by_currency.XOF.ecobank_account'));

        $this->asAdmin($boss)->put(route('admin.settings.routing'), ['s_payments__default' => 'paypal'])->assertStatus(422);
    }

    public function test_refund_through_gateway_and_manual_refund(): void
    {
        $boss = $this->admin();
        $donor = User::factory()->create();
        $make = fn (string $gateway) => Payment::create([
            'user_id' => $donor->id, 'gateway' => $gateway, 'method_type' => 'card', 'sequence_type' => 'oneoff',
            'amount_cents' => 3000, 'currency' => 'XOF', 'status' => PaymentStatus::Pending,
        ]);
        $processor = app(PaymentProcessor::class);

        // Encaissement simulé puis remboursement via l'API (passerelle fake).
        $p1 = $make('fake');
        $processor->apply($p1, new ChargeResult(PaymentStatus::Paid, 'ref1'));
        $this->asAdmin($boss)->post(route('admin.payments.refund', $p1->id))->assertSessionHas('status');
        $this->assertSame(PaymentStatus::Refunded, $p1->fresh()->status);

        // UBA : pas d'API de remboursement -> message, puis enregistrement manuel par le super-admin.
        $p2 = $make('uba');
        $processor->apply($p2, new ChargeResult(PaymentStatus::Paid, 'ref2'));
        $this->asAdmin($boss)->post(route('admin.payments.refund', $p2->id))->assertSessionHasErrors('refund');
        $this->asAdmin($boss)->post(route('admin.payments.mark-refunded', $p2->id), ['reason' => 'Remboursé via dashboard UBA #R-881'])->assertSessionHas('status');
        $this->assertSame(PaymentStatus::Refunded, $p2->fresh()->status);

        $this->assertSame(4, LedgerEntry::count()); // 2 encaissements + 2 contre-passations
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.marked_refunded', 'entity_id' => $p2->id]);

        $admin = $this->admin(User::ROLE_ADMIN);
        $this->asAdmin($admin)->post(route('admin.payments.mark-refunded', $p1->id), ['reason' => 'tentative'])->assertForbidden();
    }

    public function test_campaign_creation_and_admin_promotion(): void
    {
        $boss = $this->admin();

        $this->asAdmin($boss)->post(route('admin.campaigns.store'), [
            'title' => 'Bibliothèque de Lomé', 'currency' => 'XOF', 'country' => 'tg', 'goal' => '2000000', 'status' => 'open',
            'allows_recurring' => '1', 'tax_deductible' => '1',
        ])->assertRedirect(route('admin.campaigns.index'));

        $campaign = Campaign::where('slug', 'bibliotheque-de-lome')->sole();
        $this->assertSame(2_000_000, $campaign->goal_cents);
        $this->assertSame('TG', $campaign->country);
        $this->assertFalse($campaign->tax_deductible); // reçu fiscal : euros uniquement

        $member = User::factory()->create(['email' => 'aide@fundgo.test']);
        $this->asAdmin($boss)->post(route('admin.admins.grant'), ['email' => 'aide@fundgo.test', 'role' => 'admin'])->assertSessionHas('status');
        $this->assertTrue($member->fresh()->isAdmin());
        $this->asAdmin($boss)->delete(route('admin.admins.revoke', $boss->id))->assertStatus(422); // pas soi-même
        $this->asAdmin($boss)->delete(route('admin.admins.revoke', $member->id))->assertSessionHas('status');
        $this->assertFalse($member->fresh()->isAdmin());
    }

    public function test_all_admin_pages_render(): void
    {
        $boss = $this->admin();
        foreach (['admin.dashboard', 'admin.campaigns.index', 'admin.campaigns.create', 'admin.payments.index', 'admin.donors',
            'admin.receipts', 'admin.webhooks', 'admin.audit', 'admin.settings', 'admin.admins'] as $route) {
            $this->asAdmin($boss)->get(route($route))->assertOk();
        }
    }
}