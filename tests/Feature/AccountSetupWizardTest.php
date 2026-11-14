<?php

use App\Filament\App\Pages\AccountSetupWizard;
use App\Models\IntegrationConnection;
use App\Models\TeamOnboarding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Integrations\Support\CredentialVault;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('rejects account setup without a signed in user', function () {
    Livewire::test(AccountSetupWizard::class)->assertNotFound();
});

it('rejects account setup for a team the user does not belong to', function () {
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($otherTeam);

    Livewire::test(AccountSetupWizard::class)->assertNotFound();
});

it('prefills the existing AI provider without exposing its encrypted key', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    IntegrationConnection::query()->create([
        'scope_type' => Team::class,
        'scope_id' => (string) $team->id,
        'provider' => 'anthropic',
        'credentials' => app(CredentialVault::class)->seal(['api_key' => 'private-api-key']),
        'capabilities' => ['text-generation'],
        'status' => 'ready',
    ]);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(AccountSetupWizard::class)
        ->assertSet('data.ai_provider', 'anthropic')
        ->assertSet('data.ai_api_key', null)
        ->assertDontSee('private-api-key');
});

it('saves team setup and encrypts the selected provider key', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(AccountSetupWizard::class)
        ->set('data', [
            'name' => 'Ada Lovelace',
            'team_name' => 'Analytical Engines',
            'ai_provider' => 'openai',
            'ai_api_key' => 'sk-test-key',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Ada Lovelace')
        ->and($team->fresh()->name)->toBe('Analytical Engines')
        ->and(TeamOnboarding::query()->where('team_id', $team->id)->value('completed_at'))->not->toBeNull();

    $connection = IntegrationConnection::query()->where('scope_id', (string) $team->id)->firstOrFail();

    expect($connection->credentials)->not->toContain('sk-test-key')
        ->and(app(CredentialVault::class)->open($connection->credentials))->toBe(['api_key' => 'sk-test-key']);
});
