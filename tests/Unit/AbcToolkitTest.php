<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitAbcCo\AbcToolkit;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\DecomposeTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\NextActionTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\ReviewTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\StatusTool;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

test('toolkit implements ToolkitInterface', function () {
    $client = new KanboardClient();
    $toolkit = new AbcToolkit($client);

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns all four ABC tools', function () {
    $client = new KanboardClient();
    $toolkit = new AbcToolkit($client);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(4);

    $names = array_map(fn($tool) => $tool->name(), $tools);
    expect($names)->toBe([
        'abc_decompose',
        'abc_status',
        'abc_next',
        'abc_review',
    ]);
});

test('each tool implements ToolInterface', function () {
    $client = new KanboardClient();
    $toolkit = new AbcToolkit($client);
    $tools = $toolkit->tools();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $client = new KanboardClient();
    $toolkit = new AbcToolkit($client);

    expect($toolkit->guidelines())
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('ABC-TOOLKIT-GUIDELINES');
});

test('fromEnv creates instance', function () {
    $toolkit = AbcToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(AbcToolkit::class);
});

test('decompose tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    expect($tool->name())->toBe('abc_decompose');
});

test('status tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new StatusTool($client))->build();

    expect($tool->name())->toBe('abc_status');
});

test('next action tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new NextActionTool($client))->build();

    expect($tool->name())->toBe('abc_next');
});

test('review tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new ReviewTool($client))->build();

    expect($tool->name())->toBe('abc_review');
});

test('decompose tool rejects more than 8 tasks', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    $tasks = array_map(fn($i) => ['title' => "Task {$i}"], range(1, 9));

    $result = $tool->execute([
        'action' => 'create',
        'project_name' => 'Test Project',
        'tasks' => json_encode($tasks),
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('max 8');
});

test('decompose tool requires project_name for create', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    $result = $tool->execute([
        'action' => 'create',
        'tasks' => json_encode([['title' => 'Task 1']]),
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('project_name');
});

test('decompose tool requires tasks for create', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    $result = $tool->execute([
        'action' => 'create',
        'project_name' => 'Test Project',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('tasks');
});

test('decompose tool requires project_id for add_tasks', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    $result = $tool->execute([
        'action' => 'add_tasks',
        'tasks' => json_encode([['title' => 'Task 1']]),
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('project_id');
});

test('status tool requires project_id', function () {
    $client = new KanboardClient();
    $tool = (new StatusTool($client))->build();

    $result = $tool->execute([]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('project_id');
});

test('next tool requires project_id', function () {
    $client = new KanboardClient();
    $tool = (new NextActionTool($client))->build();

    $result = $tool->execute([]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('project_id');
});

test('review tool requires project_id', function () {
    $client = new KanboardClient();
    $tool = (new ReviewTool($client))->build();

    $result = $tool->execute(['action' => 'review']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('project_id');
});

test('review tool rejects unknown action', function () {
    $client = new KanboardClient();
    $tool = (new ReviewTool($client))->build();

    $result = $tool->execute(['action' => 'invalid', 'project_id' => 1]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Unknown action');
});

test('decompose tool rejects unknown action', function () {
    $client = new KanboardClient();
    $tool = (new DecomposeTool($client))->build();

    $result = $tool->execute(['action' => 'invalid']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Unknown action');
});
