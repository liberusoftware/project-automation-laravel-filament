<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Liberu\Foundation\ApplicationCore\Http\Middleware\SecurityHeaders;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Audit\Support\ModelAuditListener;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\EditUser;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Foundation\SessionsDevicesFilament\SessionsDevicesFilamentPlugin;
use Liberu\Foundation\UsersRoles\Database\Factories\UserFactory;
use Liberu\Foundation\UsersRoles\DatabaseServiceProvider as UsersRolesDatabaseServiceProvider;
use Liberu\Foundation\UsersRoles\Policies\UserPolicy;
use Liberu\Foundation\UsersRoles\Services\UserRoleLookup;

it('adds HSTS header on secure HTTPS requests', function () {
    $middleware = new SecurityHeaders();
    $request = Request::create('https://example.test/dashboard', 'GET');

    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

it('records created, updated, and deleted genealogy model audit events', function () {
    $listener = new ModelAuditListener();

    $model = new class() extends Model
    {
        protected $table = 'genealogy_people';

        protected $guarded = [];
    };
    $model->setRawAttributes(['id' => 42, 'name' => 'Ada', 'team_id' => 7], true);
    $model->setAttribute('name', 'Ada Lovelace');

    // Non-matching operation returns early
    $listener->handle('eloquent.retrieved: GenealogyPerson', [$model]);

    // When AuditRecorder is not bound, returns early
    $previous = app()->bound(AuditRecorder::class) ? app(AuditRecorder::class) : null;
    app()->offsetUnset(AuditRecorder::class);
    $listener->handle('eloquent.created: GenealogyPerson', [$model]);

    $recorded = [];
    app()->instance(AuditRecorder::class, new class($recorded) implements AuditRecorder
    {
        /** @var array<int, array<string, mixed>> */
        public array $entries;

        public function __construct(array &$entries)
        {
            $this->entries = &$entries;
        }

        public function record(
            string $action,
            ?string $subjectType = null,
            int|string|null $subjectId = null,
            array $before = [],
            array $after = [],
            ?AuditContext $context = null,
        ): void {
            $this->entries[] = compact('action', 'subjectType', 'subjectId', 'before', 'after', 'context');
        }
    });

    $user = User::factory()->create();
    $this->actingAs($user);

    $listener->handle('eloquent.created: GenealogyPerson', [$model]);
    $listener->handle('eloquent.updated: GenealogyPerson', [$model]);
    $listener->handle('eloquent.deleted: GenealogyPerson', [$model]);

    if ($previous !== null) {
        app()->instance(AuditRecorder::class, $previous);
    }

    expect($recorded)->toHaveCount(3)
        ->and($recorded[0]['action'])->toBe('genealogy_created')
        ->and($recorded[0]['before'])->toBe([])
        ->and($recorded[1]['action'])->toBe('genealogy_updated')
        ->and($recorded[2]['action'])->toBe('genealogy_deleted')
        ->and($recorded[2]['after'])->toBe([]);
});

it('ignores unknown authentication events in EmitAuthenticationEvent', function () {
    $listener = new EmitAuthenticationEvent();
    $listener->handle(new stdClass());

    expect(true)->toBeTrue();
});

it('builds header actions on EditUser and EditTeam pages', function () {
    $editUser = new class() extends EditUser
    {
        public function exposeHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };
    $editTeam = new class() extends EditTeam
    {
        public function exposeHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };

    expect($editUser->exposeHeaderActions())->toHaveCount(1)
        ->and($editTeam->exposeHeaderActions())->toHaveCount(1);
});

it('re-verifies email when MustVerifyEmail user changes email', function () {
    Notification::fake();

    $user = new class() extends User implements MustVerifyEmail
    {
        protected $table = 'users';

        public bool $verificationSent = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->verificationSent = true;
        }
    };

    $persisted = User::factory()->create([
        'name' => 'Before Name',
        'email' => 'before@example.test',
        'email_verified_at' => now(),
    ]);

    $verifiable = $user->newQuery()->findOrFail($persisted->getKey());

    app(UpdateUserProfileInformation::class)->update($verifiable, [
        'name' => 'After Name',
        'email' => 'after@example.test',
    ]);

    expect($verifiable->fresh()->email)->toBe('after@example.test')
        ->and($verifiable->fresh()->email_verified_at)->toBeNull()
        ->and($verifiable->verificationSent)->toBeTrue();
});

it('deletes users and rejects non-user models in Jetstream DeleteUser action', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->first();
    $user->createToken('test-token');

    $deletedTeams = [];
    $action = new DeleteUser(new class($deletedTeams) implements DeletesTeams
    {
        public array $deleted;

        public function __construct(array &$deleted)
        {
            $this->deleted = &$deleted;
        }

        public function delete(mixed $team): void
        {
            $this->deleted[] = $team->getKey();
            $team->delete();
        }
    });

    expect(fn () => $action->delete(new stdClass()))
        ->toThrow(InvalidArgumentException::class);

    $action->delete($user);

    expect($deletedTeams)->toContain($team->getKey())
        ->and(User::query()->find($user->getKey()))->toBeNull();
});

it('evaluates Fortify rate limiters for login, two-factor, and passkeys', function () {
    $request = Request::create('/login', 'POST', [
        'email' => 'User@Example.TEST',
        'credential' => ['id' => 'cred-123'],
    ]);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('login.id', 99);

    $loginLimit = RateLimiter::limiter('login')($request);
    $twoFactorLimit = RateLimiter::limiter('two-factor')($request);
    $passkeysLimit = RateLimiter::limiter('passkeys')($request);

    expect($loginLimit->maxAttempts)->toBe(5)
        ->and($twoFactorLimit->maxAttempts)->toBe(5)
        ->and($passkeysLimit->maxAttempts)->toBe(10);
});

it('rejects duplicate searcher registrations in SearcherRegistry', function () {
    $registry = new SearcherRegistry();
    $registry->register('items', fn () => new LengthAwarePaginator([], 0, 15));

    expect(fn () => $registry->register('items', fn () => new LengthAwarePaginator([], 0, 15)))
        ->toThrow(InvalidArgumentException::class);
});

it('covers AccountSecurity page and SessionsDevicesFilamentPlugin', function () {
    expect(AccountSecurity::canAccess())->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountSecurity::canAccess())->toBeTrue();

    $reader = app(SessionReader::class);
    $page = new AccountSecurity();
    $page->mount($reader);
    $page->revoke('non-existent-session', $reader);

    $plugin = SessionsDevicesFilamentPlugin::make();
    $panel = Panel::make()->id('test-sessions');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($page->sessions)->toBeInstanceOf(Collection::class)
        ->and($plugin->getId())->toBe('liberu-sessions-devices');
});

it('covers users-roles trait, factory, service provider, policy, and lookup edge cases', function () {
    $user = User::factory()->create();

    expect($user->authorizationIdentifier())->toBe($user->getKey())
        ->and($user->authorizationType())->toBe($user->getMorphClass());

    $factory = UserFactory::new()->unverified();
    expect($factory->modelName())->toBe(User::class);
    $built = $factory->make();
    expect($built->email_verified_at)->toBeNull();

    (new UsersRolesDatabaseServiceProvider(app()))->boot();

    $lookup = app(UserRoleLookup::class);
    expect($lookup->hasRoleInAnyTeam(new User(), ['admin']))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($user, []))->toBeFalse();

    $policy = new UserPolicy();
    foreach ([
        'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
        'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
    ] as $ability) {
        expect($policy->{$ability}($user))->toBeBool();
    }
});

it('falls back to secondary exchange rate API URL when primary returns empty rates', function () {
    Cache::flush();
    config()->set('currency.exchange_rates_enabled', true);
    config()->set('currency.exchange_rate_api_url', 'https://primary.rates.test/v6/latest/{base}');
    config()->set('currency.exchange_rate_api_fallback_url', 'https://fallback.rates.test/v6/latest/{base}');

    Http::fake([
        'https://primary.rates.test/*' => Http::response([], 500),
        'https://fallback.rates.test/*' => Http::response([
            'result' => 'success',
            'rates' => ['EUR' => 0.92],
            'time_last_update_unix' => time(),
        ], 200),
    ]);

    $provider = new OpenExchangeRateApiProvider();
    $rate = $provider->rate(
        new Currency('USD', 2, '$', 'US Dollar'),
        new Currency('EUR', 2, '€', 'Euro'),
        new DateTimeImmutable(),
    );

    expect($rate)->not->toBeNull()
        ->and($rate->source)->toBe('fallback.rates.test');
});
