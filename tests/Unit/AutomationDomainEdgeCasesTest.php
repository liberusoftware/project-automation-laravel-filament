<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Modules\Automation\AiGateway\Domain\GatewayRequest;
use Liberu\Modules\Automation\AiGateway\Domain\ModelDefinition;
use Liberu\Modules\Automation\AiGateway\Domain\ProviderContract;
use Liberu\Modules\Automation\AiGateway\Domain\RoutingPolicy;
use Liberu\Modules\Automation\AiGateway\Domain\StructuredOutput;
use Liberu\Modules\Automation\AiGateway\Domain\ToolPolicy;
use Liberu\Modules\Automation\AiGateway\Domain\UsageMeter;
use Liberu\Modules\Automation\Approvals\Domain\ApprovalQueue;
use Liberu\Modules\Automation\Approvals\Domain\ApprovalRequest;
use Liberu\Modules\Automation\Approvals\Domain\Delegation;
use Liberu\Modules\Automation\Approvals\Domain\EscalationPolicy;
use Liberu\Modules\Automation\AutomationCore\Actions\CancelWorkflowRun;
use Liberu\Modules\Automation\AutomationCore\Actions\PublishWorkflow;
use Liberu\Modules\Automation\AutomationCore\Actions\StartWorkflowRun;
use Liberu\Modules\Automation\AutomationCore\Domain\RetryPolicy;
use Liberu\Modules\Automation\AutomationCore\Domain\WorkflowDefinition;
use Liberu\Modules\Automation\AutomationCore\Domain\WorkflowRun;
use Liberu\Modules\Automation\AutomationCore\Domain\WorkflowSchedule;
use Liberu\Modules\Automation\AutomationCore\Domain\WorkflowTrigger;
use Liberu\Modules\Automation\AutomationCore\Domain\WorkflowVariables;
use Liberu\Modules\Automation\AutomationCore\Models\AutomationCoreResource;
use Liberu\Modules\Automation\AutomationCore\Models\WorkflowRunRecord;
use Liberu\Modules\Automation\Connectors\Domain\ConnectorDefinition;
use Liberu\Modules\Automation\Connectors\Domain\Cursor;
use Liberu\Modules\Automation\Connectors\Domain\RateLimit;
use Liberu\Modules\Automation\Connectors\Domain\ReconciliationReport;
use Liberu\Modules\Automation\Connectors\Domain\Webhook;
use Liberu\Modules\Automation\DataProcessing\Domain\ExtractionSchema;
use Liberu\Modules\Automation\DataProcessing\Domain\ProcessingRequest;
use Liberu\Modules\Automation\DataProcessing\Domain\RedactionPolicy;
use Liberu\Modules\Automation\Evaluation\Domain\EvaluationCase;
use Liberu\Modules\Automation\Evaluation\Domain\SafetyCheck;
use Liberu\Modules\Automation\Image\Domain\ImageRequest;
use Liberu\Modules\Automation\Image\Domain\ImageVariant;
use Liberu\Modules\Automation\Image\Domain\Provenance;
use Liberu\Modules\Automation\PromptRegistry\Domain\PromptApproval;
use Liberu\Modules\Automation\PromptRegistry\Domain\PromptEvaluationSet;
use Liberu\Modules\Automation\PromptRegistry\Domain\PromptOverride;
use Liberu\Modules\Automation\PromptRegistry\Domain\PromptRelease;
use Liberu\Modules\Automation\PromptRegistry\Domain\PromptVersion;
use Liberu\Modules\Automation\Rules\Domain\DecisionTable;
use Liberu\Modules\Automation\Rules\Domain\RuleCondition;
use Liberu\Modules\Automation\Rules\Domain\RuleExpression;
use Liberu\Modules\Automation\Video\Domain\CaptionCue;
use Liberu\Modules\Automation\Video\Domain\VideoJob;
use Liberu\Modules\Automation\Video\Domain\VideoProvenance;
use Liberu\Modules\Automation\Video\Domain\VideoRequest;
use Liberu\Modules\Automation\Voice\Domain\ConsentRecord;
use Liberu\Modules\Automation\Voice\Domain\SpeechSynthesisRequest;
use Liberu\Modules\Automation\Voice\Domain\StreamingSession;
use Liberu\Modules\Automation\Voice\Domain\TranscriptSegment;
use Liberu\Modules\Automation\Voice\Domain\VoiceRequest;

uses(RefreshDatabase::class);

it('covers AI Gateway domain validation edge cases', function () {
    expect(fn () => new GatewayRequest('', 'm', ['x' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ModelDefinition('', 'm', 'chat', 1000, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderContract('', ['m']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderContract('openai', ['']))->toThrow(InvalidArgumentException::class);

    $noTools = new ProviderContract('openai', ['gpt-4'], supportsTools: false);
    $reqWithTools = new GatewayRequest('chat', 'gpt-4', ['prompt' => 'hi'], toolPolicy: new ToolPolicy(['search'], true, 1));
    expect(fn () => $noTools->validateRequest($reqWithTools))->toThrow(InvalidArgumentException::class);

    $routing = new RoutingPolicy(['primary', 'fallback'], 2);
    expect(fn () => $routing->providerForAttempt(0))->toThrow(InvalidArgumentException::class);

    expect(fn () => StructuredOutput::fromSchema(['type' => 'string']))->toThrow(InvalidArgumentException::class);
    $output = StructuredOutput::fromSchema(['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]);
    expect(fn () => $output->validate([]))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ToolPolicy([''], true, 1))->toThrow(InvalidArgumentException::class);

    $disabledModel = new ModelDefinition('openai', 'gpt-4', 'chat', 4096, 10, 20, enabled: false);
    expect(fn () => (new UsageMeter())->record(10, 10, $disabledModel))->toThrow(InvalidArgumentException::class);
});

it('covers Approvals domain validation edge cases', function () {
    expect(fn () => new ApprovalQueue('', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ApprovalRequest('', 't', 'u', 'pending'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ApprovalRequest('1', 't', 'u', 'delegated', delegateId: ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Delegation('', CarbonImmutable::now()->addHour()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EscalationPolicy(0, ['u1']))->toThrow(InvalidArgumentException::class);

    $now = CarbonImmutable::now();
    $req = new ApprovalRequest('1', 't', 'req-1', 'pending', expiresAt: $now->addHours(5));
    expect(fn () => $req->delegate('other', new Delegation('del-1', $now->addHour()), $now))
        ->toThrow(InvalidArgumentException::class);

    $policy = new EscalationPolicy(60, ['mgr-1']);
    expect(fn () => $req->escalate('mgr-1', $policy, $now))->toThrow(InvalidArgumentException::class);

    $soon = new ApprovalRequest('2', 't', 'req-1', 'pending', expiresAt: $now->addSeconds(30));
    expect(fn () => $soon->escalate('unauthorized', $policy, $now))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $soon->expire($now))->toThrow(InvalidArgumentException::class);
});

it('covers AutomationCore domain and action validation edge cases', function () {
    $wf = new AutomationCoreResource(['team_id' => 't1', 'status' => 'draft']);
    expect(fn () => app(PublishWorkflow::class)->execute($wf, 't2', WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']]])))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(StartWorkflowRun::class)->execute($wf, 't1'))
        ->toThrow(InvalidArgumentException::class);

    $publishedNoVersion = AutomationCoreResource::query()->create([
        'team_id' => 't1',
        'name' => 'NoVer',
        'status' => 'published',
        'payload' => [],
    ]);
    expect(fn () => app(StartWorkflowRun::class)->execute($publishedNoVersion, 't1'))
        ->toThrow(InvalidArgumentException::class);

    $completedRun = new WorkflowRunRecord(['team_id' => 't1', 'status' => 'succeeded']);
    expect(fn () => app(CancelWorkflowRun::class)->execute($completedRun, 't1'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new RetryPolicy(0, 10))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RetryPolicy::fromArray(['max_attempts' => -1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RetryPolicy::fromArray(['retryable_failures_only' => 'yes']))->toThrow(InvalidArgumentException::class);

    expect(fn () => WorkflowDefinition::fromArray(['name' => '', 'steps' => [['type' => 'a']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => '']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']], 'triggers' => 'bad']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']], 'triggers' => ['bad']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']], 'compensation' => 'bad']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']], 'compensation' => [['type' => '']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowDefinition::fromArray(['name' => 'W', 'steps' => [['type' => 'a']], 'input_schema' => 'bad']))->toThrow(InvalidArgumentException::class);

    $withTriggers = WorkflowDefinition::fromArray([
        'name' => 'W',
        'steps' => [['type' => 'action']],
        'triggers' => [['type' => 'event', 'event' => 'order.created', 'enabled' => true]],
    ]);
    expect($withTriggers->triggers)->toHaveCount(1);

    expect(fn () => new WorkflowRun('', 'wf', 'queued'))->toThrow(InvalidArgumentException::class);
    $run = new WorkflowRun('r1', 'wf', 'succeeded');
    expect($run->attempts())->toBe(0)
        ->and(fn () => $run->startAttempt())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $run->requestCancellation())->toThrow(InvalidArgumentException::class);

    expect(fn () => WorkflowSchedule::fromArray(['expression' => '', 'timezone' => 'UTC']))->toThrow(InvalidArgumentException::class);
    expect(WorkflowSchedule::fromArray(['expression' => '0 * * * *', 'timezone' => 'UTC'])->toArray())->toBe(['expression' => '0 * * * *', 'timezone' => 'UTC', 'enabled' => true]);

    expect(fn () => new WorkflowTrigger('invalid', 'ev'))->toThrow(InvalidArgumentException::class);

    expect(fn () => WorkflowVariables::validate([], ['1bad' => []]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowVariables::validate(['a.b' => 1], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowVariables::validate(['x' => 1], ['x' => 'not-array']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WorkflowVariables::validate(['x' => 'wrong'], ['x' => ['type' => 'integer']]))->toThrow(InvalidArgumentException::class);
});

it('covers Connectors, DataProcessing, Evaluation, and Image domain edge cases', function () {
    expect(fn () => new ConnectorDefinition('', 'https://api.example.com', 'sec'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Cursor(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RateLimit(0, 60))->toThrow(InvalidArgumentException::class);

    $rl = new RateLimit(5, 60);
    expect($rl->remaining())->toBe(5)
        ->and($rl->retryAfter(100))->toBe(0)
        ->and($rl->consume(0, 100))->toBeFalse();

    expect(fn () => new ReconciliationReport(1, 2, 0, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Webhook('', 'p', 100, 'sig'))->toThrow(InvalidArgumentException::class);

    $wh = new Webhook('ev', 'payload', 100, 'sig');
    expect($wh->verify('', 100))->toBeFalse();

    $schema = new ExtractionSchema(['total' => 'number']);
    expect(fn () => $schema->validate([]))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ProcessingRequest('extraction', 'hello', false, ['schema' => 'bad']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RedactionPolicy([], '[REDACTED]'))->toThrow(InvalidArgumentException::class);

    expect(fn () => new EvaluationCase('', ['a' => 1], 'ok'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EvaluationCase('1', ['obj' => new stdClass()], 'ok'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SafetyCheck('', ['/bad/']))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ImageRequest('', 'generate'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ImageVariant('', 1, 'png', 100, 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Provenance('', 'actor', 'bad-hash'))->toThrow(InvalidArgumentException::class);
});

it('covers PromptRegistry, Rules, Video, and Voice domain edge cases', function () {
    $v1 = new PromptVersion('welcome', 1, 'Hello {{name}}', ['name']);
    $v2 = new PromptVersion('welcome', 2, 'Hi {{name}}', ['name']);

    expect(fn () => new PromptEvaluationSet('', [['input' => ['name' => 'a'], 'expected' => 'b']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PromptEvaluationSet('set', [['input' => ['name' => ['nested']], 'expected' => 'b']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PromptOverride('', 't1', null, $v1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PromptRelease('', [$v1]))->toThrow(InvalidArgumentException::class);

    $release = new PromptRelease('welcome', [$v1]);
    $wrongApproval = PromptApproval::approve('other', 1, 'approver', 'author');
    expect(fn () => $release->publish($v1, $wrongApproval))->toThrow(InvalidArgumentException::class);
    $unregisteredApproval = PromptApproval::approve('welcome', 2, 'approver', 'author');
    expect(fn () => $release->publish($v2, $unregisteredApproval))->toThrow(InvalidArgumentException::class);
    expect(fn () => $release->rollbackTo(99))->toThrow(InvalidArgumentException::class);

    expect(fn () => new PromptVersion('', 1, 'hi', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PromptVersion('k', 1, 'hi', ['1invalid']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $v1->render(['name' => ['not-scalar']]))->toThrow(InvalidArgumentException::class);

    expect(fn () => new DecisionTable('', [['conditions' => [], 'outcome' => 'ok']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DecisionTable('dt', [['conditions' => ['not-array'], 'outcome' => 'ok']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DecisionTable('dt', [['conditions' => [['field' => 'a', 'operator' => 'exists']], 'outcome' => '']]))->toThrow(InvalidArgumentException::class);

    expect(fn () => RuleCondition::fromArray(['field' => 'a', 'operator' => 'equals']))->toThrow(InvalidArgumentException::class);
    $notEq = RuleCondition::fromArray(['field' => 'a', 'operator' => 'not_equals', 'value' => 1]);
    expect($notEq->matches(['a' => 2]))->toBeTrue();
    expect(fn () => RuleExpression::fromArray(['operator' => 'not', 'conditions' => []]))->toThrow(InvalidArgumentException::class);

    $vreq = new VideoRequest('Launch demo', true);
    expect(fn () => new CaptionCue(-1, 'hi', 0, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VideoJob('', 't1', $vreq))->toThrow(InvalidArgumentException::class);
    $vjob = new VideoJob('vj1', 't1', $vreq);
    expect(fn () => $vjob->fail())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $vjob->retry(1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $vjob->complete())->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VideoProvenance('', 'actor', 'bad'))->toThrow(InvalidArgumentException::class);

    $now = CarbonImmutable::now();
    expect(fn () => new ConsentRecord('', 'a', 'p', $now))->toThrow(InvalidArgumentException::class);
    $consent = new ConsentRecord('c1', 'a1', 'support', $now);
    expect(fn () => $consent->revoke($now->subMinute()))->toThrow(InvalidArgumentException::class);

    expect(fn () => new SpeechSynthesisRequest('', 'alloy', 'audio/mpeg', 16000))->toThrow(InvalidArgumentException::class);

    $batchVoice = new VoiceRequest('speech_to_text', 'en-US', true);
    expect(fn () => new StreamingSession('s1', 't1', $batchVoice))->toThrow(InvalidArgumentException::class);

    $streamVoice = new VoiceRequest('stream', 'en-US', true);
    $session = new StreamingSession('s1', 't1', $streamVoice);
    expect(fn () => $session->interrupt())->toThrow(InvalidArgumentException::class)
        ->and(fn () => $session->complete())->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TranscriptSegment(-1, 'sp', 'hi', 0, 1))->toThrow(InvalidArgumentException::class);
});
