<?php

declare(strict_types=1);

use App\Models\User;
use Composer\InstalledVersions;
use Filament\Schemas\Schema;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JoelButcher\Socialstream\ConnectedAccount;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Localization\MyMemory\MyMemoryServiceProvider;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleManagement as ModuleManagementService;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;

it('covers organizations-teams remaining actions, models, and policy', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->create();
    $team = $user->currentTeam;

    expect(fn () => (new InviteMember())->handle($team->id, 'bad@example.test', 'not-a-valid-role', $user->id))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new TransferOwnership())->handle($team, $user->id, $other->id, true))
        ->toThrow(RuntimeException::class);

    $org = new Organization();
    expect($org->getCasts())->toHaveKey('archived_at')
        ->and($org->teams()->getRelated())->toBeInstanceOf(Team::class);

    $invite = new TeamInvitation();
    expect($invite->getCasts())->toHaveKeys(['expires_at', 'accepted_at', 'revoked_at'])
        ->and($invite->team()->getRelated())->toBeInstanceOf(Team::class);

    $policy = new TeamPolicy();
    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue();
});

it('covers roles-permissions Role::team and AnyTeamRoleLookup', function () {
    $role = new Role();
    expect($role->team()->getRelated())->toBeInstanceOf(Team::class);

    $user = User::factory()->create();
    $lookup = new AnyTeamRoleLookup();
    expect($lookup->hasRoleInAnyTeam($user, []))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($user, 'nonexistent-role'))->toBeFalse();
});

it('covers identity-socialstream remaining action branches', function () {
    $providerUser = (new SocialiteUser())->map([
        'id' => 'soc-999',
        'nickname' => 'ada',
        'name' => 'Ada Social',
        'email' => null,
        'avatar' => 'https://example.test/avatar.png',
    ])->setToken('tok-1');

    expect(fn () => (new CreateConnectedAccount())->create(new stdClass(), 'github', $providerUser))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new SetUserPassword())->set(new stdClass(), ['password' => 'Secret!123456', 'password_confirmation' => 'Secret!123456']))
        ->toThrow(InvalidArgumentException::class);

    // ResolveSocialiteUser with generateMissingEmails enabled
    $originalFeatures = config('socialstream.features', []);
    $originalJetstreamFeatures = config('jetstream.features', []);
    config()->set('socialstream.features', array_values(array_unique([...$originalFeatures, Features::generateMissingEmails(), Features::providerAvatars()])));
    config()->set('jetstream.features', array_values(array_unique([...$originalJetstreamFeatures, Laravel\Jetstream\Features::profilePhotos()])));
    config()->set('app.domain', '.example.test');

    $driver = Mockery::mock();
    $driver->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->with('github')->once()->andReturn($driver);

    $resolved = (new ResolveSocialiteUser())->resolve('github');
    expect($resolved->getEmail())->toBe('soc-999@github.example.test');

    // CreateUserFromProvider with providerAvatars enabled
    Storage::fake('public');
    Http::fake(['https://example.test/avatar.png' => Http::response('fake-image', 200, ['Content-Type' => 'image/png'])]);
    $createdUser = (new CreateUserFromProvider(new CreateConnectedAccount()))->create('github', $resolved);
    expect($createdUser->exists)->toBeTrue();

    config()->set('jetstream.features', $originalJetstreamFeatures);

    // UpdateConnectedAccount::updateRefreshToken
    $account = ConnectedAccount::query()->where('user_id', $createdUser->id)->firstOrFail();
    $refreshed = new RefreshedCredentials('new-tok', 'new-sec', 'new-ref', now()->addHour());
    $resolversProp = new ReflectionProperty(Socialstream::class, 'refreshTokenResolvers');
    $resolvers = $resolversProp->getValue();
    $resolvers['github'] = fn () => $refreshed;
    $resolversProp->setValue(null, $resolvers);

    $updated = (new UpdateConnectedAccount())->updateRefreshToken($account);
    expect($updated->token)->toBe('new-tok');

    config()->set('socialstream.features', $originalFeatures);
});

it('covers localization-mymemory service provider and translation service', function () {
    (new MyMemoryServiceProvider(app()))->boot();

    $service = app(TranslationService::class);
    expect($service->name())->toBe('mymemory')
        ->and($service->getSupportedLanguages())->toHaveKey('en')
        ->and($service->supportedLanguages())->toHaveKey('es')
        ->and($service->isLanguageSupported('fr'))->toBeTrue()
        ->and($service->isLanguageSupported('zz'))->toBeFalse();

    // Same language short-circuit
    expect($service->translate('Hello', 'en', 'en'))->toBe('Hello');

    // Successful HTTP call + cache hit
    Http::fake([
        'https://api.mymemory.translated.net/*' => Http::sequence()
            ->push(['responseData' => ['translatedText' => 'Hola']], 200)
            ->push(['responseData' => ['translatedText' => '']], 200)
            ->whenEmpty(fn () => throw new RuntimeException('network down')),
    ]);

    Cache::flush();
    expect($service->translate('Hello', 'es', 'en'))->toBe('Hola');
    // Cached response
    expect($service->translate('Hello', 'es', 'en'))->toBe('Hola');
    // Empty translation fallback
    expect($service->translate('World', 'es', 'en'))->toBe('World');
    // Exception fallback
    expect($service->translate('Boom', 'es', 'en'))->toBe('Boom');

    // translateBatch with nested array, string, and non-string
    $batch = $service->translateBatch(['a' => 'Hello', 'nested' => ['b' => 'Hello'], 'num' => 42], 'en', 'en');
    expect($batch)->toBe(['a' => 'Hello', 'nested' => ['b' => 'Hello'], 'num' => 42]);
});

it('covers module-manager-filament ModuleManagement page table, actions, and change handler', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Gate::define(ModuleManagement::ABILITY, fn () => true);

    // Mount a temporary feature module in Composer's InstalledVersions so ModuleManagement::modules()
    // returns a real changeable feature module (covering rows() loop + successful enable/disable).
    $tmpRoot = sys_get_temp_dir().'/mm-fil-'.bin2hex(random_bytes(5));
    $modDir = $tmpRoot.'/demo-feature';
    File::ensureDirectoryExists($modDir);
    File::put($modDir.'/module.json', json_encode([
        'name' => 'demo-feature',
        'display_name' => 'Demo Feature',
        'description' => 'Temp feature module',
        'version' => '1.0.0',
        'category' => 'foundation',
        'module_type' => 'feature',
        'default_enabled' => false,
        'provider' => ModuleManagerServiceProvider::class,
        'requires' => ['php' => '^8.5', 'modules' => []],
        'capabilities' => ['demo.feature'],
        'features' => ['demo.feature'],
    ], JSON_THROW_ON_ERROR));
    File::put($modDir.'/composer.json', json_encode([
        'name' => 'liberusoftware/demo-feature',
        'type' => 'liberu-module',
        'version' => '1.0.0',
        'extra' => ['liberu' => ['name' => 'demo-feature']],
    ], JSON_THROW_ON_ERROR));

    $installed = require base_path('vendor/composer/installed.php');
    $originalInstalled = $installed;
    $installed['versions']['liberusoftware/demo-feature'] = [
        'pretty_version' => '1.0.0',
        'version' => '1.0.0.0',
        'reference' => 'deadbeef',
        'type' => 'liberu-module',
        'install_path' => $modDir,
        'aliases' => [],
        'dev_requirement' => false,
    ];

    $prevService = app(ModuleManagementService::class);

    try {
        InstalledVersions::reload($installed);
        $manifest = Manifest::fromFile($modDir.'/module.json');
        app()->instance(ModuleManagementService::class, new ModuleManagementService(
            new ModuleInventory(['demo-feature' => $manifest], []),
            app(ModuleActivations::class),
            new ModuleValidator(),
            app(PackageManifest::class),
            [],
            [],
        ));
        $page = new ModuleManagement();
        $page->bootedInteractsWithTable();
        expect($page->content(Schema::make())->getComponents())->toHaveCount(1);

        $table = $page->getTable();
        $records = $table->getRecords();
        expect($records)->toBeInstanceOf(Collection::class)
            ->and($records->has('demo-feature'))->toBeTrue();

        foreach ($table->getColumns() as $column) {
            if ($column->getName() === 'name') {
                $column->record(['name' => 'Demo', 'description' => 'Active module']);
                expect($column->getDescriptionBelow())->toBe('Active module');
            }
            if ($column->getName() === 'status') {
                foreach (['enabled', 'failed', 'disabled'] as $state) {
                    expect($column->formatState($state))->toBeString();
                    expect($column->getColor($state))->toBeString();
                    expect($column->getIcon($state))->toBeString();
                }
            }
        }

        foreach ($table->getRecordActions() as $action) {
            if ($action->getName() === 'enable') {
                $action->record(['module' => 'demo-feature', 'name' => 'Demo Feature', 'status' => 'disabled', 'changeable' => false]);
                expect($action->isVisible())->toBeFalse();
                $action->record(['module' => 'demo-feature', 'name' => 'Demo Feature', 'status' => 'disabled', 'changeable' => true]);
                expect($action->isVisible())->toBeTrue();
                $action->call();
            }
            if ($action->getName() === 'disable') {
                $action->record(['module' => 'demo-feature', 'name' => 'Demo Feature', 'status' => 'enabled', 'changeable' => true]);
                expect($action->isVisible())->toBeTrue();
                expect($action->getModalHeading())->toContain('Demo Feature');
                $action->call();
                // Trigger ModuleActivationRefused branch on unknown module
                $action->record(['module' => 'nonexistent-mod', 'name' => 'Locked', 'status' => 'enabled', 'changeable' => true]);
                $action->call();
            }
        }
    } finally {
        InstalledVersions::reload($originalInstalled);
        app()->instance(ModuleManagementService::class, $prevService);
        File::deleteDirectory($tmpRoot);
    }
});
