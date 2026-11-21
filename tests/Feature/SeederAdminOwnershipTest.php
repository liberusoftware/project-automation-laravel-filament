<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Liberu\Foundation\Organizations\Models\Team;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('makes admin@example.com the owner of the Default team', function () {
    seed(TeamSeeder::class);
    seed(RolesSeeder::class);
    seed(UserSeeder::class);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $team = Team::where('name', 'Default')->firstOrFail();

    expect($team->user_id)->toBe($admin->id);
    expect($admin->current_team_id)->toBe($team->id);
});

it('leaves no throwaway owner@example.com placeholder user', function () {
    seed(TeamSeeder::class);
    seed(RolesSeeder::class);
    seed(UserSeeder::class);

    expect(User::where('email', 'owner@example.com')->exists())->toBeFalse();
});

it('keeps seeded admin credentials usable across reseeds', function () {
    $email = 'admin@example.com';
    config(['app.seed_admin_password' => 'initial-seed-password']);
    seed(TeamSeeder::class);
    seed(RolesSeeder::class);
    seed(UserSeeder::class);

    $admin = User::where('email', $email)->firstOrFail();
    expect(Auth::validate(['email' => $email, 'password' => 'initial-seed-password']))->toBeTrue();

    config(['app.seed_admin_password' => null]);
    seed(UserSeeder::class);
    expect($admin->fresh()->password)->toBe($admin->password);

    config(['app.seed_admin_password' => 'replacement-seed-password']);
    seed(UserSeeder::class);
    expect(Auth::validate(['email' => $email, 'password' => 'replacement-seed-password']))->toBeTrue()
        ->and(User::where('email', $email)->count())->toBe(1);
});
