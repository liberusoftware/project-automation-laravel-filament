<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Liberu\Modules\Automation\AutomationCore\Actions\CancelWorkflowRun;
use Liberu\Modules\Automation\AutomationCore\Actions\PublishWorkflow;
use Liberu\Modules\Automation\AutomationCore\Actions\StartWorkflowRun;
use Liberu\Modules\Automation\AutomationCore\Api\Http\Controllers\AutomationCoreResourceController;
use Liberu\Modules\Automation\AutomationCore\Models\AutomationCoreResource;
use Livewire\Component;

dataset('automation_domains', [
    'ai-gateway' => ['AiGateway'],
    'approvals' => ['Approvals'],
    'automation-core' => ['AutomationCore'],
    'connectors' => ['Connectors'],
    'data-processing' => ['DataProcessing'],
    'evaluation' => ['Evaluation'],
    'image' => ['Image'],
    'prompt-registry' => ['PromptRegistry'],
    'rules' => ['Rules'],
    'video' => ['Video'],
    'voice' => ['Voice'],
]);

it('covers API, Filament, Livewire, and core CRUD layers for every automation domain', function (string $studly) {
    Event::fake();

    $ns = "Liberu\\Modules\\Automation\\{$studly}";
    $modelClass = "{$ns}\\Models\\{$studly}Resource";
    $createClass = "{$ns}\\Actions\\Create{$studly}Resource";
    $transitionClass = "{$ns}\\Actions\\Transition{$studly}Resource";
    $controllerClass = "{$ns}\\Api\\Http\\Controllers\\{$studly}ResourceController";
    $pluginClass = "{$ns}\\Filament\\{$studly}FilamentPlugin";
    $resourceClass = "{$ns}\\Filament\\Resources\\{$studly}Resource";
    $livewireClass = "{$ns}\\Livewire\\ResourceList";

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $makeRequest = function (string $method, array $data = [], array $headers = []) use ($user): Request {
        $req = Request::create('/api/automation/test', $method, $data);
        foreach ($headers as $k => $v) {
            $req->headers->set($k, $v);
        }
        $req->setUserResolver(fn () => $user);

        return $req;
    };

    $controller = app($controllerClass);
    $createAction = app($createClass);
    $transitionAction = app($transitionClass);

    // API store + idempotent replay
    $storeReq = $makeRequest('POST', ['name' => 'Alpha', 'payload' => ['env' => 'test']], ['Idempotency-Key' => "idem-{$studly}-1", 'X-Correlation-ID' => 'corr-1']);
    $storeRes = $controller->store($storeReq, $createAction);
    expect($storeRes->getStatusCode())->toBe(201);
    $id = $storeRes->getData(true)['data']['id'];

    // Idempotent replay via action
    $replay = $createAction->execute((string) $user->currentTeam->getKey(), 'Alpha', ['env' => 'test'], "idem-{$studly}-1");
    expect((string) $replay->getKey())->toBe($id);

    // API index
    $indexRes = $controller->index($makeRequest('GET', ['page' => ['size' => 10, 'number' => 1]]));
    expect($indexRes->getStatusCode())->toBe(200)
        ->and($indexRes->getData(true)['meta']['total'])->toBe(1);

    // API show
    $showRes = $controller->show($makeRequest('GET'), $id);
    expect($showRes->getStatusCode())->toBe(200);

    // API update with If-Match
    $currentVersion = (string) ($modelClass::query()->findOrFail($id)->lock_version);
    $updateRes = $controller->update($makeRequest('PATCH', ['name' => 'Alpha Updated', 'payload' => ['v' => 2]], ['If-Match' => $currentVersion]), $id);
    expect($updateRes->getStatusCode())->toBe(200);

    // API transition (draft -> active)
    $transitionRes = $controller->transition($makeRequest('POST', ['status' => 'active'], ['X-Correlation-ID' => 'corr-2']), $id, $transitionAction);
    expect($transitionRes->getStatusCode())->toBe(200);

    // Guard checks on Create and Transition actions
    expect(fn () => $createAction->execute('', 'Bad'))->toThrow(InvalidArgumentException::class);

    // Invalid transition throws InvalidArgumentException (active -> draft is invalid)
    $record = $modelClass::query()->findOrFail($id);
    expect(fn () => $transitionAction->execute($record, 'wrong-team', 'paused'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $transitionAction->execute($record, (string) $user->currentTeam->getKey(), 'unknown-status'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $transitionAction->execute($record, (string) $user->currentTeam->getKey(), 'draft'))
        ->toThrow(InvalidArgumentException::class);

    // API destroy
    $destroyRes = $controller->destroy($makeRequest('DELETE'), $id);
    expect($destroyRes->getStatusCode())->toBe(204);

    // Filament Plugin + Resource
    $plugin = $pluginClass::make();
    $panel = Panel::make()->id("auto-{$studly}");
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->not->toBeEmpty()
        ->and($plugin->capabilities())->not->toBeEmpty();

    expect($resourceClass::getPages())->toHaveKeys(['index', 'create', 'edit']);
    expect($resourceClass::getEloquentQuery()->count())->toBe(0);

    $schema = Schema::make();
    $components = $resourceClass::form($schema)->getComponents();
    expect($components)->not->toBeEmpty();

    foreach ($components as $component) {
        if ($component instanceof Textarea) {
            $hydratedProp = new ReflectionProperty($component, 'afterStateHydrated');
            $hydratedFn = $hydratedProp->getValue($component);
            if ($hydratedFn instanceof Closure) {
                $inner = (new ReflectionFunction($hydratedFn))->getStaticVariables()['callback'] ?? null;
                if ($inner instanceof Closure) {
                    expect($inner(['a' => 1]))->toContain('"a"');
                }
            }

            $dehydrateProp = new ReflectionProperty($component, 'dehydrateStateUsing');
            $dehydrateFn = $dehydrateProp->getValue($component);
            if ($dehydrateFn instanceof Closure) {
                expect($dehydrateFn('{"a":1}'))->toBe(['a' => 1]);
                expect($dehydrateFn(''))->toBe([]);
            }
        }
    }

    $livewireStub = Mockery::mock(Component::class, HasTable::class);
    $table = Table::make($livewireStub);
    expect($resourceClass::table($table)->getColumns())->not->toBeEmpty();

    $lwProviderClass = "{$ns}\\Livewire\\{$studly}LivewireServiceProvider";
    $lwProvider = new $lwProviderClass(app());
    $lwProvider->boot();
    expect($lwProvider->capabilities())->not->toBeEmpty();

    $apiProviderClass = "{$ns}\\Api\\{$studly}ApiServiceProvider";
    (new $apiProviderClass(app()))->boot();

    // Livewire ResourceList: create, edit, update, transition, render (with search + guest), delete
    $lw = new $livewireClass();
    $lw->name = 'Livewire Item';
    $lw->payload = '{"k":"v"}';
    $lw->save();

    $lwRecord = $modelClass::query()->forTeam((string) $user->currentTeam->getKey())->firstOrFail();
    $lw->edit((string) $lwRecord->getKey());
    expect($lw->editingId)->toBe((string) $lwRecord->getKey());

    $lw->name = 'Livewire Updated';
    $lw->payload = '';
    $lw->save();

    $lw->transitionStatus((string) $lwRecord->getKey(), 'active');

    $lw->search = 'Livewire';
    $authView = $lw->render();
    expect($authView->getData()['resources'])->toHaveCount(1);

    $lw->edit((string) $lwRecord->getKey());
    $lw->delete((string) $lwRecord->getKey());
    expect($lw->editingId)->toBeNull();

    auth()->logout();
    $guestView = $lw->render();
    expect($guestView->getData()['resources'])->toHaveCount(0);
})->with('automation_domains');

it('covers AutomationCore workflow publish, run, and cancelRun endpoints and actions', function () {
    Event::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $teamId = (string) $user->currentTeam->getKey();
    $this->actingAs($user);

    $workflow = AutomationCoreResource::query()->create([
        'team_id' => $teamId,
        'name' => 'Deploy Pipeline',
        'status' => 'draft',
        'payload' => [],
    ]);

    $makeRequest = function (string $method, array $data = [], array $headers = []) use ($user): Request {
        $req = Request::create('/api/automation/core', $method, $data);
        foreach ($headers as $k => $v) {
            $req->headers->set($k, $v);
        }
        $req->setUserResolver(fn () => $user);

        return $req;
    };

    $controller = app(AutomationCoreResourceController::class);

    $publishRes = $controller->publish(
        $makeRequest('POST', [
            'definition' => [
                'name' => 'Deploy Pipeline',
                'version' => 1,
                'trigger' => ['type' => 'webhook', 'source' => 'github'],
                'steps' => [
                    ['id' => 'step-1', 'type' => 'action', 'name' => 'Build'],
                ],
            ],
        ], ['X-Correlation-ID' => 'corr-pub']),
        (string) $workflow->getKey(),
        app(PublishWorkflow::class),
    );
    expect($publishRes->getStatusCode())->toBe(201);

    $runRes = $controller->run(
        $makeRequest('POST', [
            'variables' => ['branch' => 'main'],
            'idempotency_key' => 'run-idem-1',
        ], ['X-Correlation-ID' => 'corr-run']),
        (string) $workflow->getKey(),
        app(StartWorkflowRun::class),
    );
    expect($runRes->getStatusCode())->toBe(202);
    $runId = $runRes->getData(true)['data']['id'];

    // Idempotent replay of StartWorkflowRun
    $workflow->refresh();
    $replayRun = app(StartWorkflowRun::class)->execute($workflow, $teamId, ['branch' => 'main'], 'run-idem-1');
    expect((string) $replayRun->getKey())->toBe($runId);

    $cancelRes = $controller->cancelRun(
        $makeRequest('POST', [], ['X-Correlation-ID' => 'corr-cancel']),
        (string) $workflow->getKey(),
        $runId,
        app(CancelWorkflowRun::class),
    );
    expect($cancelRes->getStatusCode())->toBe(200);
});
