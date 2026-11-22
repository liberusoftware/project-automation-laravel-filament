<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Liberu\Foundation\Organizations\Models\TeamOnboarding;
use Liberu\Modules\Automation\AutomationCore\Filament\Pages\AccountSetupWizard;

Route::get('/', function () {
    return view('welcome');
});

// Authenticated home — super admins land in the "admin" panel, everyone else in
// the user-facing "app" panel.
Route::get('/dashboard', function () {
    $user = auth()->user();

    if ($user instanceof User && $user->isSuperAdmin()) {
        $panel = Filament::getPanel('admin');
        $tenant = $user->getDefaultTenant($panel);

        return redirect($tenant !== null ? $panel->getUrl($tenant) : '/'.$panel->getPath());
    }

    if ($user instanceof User && $user->currentTeam !== null && ! TeamOnboarding::query()
        ->where('team_id', $user->currentTeam->getKey())
        ->whereNotNull('completed_at')
        ->exists()) {
        return redirect(AccountSetupWizard::getUrl(panel: 'app'));
    }

    return redirect()->route('filament.app.pages.dashboard');
})->middleware(['auth:sanctum', config('jetstream.auth_session')])->name('dashboard');
