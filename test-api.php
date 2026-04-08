<?php

/**
 * Live API test script for ABC toolkit.
 * Tests all Kanboard API calls used by the 4 ABC tools.
 *
 * Usage: php test-api.php
 */

declare(strict_types=1);

require __DIR__ . '/../../Core/coqui/vendor/autoload.php';

// Load workspace autoloader for the kanboard toolkit
$workspaceAutoloader = getenv('HOME') . '/.coqui/.workspace/vendor/autoload.php';
if (file_exists($workspaceAutoloader)) {
    require $workspaceAutoloader;
}

use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

$url = 'https://projects.madeinnewburgh.com/jsonrpc.php';
$username = 'coqui';
$token = '6e4abadff1b865b8b156038ef9cd6a26f9021e6a21aebaa45c9fde0275c9';

$client = new KanboardClient($url, $username, $token);

$testProjectId = null;
$testTaskIds = [];
$testSubtaskIds = [];
$testSwimlaneIds = [];
$testCategoryIds = [];
$errors = [];
$passed = 0;

function test(string $name, callable $fn): void
{
    global $errors, $passed;
    echo "  Testing: {$name}... ";
    try {
        $result = $fn();
        echo "✓ " . (is_string($result) ? $result : '') . "\n";
        $passed++;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        echo "✗ {$msg}\n";
        $errors[] = "{$name}: {$msg}";
    }
}

echo "\n=== ABC Toolkit Live API Tests ===\n\n";

// Resolve authenticated user ID for comment tests
$me = $client->call('getMe', []);
$authUserId = (int) ($me['id'] ?? 0);
echo "Authenticated as: {$me['username']} (id={$authUserId})\n\n";

// ─────────────────────────────────────
echo "── DecomposeTool API calls ──\n";
// ─────────────────────────────────────

// 1. createProject
test('createProject', function () use ($client, &$testProjectId): string {
    $result = $client->call('createProject', [
        'name' => 'ABC Test ' . date('Y-m-d H:i:s'),
        'description' => "# Goal\n\nTest project for ABC toolkit API validation",
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int project_id, got: ' . json_encode($result));
    }
    $testProjectId = $result;
    return "project_id={$testProjectId}";
});

// 2. addSwimlane
test('addSwimlane', function () use ($client, &$testProjectId, &$testSwimlaneIds): string {
    $result = $client->call('addSwimlane', [
        'project_id' => $testProjectId,
        'name' => 'Quick Wins',
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int swimlane_id, got: ' . json_encode($result));
    }
    $testSwimlaneIds[] = $result;
    return "swimlane_id={$result}";
});

// 3. addSwimlane batch
test('addSwimlane (batch)', function () use ($client, &$testProjectId, &$testSwimlaneIds): string {
    $results = $client->batch([
        ['method' => 'addSwimlane', 'params' => ['project_id' => $testProjectId, 'name' => 'Deep Work']],
        ['method' => 'addSwimlane', 'params' => ['project_id' => $testProjectId, 'name' => 'Delegated']],
    ]);
    $ids = [];
    foreach ($results as $r) {
        if (!$r['success']) {
            throw new \RuntimeException('Batch addSwimlane failed: ' . ($r['error'] ?? 'unknown'));
        }
        $ids[] = $r['result'];
        $testSwimlaneIds[] = $r['result'];
    }
    return 'ids=' . implode(',', $ids);
});

// 4. createCategory
test('createCategory', function () use ($client, &$testProjectId, &$testCategoryIds): string {
    $result = $client->call('createCategory', [
        'project_id' => $testProjectId,
        'name' => 'Infrastructure',
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int category_id, got: ' . json_encode($result));
    }
    $testCategoryIds[] = $result;
    return "category_id={$result}";
});

// 5. createCategory batch
test('createCategory (batch)', function () use ($client, &$testProjectId, &$testCategoryIds): string {
    $results = $client->batch([
        ['method' => 'createCategory', 'params' => ['project_id' => $testProjectId, 'name' => 'Marketing']],
        ['method' => 'createCategory', 'params' => ['project_id' => $testProjectId, 'name' => 'Engineering']],
    ]);
    $ids = [];
    foreach ($results as $r) {
        if (!$r['success']) {
            throw new \RuntimeException('Batch createCategory failed: ' . ($r['error'] ?? 'unknown'));
        }
        $ids[] = $r['result'];
        $testCategoryIds[] = $r['result'];
    }
    return 'ids=' . implode(',', $ids);
});

// 6. createTask (single)
test('createTask', function () use ($client, &$testProjectId, &$testTaskIds, &$testSwimlaneIds, &$testCategoryIds): string {
    $result = $client->call('createTask', [
        'title' => 'Set up CI/CD pipeline',
        'project_id' => $testProjectId,
        'description' => 'Configure GitHub Actions for automated testing',
        'priority' => 2,
        'color_id' => 'red',
        'swimlane_id' => $testSwimlaneIds[0] ?? 0,
        'category_id' => $testCategoryIds[0] ?? 0,
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int task_id, got: ' . json_encode($result));
    }
    $testTaskIds[] = $result;
    return "task_id={$result}";
});

// 7. createTask (batch)
test('createTask (batch)', function () use ($client, &$testProjectId, &$testTaskIds): string {
    $results = $client->batch([
        ['method' => 'createTask', 'params' => ['title' => 'Write landing page copy', 'project_id' => $testProjectId, 'priority' => 1]],
        ['method' => 'createTask', 'params' => ['title' => 'Deploy to staging', 'project_id' => $testProjectId, 'priority' => 3, 'color_id' => 'orange']],
        ['method' => 'createTask', 'params' => ['title' => 'User testing round 1', 'project_id' => $testProjectId, 'priority' => 0]],
    ]);
    $ids = [];
    foreach ($results as $r) {
        if (!$r['success']) {
            throw new \RuntimeException('Batch createTask failed: ' . ($r['error'] ?? 'unknown'));
        }
        $ids[] = $r['result'];
        $testTaskIds[] = $r['result'];
    }
    return 'ids=' . implode(',', $ids);
});

// 8. createSubtask
test('createSubtask', function () use ($client, &$testTaskIds, &$testSubtaskIds): string {
    $taskId = $testTaskIds[0];
    $result = $client->call('createSubtask', [
        'task_id' => $taskId,
        'title' => 'Write Dockerfile',
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int subtask_id, got: ' . json_encode($result));
    }
    $testSubtaskIds[] = $result;
    return "subtask_id={$result}";
});

// 9. createSubtask (batch)
test('createSubtask (batch)', function () use ($client, &$testTaskIds, &$testSubtaskIds): string {
    $taskId = $testTaskIds[0];
    $results = $client->batch([
        ['method' => 'createSubtask', 'params' => ['task_id' => $taskId, 'title' => 'Configure GitHub Actions']],
        ['method' => 'createSubtask', 'params' => ['task_id' => $taskId, 'title' => 'Add test workflow']],
        ['method' => 'createSubtask', 'params' => ['task_id' => $taskId, 'title' => 'Set up deployment keys']],
    ]);
    $ids = [];
    foreach ($results as $r) {
        if (!$r['success']) {
            throw new \RuntimeException('Batch createSubtask failed: ' . ($r['error'] ?? 'unknown'));
        }
        $ids[] = $r['result'];
        $testSubtaskIds[] = $r['result'];
    }
    return 'ids=' . implode(',', $ids);
});

// ─────────────────────────────────────
echo "\n── StatusTool API calls ──\n";
// ─────────────────────────────────────

// 10. getBoard
test('getBoard', function () use ($client, &$testProjectId): string {
    $result = $client->call('getBoard', ['project_id' => $testProjectId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    return count($result) . ' swimlane(s)';
});

// 11. getAllTasks (active)
test('getAllTasks (status=1)', function () use ($client, &$testProjectId): string {
    $result = $client->call('getAllTasks', ['project_id' => $testProjectId, 'status_id' => 1]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    return count($result) . ' active task(s)';
});

// 12. getAllTasks (closed)
test('getAllTasks (status=0)', function () use ($client, &$testProjectId): string {
    $result = $client->call('getAllTasks', ['project_id' => $testProjectId, 'status_id' => 0]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    return count($result) . ' closed task(s)';
});

// 13. getOverdueTasksByProject
test('getOverdueTasksByProject', function () use ($client, &$testProjectId): string {
    $result = $client->call('getOverdueTasksByProject', ['project_id' => $testProjectId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    return count($result) . ' overdue task(s)';
});

// 14. getProjectById (fixed from getProject)
test('getProjectById', function () use ($client, &$testProjectId): string {
    $result = $client->call('getProjectById', ['project_id' => $testProjectId]);
    if (!is_array($result) || !isset($result['name'])) {
        throw new \RuntimeException('Expected project array with name, got: ' . json_encode($result));
    }
    return '"' . $result['name'] . '"';
});

// 15. StatusTool full batch
test('StatusTool batch (getBoard + getAllTasks + getOverdueTasksByProject + getProjectById)', function () use ($client, &$testProjectId): string {
    $results = $client->batch([
        ['method' => 'getBoard', 'params' => ['project_id' => $testProjectId]],
        ['method' => 'getAllTasks', 'params' => ['project_id' => $testProjectId, 'status_id' => 1]],
        ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $testProjectId]],
        ['method' => 'getProjectById', 'params' => ['project_id' => $testProjectId]],
    ]);
    $allOk = true;
    foreach ($results as $i => $r) {
        if (!$r['success']) {
            throw new \RuntimeException("Batch item {$i} failed: " . ($r['error'] ?? 'unknown'));
        }
    }
    return '4/4 calls succeeded';
});

// ─────────────────────────────────────
echo "\n── NextActionTool API calls ──\n";
// ─────────────────────────────────────

// 16. getColumns
test('getColumns', function () use ($client, &$testProjectId): string {
    $result = $client->call('getColumns', ['project_id' => $testProjectId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    $names = array_map(fn($c) => $c['title'] ?? '?', $result);
    return count($result) . ' columns: ' . implode(', ', $names);
});

// 17. getAllSubtasks
test('getAllSubtasks', function () use ($client, &$testTaskIds): string {
    $taskId = $testTaskIds[0];
    $result = $client->call('getAllSubtasks', ['task_id' => $taskId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    return count($result) . ' subtask(s) for task ' . $taskId;
});

// 18. getAllSubtasks (batch — as NextAction does)
test('getAllSubtasks batch (all tasks)', function () use ($client, &$testTaskIds): string {
    $requests = [];
    foreach ($testTaskIds as $tid) {
        $requests[] = ['method' => 'getAllSubtasks', 'params' => ['task_id' => $tid]];
    }
    $results = $client->batch($requests);
    $counts = [];
    foreach ($results as $i => $r) {
        if (!$r['success']) {
            throw new \RuntimeException("Batch getAllSubtasks for task {$testTaskIds[$i]} failed: " . ($r['error'] ?? 'unknown'));
        }
        $counts[] = count($r['result']);
    }
    return implode(', ', $counts) . ' subtask(s) per task';
});

// 19. NextActionTool full batch
test('NextActionTool batch (getAllTasks + getOverdueTasksByProject + getColumns)', function () use ($client, &$testProjectId): string {
    $results = $client->batch([
        ['method' => 'getAllTasks', 'params' => ['project_id' => $testProjectId, 'status_id' => 1]],
        ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $testProjectId]],
        ['method' => 'getColumns', 'params' => ['project_id' => $testProjectId]],
    ]);
    foreach ($results as $i => $r) {
        if (!$r['success']) {
            throw new \RuntimeException("Batch item {$i} failed: " . ($r['error'] ?? 'unknown'));
        }
    }
    return '3/3 calls succeeded';
});

// ─────────────────────────────────────
echo "\n── ReviewTool API calls ──\n";
// ─────────────────────────────────────

// 20. ReviewTool full batch
test('ReviewTool batch (getAllTasks×2 + getOverdueTasksByProject + getBoard + getProjectById)', function () use ($client, &$testProjectId): string {
    $results = $client->batch([
        ['method' => 'getAllTasks', 'params' => ['project_id' => $testProjectId, 'status_id' => 1]],
        ['method' => 'getAllTasks', 'params' => ['project_id' => $testProjectId, 'status_id' => 0]],
        ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $testProjectId]],
        ['method' => 'getBoard', 'params' => ['project_id' => $testProjectId]],
        ['method' => 'getProjectById', 'params' => ['project_id' => $testProjectId]],
    ]);
    foreach ($results as $i => $r) {
        if (!$r['success']) {
            throw new \RuntimeException("Batch item {$i} failed: " . ($r['error'] ?? 'unknown'));
        }
    }
    return '5/5 calls succeeded';
});

// 21. createComment (for kill_stalled)
test('createComment (dynamic user_id)', function () use ($client, &$testTaskIds, $authUserId): string {
    $taskId = $testTaskIds[0];
    $result = $client->call('createComment', [
        'task_id' => $taskId,
        'content' => '[ABC Test] This is a test comment from the API validation script.',
        'user_id' => $authUserId,
    ]);
    if (!is_int($result) || $result < 1) {
        throw new \RuntimeException('Expected int comment_id, got: ' . json_encode($result));
    }
    return "comment_id={$result}";
});

// 22. closeTask
test('closeTask', function () use ($client, &$testTaskIds): string {
    $taskId = $testTaskIds[count($testTaskIds) - 1]; // close the last task
    $result = $client->call('closeTask', ['task_id' => $taskId]);
    if ($result !== true) {
        throw new \RuntimeException('Expected true, got: ' . json_encode($result));
    }
    return "closed task {$taskId}";
});

// 23. createComment + closeTask batch (as killStalled does)
test('createComment + closeTask batch', function () use ($client, &$testTaskIds, $authUserId): string {
    $taskId = $testTaskIds[count($testTaskIds) - 2]; // second to last task
    $results = $client->batch([
        ['method' => 'createComment', 'params' => ['task_id' => $taskId, 'content' => '[ABC Test] Batch kill test', 'user_id' => $authUserId]],
        ['method' => 'closeTask', 'params' => ['task_id' => $taskId]],
    ]);
    foreach ($results as $i => $r) {
        if (!$r['success']) {
            throw new \RuntimeException("Batch item {$i} failed: " . ($r['error'] ?? 'unknown'));
        }
        // Check comment result value (createComment returns false on failure, not a JSON-RPC error)
        if ($i === 0 && (!is_int($r['result']) || $r['result'] < 1)) {
            throw new \RuntimeException("createComment returned: " . json_encode($r['result']));
        }
    }
    return "commented + closed task {$taskId}";
});

// ─────────────────────────────────────
echo "\n── addTasks flow (existing project) ──\n";
// ─────────────────────────────────────

// 24. getActiveSwimlanes
test('getActiveSwimlanes', function () use ($client, &$testProjectId): string {
    $result = $client->call('getActiveSwimlanes', ['project_id' => $testProjectId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    $names = array_map(fn($s) => $s['name'] ?? '?', $result);
    return count($result) . ' swimlane(s): ' . implode(', ', $names);
});

// 25. getAllCategories
test('getAllCategories', function () use ($client, &$testProjectId): string {
    $result = $client->call('getAllCategories', ['project_id' => $testProjectId]);
    if (!is_array($result)) {
        throw new \RuntimeException('Expected array, got: ' . gettype($result));
    }
    $names = array_map(fn($c) => $c['name'] ?? '?', $result);
    return count($result) . ' category(ies): ' . implode(', ', $names);
});

// ─────────────────────────────────────
echo "\n── Cleanup ──\n";
// ─────────────────────────────────────

// Remove test project
test('removeProject', function () use ($client, &$testProjectId): string {
    $result = $client->call('removeProject', ['project_id' => $testProjectId]);
    if ($result !== true) {
        throw new \RuntimeException('Expected true, got: ' . json_encode($result));
    }
    return "removed project {$testProjectId}";
});

// ─────────────────────────────────────
echo "\n=== Results ===\n";
$total = $passed + count($errors);
echo "Passed: {$passed}/{$total}\n";
if ($errors !== []) {
    echo "FAILURES:\n";
    foreach ($errors as $err) {
        echo "  ✗ {$err}\n";
    }
    exit(1);
}
echo "All API calls verified ✓\n";
