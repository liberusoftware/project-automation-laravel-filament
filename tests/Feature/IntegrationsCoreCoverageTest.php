<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Services {
    class IntegrationHostMock
    {
        public static ?string $fakeHostIp = null;
    }

    if (! function_exists(__NAMESPACE__.'\\gethostbyname')) {
        function gethostbyname(string $hostname): string
        {
            if (IntegrationHostMock::$fakeHostIp !== null) {
                return IntegrationHostMock::$fakeHostIp;
            }

            return \gethostbyname($hostname);
        }
    }

    if (! class_exists(QuickBooksService::class)) {
        class QuickBooksService
        {
            public function syncInvoice($integration, $invoice): array
            {
                return ['platform' => 'quickbooks', 'type' => 'invoice', 'data' => $invoice];
            }

            public function syncPayment($integration, $payment): array
            {
                return ['platform' => 'quickbooks', 'type' => 'payment', 'data' => $payment];
            }

            public function connect(array $credentials): array
            {
                return ['platform' => 'quickbooks', 'connected' => true, 'credentials' => $credentials];
            }
        }
    }

    if (! class_exists(XeroService::class)) {
        class XeroService
        {
            public function syncInvoice($integration, $invoice): array
            {
                return ['platform' => 'xero', 'type' => 'invoice', 'data' => $invoice];
            }

            public function syncPayment($integration, $payment): array
            {
                return ['platform' => 'xero', 'type' => 'payment', 'data' => $payment];
            }

            public function connect(array $credentials): array
            {
                return ['platform' => 'xero', 'connected' => true, 'credentials' => $credentials];
            }
        }
    }
}

namespace {
    use App\Exceptions\AccountingIntegrationException;
    use App\Models\ConnectedAccount;
    use App\Models\MarketingPlatformSettings;
    use App\Models\User;
    use Filament\Facades\Filament;
    use Filament\Schemas\Schema as FilamentSchema;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Table;
    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response as GuzzleResponse;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Client\Factory as HttpFactory;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\View;
    use Illuminate\Validation\ValidationException;
    use Laravel\Jetstream\Jetstream;
    use Laravel\Socialite\Contracts\User as SocialiteContractUser;
    use Laravel\Socialite\Facades\Socialite;
    use Laravel\Socialite\Two\AbstractProvider;
    use Laravel\Socialite\Two\User as SocialiteTwoUser;
    use Liberu\Foundation\Integrations\Enums\SocialPlatform;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\CreateOAuthConfiguration;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\EditOAuthConfiguration;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\ListOAuthConfigurations;
    use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;
    use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource\Pages\ListWebhookDeliveries;
    use Liberu\Foundation\Integrations\Http\Controllers\AccountingIntegrationController;
    use Liberu\Foundation\Integrations\Http\Controllers\Api\WebhookController;
    use Liberu\Foundation\Integrations\Http\Controllers\OAuthConfigurationController;
    use Liberu\Foundation\Integrations\Http\Controllers\OAuthController;
    use Liberu\Foundation\Integrations\Models\AccountingIntegration;
    use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
    use Liberu\Foundation\Integrations\Models\Webhook;
    use Liberu\Foundation\Integrations\Models\WebhookDelivery;
    use Liberu\Foundation\Integrations\Services\AccountingService;
    use Liberu\Foundation\Integrations\Services\FacebookMessengerService;
    use Liberu\Foundation\Integrations\Services\IntegrationHostMock;
    use Liberu\Foundation\Integrations\Services\MessageService;
    use Liberu\Foundation\Integrations\Services\OAuth\OAuthManager;
    use Liberu\Foundation\Integrations\Services\QuickBooksService;
    use Liberu\Foundation\Integrations\Services\Social\DirectSocialApiClient;
    use Liberu\Foundation\Integrations\Services\WebhookService;
    use Liberu\Foundation\Integrations\Services\WhatsAppBusinessService;
    use Liberu\Foundation\Integrations\Services\XeroService;
    use Liberu\Foundation\Integrations\Support\SsrfGuard;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\RolesPermissions\Models\Permission;
    use Liberu\Foundation\RolesPermissions\Models\Role as SpatieRole;
    use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
    use Livewire\Livewire;

    beforeEach(function (): void {
        Jetstream::useTeamModel(Team::class);
        PermissionCatalog::sync();

        Schema::dropIfExists('oauth_configurations');
        Schema::create('oauth_configurations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('service_name');
            $table->string('account_name')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->json('additional_settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('connected_accounts');
        Schema::create('connected_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('account_type')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('provider_id')->nullable();
            $table->string('name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone')->nullable();
            $table->text('avatar_path')->nullable();
            $table->text('token')->nullable();
            $table->text('secret')->nullable();
            $table->text('token_secret')->nullable();
            $table->text('refresh_token')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('emails')) {
            Schema::create('emails', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id')->nullable();
                $table->string('sender')->nullable();
                $table->string('recipient')->nullable();
                $table->string('subject')->nullable();
                $table->text('content')->nullable();
                $table->string('timestamp')->nullable();
                $table->boolean('is_sent')->default(false);
                $table->unsignedBigInteger('connected_account_id')->nullable();
                $table->timestamps();
            });
        }

        $viewDir = sys_get_temp_dir().'/test-views-integrations/oauth/configurations';
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0777, true);
        }
        file_put_contents($viewDir.'/index.blade.php', '<div>OAuth Index {{ count($configurations ?? []) }}</div>');
        file_put_contents($viewDir.'/create.blade.php', '<div>OAuth Create facebook twitter instagram linkedin youtube</div>');
        View::addLocation(sys_get_temp_dir().'/test-views-integrations');

        $appPanel = Filament::getPanel('app');
        $resources = [
            OAuthConfigurationResource::class,
            WebhookDeliveryResource::class,
        ];
        $appPanel->resources($resources);

        Route::middleware('web')->group(function () use ($resources): void {
            foreach ($resources as $resClass) {
                $slug = $resClass::getSlug();
                Route::get("/app/{$slug}", fn () => 'index')->name("filament.app.resources.{$slug}.index");
                Route::get("/app/{$slug}/create", fn () => 'create')->name("filament.app.resources.{$slug}.create");
                Route::get("/app/{$slug}/{record}/edit", fn () => 'edit')->name("filament.app.resources.{$slug}.edit");
            }
            Route::get('/oauth/configurations', [OAuthConfigurationController::class, 'index'])->name('oauth.configurations.index');
            Route::get('/oauth/configurations/create', [OAuthConfigurationController::class, 'create'])->name('oauth.configurations.create');
            Route::post('/oauth/configurations', [OAuthConfigurationController::class, 'store'])->name('oauth.configurations.store');
            Route::get('/oauth/configurations/{service}/{configId}/authenticate', [OAuthConfigurationController::class, 'authenticate'])->name('oauth.authenticate');
            Route::get('/oauth/configurations/{service}/callback', [OAuthConfigurationController::class, 'callback'])->name('oauth.configurations.callback');
            Route::delete('/oauth/configurations/{configuration}', [OAuthConfigurationController::class, 'destroy'])->name('oauth.configurations.destroy');
            Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
        });
        Route::getRoutes()->refreshNameLookups();
    });

    it('covers core integration models, SocialPlatform enum, and SsrfGuard', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $accounting = AccountingIntegration::create([
            'user_id' => $user->id,
            'platform' => 'quickbooks',
            'connection_details' => ['token' => 'abc'],
            'last_synced' => now(),
        ]);
        expect($accounting->user->is($user))->toBeTrue();

        $oauthConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'facebook',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $oauthConfig->save();
        expect(OAuthConfiguration::getConfig('facebook')?->id)->toBe($oauthConfig->id);

        $webhook = Webhook::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Test Hook',
            'url' => 'https://example.com/hook',
            'events' => ['contact.created'],
            'secret' => 'sec',
        ]);
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'team_id' => $team->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);
        expect($webhook->deliveries)->toHaveCount(1)
            ->and($delivery->webhook->is($webhook))->toBeTrue();

        foreach (SocialPlatform::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
        expect(SocialPlatform::options())->toHaveCount(16)
            ->and(SocialPlatform::values())->toHaveCount(16);

        SsrfGuard::assertPublicHttps('https://8.8.8.8/idp');
        SsrfGuard::assertPublicHttps('https://ns1.liberuhosting.com/idp');
        SsrfGuard::assertPublicHttps('https://unresolvable.example.invalid/idp');

        foreach ([
            'http://example.com',
            'https://',
            'https://localhost/idp',
            'https://sub.localhost/idp',
            'https://127.0.0.1/idp',
            'https://localhost.localdomain/idp',
        ] as $badUrl) {
            $thrown = false;
            try {
                SsrfGuard::assertPublicHttps($badUrl);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            expect($thrown)->toBeTrue("Expected SsrfGuard to reject {$badUrl}");
        }
    });

    it('covers AccountingIntegrationController and Api WebhookController', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $mockAccounting = Mockery::mock(AccountingService::class);
        $mockAccounting->shouldReceive('connectPlatform')
            ->once()
            ->with('quickbooks', ['code' => '123'])
            ->andReturn(['access_token' => 'qb-token']);
        $mockAccounting->shouldReceive('connectPlatform')
            ->once()
            ->with('xero', ['code' => 'bad'])
            ->andThrow(new Exception('Xero failed'));

        $accountingController = new AccountingIntegrationController($mockAccounting);
        $connectRes = $accountingController->connect(Request::create('/accounting/connect', 'POST', [
            'platform' => 'quickbooks',
            'credentials' => ['code' => '123'],
        ]));
        expect($connectRes->getStatusCode())->toBe(200);

        $failRes = $accountingController->connect(Request::create('/accounting/connect', 'POST', [
            'platform' => 'xero',
            'credentials' => ['code' => 'bad'],
        ]));
        expect($failRes->getStatusCode())->toBe(400);

        $integration = AccountingIntegration::first();
        expect($accountingController->status($integration)->getData(true)['platform'])->toBe('quickbooks');
        expect($accountingController->disconnect($integration)->getStatusCode())->toBe(200);

        $apiWebhookController = app(WebhookController::class);
        $storeReq = Request::create('/api/v1/webhooks', 'POST', [
            'name' => 'API Hook',
            'url' => 'https://8.8.8.8/webhook',
            'events' => ['contact.created'],
        ]);
        $storeReq->setUserResolver(fn () => $user);
        $createdRes = $apiWebhookController->store($storeReq);
        expect($createdRes->getStatusCode())->toBe(201);
        $webhookModel = Webhook::findOrFail($createdRes->getData(true)['id']);

        $indexReq = Request::create('/api/v1/webhooks', 'GET');
        $indexReq->setUserResolver(fn () => $user);
        expect($apiWebhookController->index($indexReq)->getStatusCode())->toBe(200);

        $noTeamUser = User::factory()->create(['current_team_id' => null]);
        $noTeamReq = Request::create('/api/v1/webhooks', 'GET');
        $noTeamReq->setUserResolver(fn () => $noTeamUser);
        expect($apiWebhookController->index($noTeamReq)->getStatusCode())->toBe(200);

        expect($apiWebhookController->show($indexReq, $webhookModel)->getStatusCode())->toBe(200);

        $updateReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', ['name' => 'Updated Hook']);
        $updateReq->setUserResolver(fn () => $user);
        expect($apiWebhookController->update($updateReq, $webhookModel)->getData(true)['name'])->toBe('Updated Hook');

        expect($apiWebhookController->regenerateSecret($indexReq, $webhookModel)->getStatusCode())->toBe(200);
        expect($apiWebhookController->events()->getStatusCode())->toBe(200);

        $origEnv = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $prodValidReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', [
                'url' => 'https://8.8.8.8/webhook',
            ]);
            $prodValidReq->setUserResolver(fn () => $user);
            expect($apiWebhookController->update($prodValidReq, $webhookModel)->getStatusCode())->toBe(200);

            $prodBadReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', [
                'url' => 'http://127.0.0.1/webhook',
            ]);
            $prodBadReq->setUserResolver(fn () => $user);
            $thrownVal = false;
            try {
                $apiWebhookController->update($prodBadReq, $webhookModel);
            } catch (ValidationException) {
                $thrownVal = true;
            }
            expect($thrownVal)->toBeTrue();
        } finally {
            app()->detectEnvironment(fn () => $origEnv);
        }

        expect($apiWebhookController->destroy($indexReq, $webhookModel)->getStatusCode())->toBe(204);
    });

    it('covers OAuthConfigurationController and OAuthController', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        MarketingPlatformSettings::query()->updateOrCreate(['id' => 1], [
            'provider' => 'direct',
            'credentials' => [
                'google_client_id' => 'g-id',
                'google_client_secret' => 'g-sec',
                'facebook_client_id' => 'fb-id',
                'facebook_client_secret' => 'fb-sec',
                'microsoft_client_id' => 'ms-id',
                'microsoft_client_secret' => 'ms-sec',
                'whatsapp_client_id' => 'wa-id',
                'whatsapp_client_secret' => 'wa-sec',
            ],
        ]);

        $controller = app(OAuthConfigurationController::class);
        expect($controller->index()->render())->toContain('OAuth Index');
        expect($controller->create()->render())->toContain('OAuth Create');

        $storeRes = $this->post(route('oauth.configurations.store'), [
            'service_name' => 'youtube',
            'account_name' => 'My YT',
            'additional_settings' => ['region' => 'US'],
        ]);
        $ytConfig = OAuthConfiguration::where('service_name', 'youtube')->firstOrFail();
        $storeRes->assertRedirect(route('oauth.authenticate', ['service' => 'youtube', 'configId' => $ytConfig->id]));

        expect($controller->authenticate('youtube', $ytConfig->id)->isRedirect())->toBeTrue();

        $igConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'instagram',
            'account_name' => 'IG',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $igConfig->save();
        expect($controller->authenticate('instagram', $igConfig->id)->isRedirect())->toBeTrue();

        $outlookConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'outlook',
            'account_name' => 'Outlook',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $outlookConfig->save();
        expect($controller->authenticate('outlook', $outlookConfig->id)->isRedirect())->toBeTrue();

        $waConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'whatsapp',
            'account_name' => 'WA',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $waConfig->save();
        expect($controller->authenticate('whatsapp', $waConfig->id)->isRedirect())->toBeTrue();

        $twoUser = (new SocialiteTwoUser())->map([
            'id' => 'prov-123',
            'name' => 'Test User',
            'nickname' => 'testnick',
            'email' => 'yt@example.com',
            'avatar' => 'https://example.com/a.png',
        ]);
        $twoUser->token = 'access-tok-123';
        $twoUser->refreshToken = 'refresh-tok-123';
        $twoUser->expiresIn = 3600;

        Socialite::extend('google', function () use ($twoUser) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUser);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('with')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

            return $mock;
        });

        session(['oauth_config_id' => $ytConfig->id, 'oauth_config_service' => 'youtube']);
        expect($controller->callback('youtube')->isRedirect())->toBeTrue()
            ->and($ytConfig->fresh()->is_active)->toBeTrue()
            ->and($ytConfig->fresh()->access_token)->toBe('access-tok-123');

        $twoUserNoExpiry = (new SocialiteTwoUser())->map(['id' => 'ig-1', 'name' => 'IG', 'email' => 'ig@example.com', 'avatar' => null]);
        $twoUserNoExpiry->token = 'ig-tok';
        $twoUserNoExpiry->refreshToken = null;
        $twoUserNoExpiry->expiresIn = null;

        Socialite::extend('facebook', function () use ($twoUserNoExpiry) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUserNoExpiry);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://facebook.com/oauth'));

            return $mock;
        });
        session(['oauth_config_id' => $igConfig->id, 'oauth_config_service' => 'instagram']);
        expect($controller->callback('instagram')->isRedirect())->toBeTrue();

        Socialite::extend('microsoft', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn(Mockery::mock(SocialiteContractUser::class));

            return $mock;
        });
        session(['oauth_config_id' => $outlookConfig->id, 'oauth_config_service' => 'outlook']);
        expect($controller->callback('outlook')->isRedirect())->toBeTrue();

        Socialite::extend('whatsapp', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn(Mockery::mock(SocialiteContractUser::class));

            return $mock;
        });
        session(['oauth_config_id' => $waConfig->id, 'oauth_config_service' => 'whatsapp']);
        expect($controller->callback('whatsapp')->isRedirect())->toBeTrue();
        expect($controller->destroy($waConfig)->isRedirect())->toBeTrue();

        $oauthController = new OAuthController();
        expect($oauthController->redirect('unconfigured-provider')->isRedirect())->toBeTrue();
        expect($oauthController->callback('unconfigured-provider')->isRedirect())->toBeTrue();

        foreach (['google', 'facebook', 'linkedin-openid'] as $prov) {
            (new OAuthConfiguration([
                'user_id' => $user->id,
                'service_name' => $prov,
                'client_id' => 'cid',
                'client_secret' => 'csec',
            ]))->forceFill(['team_id' => $team->id])->save();
        }

        Socialite::extend('linkedin-openid', function () use ($twoUserNoExpiry) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUserNoExpiry);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://linkedin.com/oauth'));

            return $mock;
        });

        expect($oauthController->redirect('google')->isRedirect())->toBeTrue();

        Http::swap(new HttpFactory());
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['data' => [['id' => 'p1', 'name' => 'Page 1']]]),
            'https://api.linkedin.com/*' => Http::response(['elements' => [['organizationalTarget' => 'urn:li:organization:1']]]),
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'ch1']]]),
        ]);
        expect($oauthController->callback('facebook')->isRedirect())->toBeTrue();
        expect($oauthController->callback('linkedin-openid')->isRedirect())->toBeTrue();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();

        Http::swap(new HttpFactory());
        Http::fake(fn () => throw new Exception('HTTP network failure'));
        expect($oauthController->callback('facebook')->isRedirect())->toBeTrue();
        expect($oauthController->callback('linkedin-openid')->isRedirect())->toBeTrue();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();

        Socialite::extend('google', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andThrow(new Exception('Socialite error'));

            return $mock;
        });
        Socialite::forgetDrivers();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();
    });

    it('covers OAuthManager, DirectSocialApiClient, AccountingService, MessageService, and WebhookService', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        // OAuthManager
        $manager = new OAuthManager();
        expect($manager->getSupportedProviders())->toContain('google', 'mailchimp', 'twitter')
            ->and($manager->isProviderSupported('google'))->toBeTrue()
            ->and($manager->isProviderSupported('unknown'))->toBeFalse();

        expect(fn () => $manager->getAuthorizationUrl('unknown'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $manager->getAuthorizationUrl('google'))->toThrow(RuntimeException::class);
        expect(fn () => $manager->exchangeCodeForToken('unknown', 'code'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $manager->exchangeCodeForToken('google', 'code'))->toThrow(RuntimeException::class);

        foreach (['google', 'mailchimp', 'twitter'] as $prov) {
            (new OAuthConfiguration([
                'user_id' => $user->id,
                'service_name' => $prov,
                'client_id' => $prov.'-id',
                'client_secret' => $prov.'-sec',
            ]))->forceFill(['team_id' => $team->id])->save();
        }

        expect($manager->getAuthorizationUrl('google'))->toContain('access_type=offline')
            ->and($manager->getAuthorizationUrl('mailchimp'))->not->toContain('scope=')
            ->and($manager->getAuthorizationUrl('twitter'))->toContain('code_challenge=challenge');

        $mockHandler = new MockHandler([
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'mc-tok', 'expires_in' => 3600])),
            new GuzzleResponse(200, [], (string) json_encode(['user_id' => 'mc-u1', 'accountname' => 'MC Acc', 'login' => ['email' => 'mc@example.com']])),
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'mc-tok-2'])),
            new GuzzleResponse(500, [], 'Server Error'),
            new GuzzleResponse(400, [], 'Bad Request'),
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 3600])),
            new GuzzleResponse(500, [], 'Error'),
        ]);
        $refClient = new ReflectionProperty(OAuthManager::class, 'client');
        $refClient->setAccessible(true);
        $refClient->setValue($manager, new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]));

        $mcTokens = $manager->exchangeCodeForToken('mailchimp', 'code-1');
        expect($mcTokens['metadata']['user_id'])->toBe('mc-u1');
        expect($manager->exchangeCodeForToken('mailchimp', 'code-2')['metadata'])->toBe([]);
        expect(fn () => $manager->exchangeCodeForToken('google', 'bad-code'))->toThrow(RuntimeException::class);

        $connected = $manager->saveConnectedAccount('mailchimp', $mcTokens, $user->id);
        expect($connected->provider_id)->toBe('mc-u1');

        $connectedNoExp = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g1', 'token' => 't', 'refresh_token' => null, 'expires_at' => null,
        ]);
        expect($manager->needsRefresh($connectedNoExp))->toBeFalse()
            ->and($manager->refreshToken($connectedNoExp))->toBeFalse();

        $connectedUnsupported = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'unsupported', 'provider_id' => 'u1', 'token' => 't', 'refresh_token' => 'r',
        ]);
        expect(fn () => $manager->refreshToken($connectedUnsupported))->toThrow(InvalidArgumentException::class);

        $connectedUnconfigured = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'zoom', 'provider_id' => 'z1', 'token' => 't', 'refresh_token' => 'r',
        ]);
        expect(fn () => $manager->refreshToken($connectedUnconfigured))->toThrow(RuntimeException::class);

        $connectedGoogle = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g2', 'token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(2),
        ]);
        expect($manager->needsRefresh($connectedGoogle))->toBeTrue()
            ->and($manager->refreshToken($connectedGoogle))->toBeTrue()
            ->and($manager->refreshToken($connectedGoogle))->toBeFalse();

        // DirectSocialApiClient
        MarketingPlatformSettings::query()->updateOrCreate(['id' => 1], ['provider' => 'direct', 'credentials' => []]);
        config([
            'services.zernio.direct_api_enabled' => true,
            'services.social.direct_base_urls.facebook' => 'https://graph.facebook.com/v18.0',
        ]);
        $fbOAuth = (new OAuthConfiguration([
            'user_id' => $user->id, 'service_name' => 'facebook', 'client_id' => 'c', 'client_secret' => 's', 'access_token' => 'fb-direct-token',
        ]))->forceFill(['team_id' => $team->id]);
        $fbOAuth->save();
        $directClient = new DirectSocialApiClient();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://graph.facebook.com/v18.0/me' => Http::sequence()->push(['id' => 'fb-me'], 200)->push('error', 500),
        ]);
        expect($directClient->request($fbOAuth, 'get', '/me'))->toBe(['id' => 'fb-me']);
        expect(fn () => $directClient->request($fbOAuth, 'get', '/me'))->toThrow(RuntimeException::class);

        // AccountingService
        $accountingService = new AccountingService(new QuickBooksService(), new XeroService());
        $qb = new AccountingIntegration(['platform' => 'quickbooks']);
        $xero = new AccountingIntegration(['platform' => 'xero']);
        $other = new AccountingIntegration(['platform' => 'other']);
        expect($accountingService->syncInvoice($qb, ['id' => 1])['platform'])->toBe('quickbooks')
            ->and($accountingService->syncInvoice($xero, ['id' => 2])['platform'])->toBe('xero')
            ->and($accountingService->syncPayment($qb, ['id' => 1])['platform'])->toBe('quickbooks')
            ->and($accountingService->syncPayment($xero, ['id' => 2])['platform'])->toBe('xero')
            ->and($accountingService->connectPlatform('quickbooks', ['key' => 'k'])['connected'])->toBeTrue()
            ->and($accountingService->connectPlatform('xero', ['key' => 'k'])['connected'])->toBeTrue();
        expect(fn () => $accountingService->syncInvoice($other, []))->toThrow(AccountingIntegrationException::class);
        expect(fn () => $accountingService->syncPayment($other, []))->toThrow(AccountingIntegrationException::class);
        expect(fn () => $accountingService->connectPlatform('invalid', []))->toThrow(AccountingIntegrationException::class);

        // MessageService
        $makeHeader = fn (string $name, string $value) => new class($name, $value)
        {
            public function __construct(private string $n, private string $v) {}

            public function getName(): string
            {
                return $this->n;
            }

            public function getValue(): string
            {
                return $this->v;
            }
        };
        $makeGmailMessage = function (string $id, string $mode = 'body') use ($makeHeader): Google_Service_Gmail_Message {
            $msg = new Google_Service_Gmail_Message();
            $msg->setId($id);
            $msg->setThreadId('th-1');
            if ($mode === 'null_payload') {
                $msg->setPayload(null);

                return $msg;
            }
            $headers = [
                $makeHeader('From', 'sender@example.com'),
                $makeHeader('To', 'rcpt@example.com'),
                $makeHeader('Subject', 'Hello Subj'),
                $makeHeader('Date', '2026-01-01 10:00:00'),
                $makeHeader('Cc', 'cc1@example.com,cc2@example.com'),
                $makeHeader('Bcc', 'bcc1@example.com'),
            ];
            $msg->setPayload(new class($headers, $mode)
            {
                public function __construct(private array $headers, private string $mode) {}

                public function getHeaders(): array
                {
                    return $this->headers;
                }

                public function getBody(): ?object
                {
                    return $this->mode === 'body' ? new class()
                    {
                        public function getData(): string
                        {
                            return strtr(base64_encode('Body text'), '+/', '-_');
                        }
                    } : null;
                }

                public function getParts(): array
                {
                    if ($this->mode === 'parts') {
                        return [['mimeType' => 'text/plain', 'body' => ['data' => strtr(base64_encode('Parts text'), '+/', '-_')]]];
                    }
                    if ($this->mode === 'empty_parts') {
                        return [['mimeType' => 'text/html', 'body' => ['data' => '']]];
                    }

                    return [];
                }
            });

            return $msg;
        };

        $msgBody = $makeGmailMessage('g1', 'body');
        $msgParts = $makeGmailMessage('g2', 'parts');
        $msgNullPayload = $makeGmailMessage('g3', 'null_payload');
        $msgEmptyParts = $makeGmailMessage('g4', 'empty_parts');

        $usersMessages = Mockery::mock();
        Google_Service_Gmail::$mockUsersMessages = $usersMessages;
        $listResponse = new class([$msgBody, $msgParts])
        {
            public function __construct(private array $msgs) {}

            public function getMessages(): array
            {
                return $this->msgs;
            }
        };
        $usersMessages->shouldReceive('listUsersMessages')->andReturn($listResponse);
        $usersMessages->shouldReceive('get')->with('me', 'g1')->andReturn($msgBody);
        $usersMessages->shouldReceive('get')->with('me', 'g2')->andReturn($msgParts);
        $usersMessages->shouldReceive('get')->with('me', 'g3')->andReturn($msgNullPayload);
        $usersMessages->shouldReceive('get')->with('me', 'g4')->andReturn($msgEmptyParts);
        $usersMessages->shouldReceive('send')->andReturn($msgBody);

        $gmailAcc = ConnectedAccount::forceCreate(['user_id' => $user->id, 'provider' => 'gmail', 'account_type' => 'gmail', 'provider_id' => 'gm_1', 'token' => 'gm_tok']);
        $waAcc = ConnectedAccount::forceCreate(['user_id' => $user->id, 'provider' => 'whatsapp', 'account_type' => 'whatsapp', 'provider_id' => 'wa_1', 'token' => 'wa_tok']);
        $fbAcc = ConnectedAccount::forceCreate(['user_id' => $user->id, 'provider' => 'facebook', 'account_type' => 'facebook', 'provider_id' => 'fb_1', 'token' => 'fb_tok']);

        $waMock = Mockery::mock(WhatsAppBusinessService::class);
        $waMock->shouldReceive('getUnreadMessages')->andReturn(['wa-unread']);
        $waMock->shouldReceive('getMessage')->andReturn(['id' => 'wa-msg']);
        $waMock->shouldReceive('sendReply')->andReturn(['id' => 'wa-reply']);

        $fbMessengerMock = Mockery::mock(FacebookMessengerService::class);
        $fbMessengerMock->shouldReceive('getUnreadMessages')->andReturn(['fb-unread']);
        $fbMessengerMock->shouldReceive('getMessage')->andReturn(['id' => 'fb-msg']);
        $fbMessengerMock->shouldReceive('sendReply')->andReturn(['id' => 'fb-reply']);

        $messageService = new MessageService($waMock, $fbMessengerMock);
        $allUnread = $messageService->getUnreadMessages();
        expect($allUnread['email'])->toHaveCount(2)
            ->and($allUnread['whatsapp'])->toContain('wa-unread')
            ->and($allUnread['facebook'])->toContain('fb-unread');

        $msgGetEmailContent = (new ReflectionClass($messageService))->getMethod('getEmailContent');
        $msgGetEmailContent->setAccessible(true);
        expect($msgGetEmailContent->invoke($messageService, $msgNullPayload))->toBe('')
            ->and($messageService->getMessage('g1', 'email', $gmailAcc->id)->getId())->toBe('g1')
            ->and($messageService->getMessage('g2', 'email', $gmailAcc->id)->getId())->toBe('g2')
            ->and($messageService->getMessage('g4', 'email', $gmailAcc->id)->getId())->toBe('g4')
            ->and($messageService->getMessage('w1', 'whatsapp', $waAcc->id))->toBe(['id' => 'wa-msg'])
            ->and($messageService->getMessage('f1', 'facebook', $fbAcc->id))->toBe(['id' => 'fb-msg']);
        expect(fn () => $messageService->getMessage('x', 'unknown', $gmailAcc->id))
            ->toThrow(InvalidArgumentException::class, 'Invalid message type: unknown');
        expect($messageService->sendReply('g1', 'Reply body', 'email', $gmailAcc->id)->getId())->toBe('g1')
            ->and($messageService->sendReply('w1', 'Reply body', 'whatsapp', $waAcc->id))->toBe(['id' => 'wa-reply'])
            ->and($messageService->sendReply('f1', 'Reply body', 'facebook', $fbAcc->id))->toBe(['id' => 'fb-reply']);
        expect(fn () => $messageService->sendReply('x', 'Reply', 'unknown', $gmailAcc->id))
            ->toThrow(InvalidArgumentException::class, 'Invalid message type: unknown');

        // WebhookService
        $webhookService = new WebhookService();
        $hook = $webhookService->register([
            'name' => 'Hook 1', 'url' => 'https://hooks.example.com/receive', 'events' => ['contact.created'], 'secret' => 'sec_123',
        ]);
        expect($webhookService->update($hook, ['name' => 'Hook 1 Updated'])->name)->toBe('Hook 1 Updated');
        $newSec = $webhookService->regenerateSecret($hook);
        expect($newSec)->toHaveLength(32)
            ->and($webhookService->verifySignature('{"a":1}', 'sha256='.hash_hmac('sha256', '{"a":1}', $newSec), $newSec))->toBeTrue();

        Webhook::create(['name' => 'No Sec', 'url' => 'https://hooks.example.com/nosec', 'events' => ['contact.created'], 'secret' => null, 'is_active' => true]);

        Http::swap(new HttpFactory());
        Http::fake([
            'https://hooks.example.com/receive' => Http::response('OK', 200),
            'https://hooks.example.com/nosec' => Http::response('Fail', 500),
            'https://hooks.example.com/fail10' => Http::response('Fail', 500),
            'https://hooks.example.com/throw' => fn () => throw new RuntimeException('Conn error'),
            '/relative-only' => Http::response('OK', 200),
        ]);

        $webhookService->dispatch('contact.created', ['id' => 1]);
        expect(WebhookDelivery::count())->toBeGreaterThanOrEqual(2);

        $fail10Hook = Webhook::create(['name' => 'Fail 10', 'url' => 'https://hooks.example.com/fail10', 'events' => ['contact.created'], 'secret' => 's', 'is_active' => true, 'failure_count' => 9]);
        expect($webhookService->send($fail10Hook, 'contact.created', []))->toBeFalse()
            ->and($fail10Hook->fresh()->is_active)->toBeFalse();

        $throwHook = Webhook::create(['name' => 'Throw', 'url' => 'https://hooks.example.com/throw', 'events' => ['contact.created'], 'secret' => 's', 'is_active' => true]);
        expect($webhookService->send($throwHook, 'contact.created', []))->toBeFalse();

        app()->detectEnvironment(fn (): string => 'production');
        try {
            $relHook = Webhook::create(['name' => 'Relative', 'url' => '/relative-only', 'events' => ['contact.created'], 'secret' => 's', 'is_active' => true]);
            $webhookService->send($relHook, 'contact.created', []);

            IntegrationHostMock::$fakeHostIp = '127.0.0.1';
            expect(fn () => $webhookService->send($hook, 'contact.created', []))
                ->toThrow(RuntimeException::class, 'Webhook URL resolves to an internal IP address.');
        } finally {
            IntegrationHostMock::$fakeHostIp = null;
            app()->detectEnvironment(fn (): string => 'testing');
        }
    });

    it('covers OAuthConfigurationResource and WebhookDeliveryResource', function (): void {
        $superAdmin = User::factory()->withPersonalTeam()->create(['email_verified_at' => now()]);
        $team = $superAdmin->currentTeam;
        setPermissionsTeamId($team->id);
        $superAdminRole = SpatieRole::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
        $adminRole = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => null]);
        $superAdmin->assignRole($superAdminRole);
        $superAdmin->assignRole($adminRole);

        foreach (['view', 'create', 'update', 'delete'] as $act) {
            Permission::firstOrCreate(['name' => "{$act}_oauth_configuration", 'guard_name' => 'web']);
        }

        $regularUser = User::factory()->withPersonalTeam()->create(['email_verified_at' => now()]);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        $makeTable = static fn (): Table => Table::make(Mockery::mock(HasTable::class));

        $oauthRecord = (new OAuthConfiguration([
            'user_id' => $superAdmin->id,
            'service_name' => 'google',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $oauthRecord->save();

        expect(OAuthConfigurationResource::canViewAny())->toBeTrue()
            ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canCreate())->toBeTrue()
            ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canEdit($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getEditAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canView($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getViewAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canDelete($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canDeleteAny())->toBeTrue()
            ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::permissionResource())->toBe('oauth_configuration')
            ->and(OAuthConfigurationResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(OAuthConfigurationResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(OAuthConfigurationResource::getRelations())->toBe([])
            ->and(OAuthConfigurationResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        Livewire::test(ListOAuthConfigurations::class)->assertOk();
        Livewire::test(CreateOAuthConfiguration::class)
            ->fillForm([
                'service_name' => 'facebook',
                'account_name' => 'FB',
                'client_id' => 'fb-id',
                'client_secret' => 'fb-sec',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(EditOAuthConfiguration::class, ['record' => $oauthRecord->getRouteKey()])
            ->fillForm(['account_name' => 'Updated Google'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->actingAs($regularUser);
        expect(OAuthConfigurationResource::canViewAny())->toBeFalse()
            ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getEditAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getViewAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

        $this->actingAs($superAdmin);
        Filament::setTenant($team);
        $wh = Webhook::create([
            'team_id' => $team->id,
            'user_id' => $superAdmin->id,
            'name' => 'WH',
            'url' => 'https://8.8.8.8/wh',
            'events' => ['contact.created'],
        ]);
        $whDelivery = WebhookDelivery::create([
            'webhook_id' => $wh->id,
            'team_id' => $team->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);
        expect(WebhookDeliveryResource::canAccess())->toBeTrue()
            ->and(WebhookDeliveryResource::canCreate())->toBeFalse()
            ->and(WebhookDeliveryResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::canEdit($whDelivery))->toBeFalse()
            ->and(WebhookDeliveryResource::getEditAuthorizationResponse($whDelivery)->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::canDelete($whDelivery))->toBeFalse()
            ->and(WebhookDeliveryResource::getDeleteAuthorizationResponse($whDelivery)->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::getEloquentQuery()->count())->toBe(1)
            ->and(WebhookDeliveryResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(WebhookDeliveryResource::getPages())->toHaveKey('index');

        $allowSub = new class() extends WebhookDeliveryResource
        {
            public static function canCreate(): bool
            {
                return true;
            }

            public static function canEdit(Model $record): bool
            {
                return true;
            }

            public static function canDelete(Model $record): bool
            {
                return true;
            }
        };
        expect($allowSub::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and($allowSub::getEditAuthorizationResponse($whDelivery)->allowed())->toBeTrue()
            ->and($allowSub::getDeleteAuthorizationResponse($whDelivery)->allowed())->toBeTrue();

        Livewire::test(ListWebhookDeliveries::class)->assertOk();
    });
}
