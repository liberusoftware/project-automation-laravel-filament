<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\CreateReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\TransitionReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Api\ReconciliationCaseController;
use Liberu\Platform\BusinessWorkflowReconciliation\Capability;
use Liberu\Platform\BusinessWorkflowReconciliation\Enums\LifecycleStatus;
use Liberu\Platform\BusinessWorkflowReconciliation\Events\ReconciliationCaseTransitioned;
use Liberu\Platform\BusinessWorkflowReconciliation\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\BusinessWorkflowReconciliationFilamentPlugin;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource\Pages\EditReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource\Pages\ListReconciliationCases;
use Liberu\Platform\BusinessWorkflowReconciliation\Livewire\ReconciliationCaseList;
use Liberu\Platform\BusinessWorkflowReconciliation\Livewire\Status;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;
use Liberu\Platform\ExecutiveInsights\Actions\CreateInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Actions\TransitionInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Api\InsightSnapshotController;
use Liberu\Platform\ExecutiveInsights\Events\InsightSnapshotTransitioned;
use Liberu\Platform\ExecutiveInsights\Filament\ExecutiveInsightsFilamentPlugin;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource\Pages\EditInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource\Pages\ListInsightSnapshots;
use Liberu\Platform\ExecutiveInsights\Livewire\InsightSnapshotList;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;
use Liberu\Platform\PlatformOrchestration\Actions\CreatePlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Actions\TransitionPlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Api\PlatformWorkflowController;
use Liberu\Platform\PlatformOrchestration\Events\PlatformWorkflowTransitioned;
use Liberu\Platform\PlatformOrchestration\Filament\PlatformOrchestrationFilamentPlugin;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource\Pages\EditPlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource\Pages\ListPlatformWorkflows;
use Liberu\Platform\PlatformOrchestration\Livewire\PlatformWorkflowList;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;
use Liberu\Platform\RevenueAndCareOrchestration\Actions\CreateCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Actions\TransitionCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Api\CarePlanController;
use Liberu\Platform\RevenueAndCareOrchestration\Events\CarePlanTransitioned;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource\Pages\EditCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource\Pages\ListCarePlans;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\RevenueAndCareOrchestrationFilamentPlugin;
use Liberu\Platform\RevenueAndCareOrchestration\Livewire\CarePlanList;
use Liberu\Platform\RevenueAndCareOrchestration\Models\CarePlan;
use Livewire\Component;

dataset('platform_domains', [
    'business-workflow-reconciliation' => [[
        'capability' => Capability::class,
        'enum' => LifecycleStatus::class,
        'exception' => InvalidLifecycleTransition::class,
        'event' => ReconciliationCaseTransitioned::class,
        'model' => ReconciliationCase::class,
        'create' => CreateReconciliationCase::class,
        'transition' => TransitionReconciliationCase::class,
        'controller' => ReconciliationCaseController::class,
        'resource' => ReconciliationCaseResource::class,
        'page_list' => ListReconciliationCases::class,
        'page_create' => ReconciliationCaseResource\Pages\CreateReconciliationCase::class,
        'page_edit' => EditReconciliationCase::class,
        'filament_plugin' => BusinessWorkflowReconciliationFilamentPlugin::class,
        'livewire' => ReconciliationCaseList::class,
        'livewire_status' => Status::class,
    ]],
    'executive-insights' => [[
        'capability' => Liberu\Platform\ExecutiveInsights\Capability::class,
        'enum' => Liberu\Platform\ExecutiveInsights\Enums\LifecycleStatus::class,
        'exception' => Liberu\Platform\ExecutiveInsights\Exceptions\InvalidLifecycleTransition::class,
        'event' => InsightSnapshotTransitioned::class,
        'model' => InsightSnapshot::class,
        'create' => CreateInsightSnapshot::class,
        'transition' => TransitionInsightSnapshot::class,
        'controller' => InsightSnapshotController::class,
        'resource' => InsightSnapshotResource::class,
        'page_list' => ListInsightSnapshots::class,
        'page_create' => InsightSnapshotResource\Pages\CreateInsightSnapshot::class,
        'page_edit' => EditInsightSnapshot::class,
        'filament_plugin' => ExecutiveInsightsFilamentPlugin::class,
        'livewire' => InsightSnapshotList::class,
        'livewire_status' => Liberu\Platform\ExecutiveInsights\Livewire\Status::class,
    ]],
    'platform-orchestration' => [[
        'capability' => Liberu\Platform\PlatformOrchestration\Capability::class,
        'enum' => Liberu\Platform\PlatformOrchestration\Enums\LifecycleStatus::class,
        'exception' => Liberu\Platform\PlatformOrchestration\Exceptions\InvalidLifecycleTransition::class,
        'event' => PlatformWorkflowTransitioned::class,
        'model' => PlatformWorkflow::class,
        'create' => CreatePlatformWorkflow::class,
        'transition' => TransitionPlatformWorkflow::class,
        'controller' => PlatformWorkflowController::class,
        'resource' => PlatformWorkflowResource::class,
        'page_list' => ListPlatformWorkflows::class,
        'page_create' => PlatformWorkflowResource\Pages\CreatePlatformWorkflow::class,
        'page_edit' => EditPlatformWorkflow::class,
        'filament_plugin' => PlatformOrchestrationFilamentPlugin::class,
        'livewire' => PlatformWorkflowList::class,
        'livewire_status' => Liberu\Platform\PlatformOrchestration\Livewire\Status::class,
    ]],
    'revenue-and-care-orchestration' => [[
        'capability' => Liberu\Platform\RevenueAndCareOrchestration\Capability::class,
        'enum' => Liberu\Platform\RevenueAndCareOrchestration\Enums\LifecycleStatus::class,
        'exception' => Liberu\Platform\RevenueAndCareOrchestration\Exceptions\InvalidLifecycleTransition::class,
        'event' => CarePlanTransitioned::class,
        'model' => CarePlan::class,
        'create' => CreateCarePlan::class,
        'transition' => TransitionCarePlan::class,
        'controller' => CarePlanController::class,
        'resource' => CarePlanResource::class,
        'page_list' => ListCarePlans::class,
        'page_create' => CarePlanResource\Pages\CreateCarePlan::class,
        'page_edit' => EditCarePlan::class,
        'filament_plugin' => RevenueAndCareOrchestrationFilamentPlugin::class,
        'livewire' => CarePlanList::class,
        'livewire_status' => Liberu\Platform\RevenueAndCareOrchestration\Livewire\Status::class,
    ]],
]);

it('covers core, API, Filament, and Livewire layers for platform domain', function (array $domain) {
    Event::fake();

    // Core: Capability, Enum, Actions, Event, Exception, Model
    $capabilityClass = $domain['capability'];
    $capability = new $capabilityClass('demo', 'Demo', ['read', 'write']);
    expect($capability->supports('read'))->toBeTrue()
        ->and($capability->supports('delete'))->toBeFalse();

    $enumClass = $domain['enum'];
    expect($enumClass::Draft->allowedTransitions())->toHaveCount(2)
        ->and($enumClass::Active->allowedTransitions())->toHaveCount(2)
        ->and($enumClass::Completed->allowedTransitions())->toBe([])
        ->and($enumClass::Archived->allowedTransitions())->toBe([]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $createAction = app($domain['create']);
    $transitionAction = app($domain['transition']);

    $record = $createAction->execute([
        'tenant_id' => (string) $team->getKey(),
        'idempotency_key' => 'idem-core-1',
        'name' => 'Initial Record',
        'status' => 'draft',
        'metadata' => ['source' => 'test'],
    ]);

    expect($record->metadata)->toBe(['source' => 'test']);

    $active = $transitionAction->execute($record, $enumClass::Active);
    expect($active->status)->toBe('active');
    Event::assertDispatched($domain['event']);

    $completed = $transitionAction->execute($active, $enumClass::Completed);
    expect($completed->status)->toBe('completed');

    expect(fn () => $transitionAction->execute($completed, $enumClass::Draft))
        ->toThrow($domain['exception']);

    // API Controller: index, store (new + idempotent replay), show, update (with transition), destroy, tenant isolation
    $controller = app($domain['controller']);

    $makeRequest = function (string $method, array $data = [], array $headers = []) use ($user): Request {
        $req = Request::create('/api/test', $method, $data);
        foreach ($headers as $k => $v) {
            $req->headers->set($k, $v);
        }
        $req->setUserResolver(fn () => $user);

        return $req;
    };

    $indexRes = $controller->index($makeRequest('GET', ['page' => ['size' => 10]]));
    expect($indexRes->getStatusCode())->toBe(200);

    $storeReq = $makeRequest('POST', ['name' => 'API Record', 'status' => 'draft', 'metadata' => ['k' => 'v']], ['Idempotency-Key' => 'api-key-1']);
    $storeRes = $controller->store($storeReq, $createAction);
    expect($storeRes->getStatusCode())->toBe(201);

    $replayRes = $controller->store($storeReq, $createAction);
    expect($replayRes->getStatusCode())->toBe(200);

    $apiRecord = $domain['model']::query()->where('idempotency_key', 'api-key-1')->firstOrFail();

    $showRes = $controller->show($makeRequest('GET'), $apiRecord);
    expect($showRes->getStatusCode())->toBe(200);

    $updateRes = $controller->update($makeRequest('PATCH', ['name' => 'API Updated', 'status' => 'active']), $apiRecord, $transitionAction);
    expect($updateRes->getStatusCode())->toBe(200);

    $destroyRes = $controller->destroy($makeRequest('DELETE'), $apiRecord);
    expect($destroyRes->getStatusCode())->toBe(204);

    // Filament Resource & Pages
    $this->actingAs($user);
    $resourceClass = $domain['resource'];
    Filament::setTenant($team);

    expect($resourceClass::getEloquentQuery()->count())->toBeGreaterThanOrEqual(1);
    expect($resourceClass::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $livewireStub = Mockery::mock(Component::class, HasTable::class);
    $schema = Schema::make();
    expect($resourceClass::form($schema)->getComponents())->not->toBeEmpty();

    $table = Table::make($livewireStub);
    expect($resourceClass::table($table)->getColumns())->not->toBeEmpty();

    $listPage = new ($domain['page_list'])();
    $listActions = (new ReflectionMethod($listPage, 'getHeaderActions'))->invoke($listPage);
    expect($listActions)->toHaveCount(1);

    $editPage = new ($domain['page_edit'])();
    $editActions = (new ReflectionMethod($editPage, 'getHeaderActions'))->invoke($editPage);
    expect($editActions)->toHaveCount(1);

    $createPage = new ($domain['page_create'])();
    $mutated = (new ReflectionMethod($createPage, 'mutateFormDataBeforeCreate'))->invoke($createPage, ['name' => 'Test']);
    expect($mutated['tenant_id'])->toBe((string) $team->getKey());

    // Livewire Component: unauthenticated branch, authenticated branch, valid & invalid status filter
    $lw = new ($domain['livewire'])();
    $lw->status = 'invalid';
    $lw->updatedStatus('invalid');
    expect($lw->status)->toBe('');
    $lw->status = 'active';
    $lw->updatedStatus('active');
    expect($lw->status)->toBe('active');

    auth()->logout();
    $guestView = $lw->render();
    expect($guestView->getData()['records'])->toHaveCount(0);

    $this->actingAs($user);
    $lw->status = 'completed';
    $authView = $lw->render();
    expect($authView->getData()['records'])->toHaveCount(1);

    $pluginClass = $domain['filament_plugin'];
    $plugin = $pluginClass::make();
    $panel = Panel::make()->id('platform-test');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->not->toBeEmpty();

    $statusClass = $domain['livewire_status'];
    expect((new $statusClass())->render())->toContain('Livewire adapter is available');
})->with('platform_domains');
