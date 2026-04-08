<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitAbcCo\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * ABC Next Action tool — prioritized next actions to take.
 *
 * Scores open tasks by priority, overdue status, and subtask completeness,
 * then returns the top N actions to execute right now.
 */
final readonly class NextActionTool
{
    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'abc_next',
            description: 'Get prioritized next actions for a Kanboard project. Scores tasks by priority, overdue status, and effort. Returns the top actions to execute right now.',
            parameters: [
                new NumberParameter('project_id', 'Kanboard project ID', required: true, integer: true),
                new NumberParameter('limit', 'Max number of actions to return (default: 5)', required: false, integer: true),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required.');
        }

        $limit = $this->requireInt($args, 'limit') ?? 5;
        $limit = max(1, min($limit, 20));

        // Fetch open tasks and overdue tasks
        try {
            $batchResults = $this->client->batch([
                ['method' => 'getAllTasks', 'params' => ['project_id' => $projectId, 'status_id' => 1]],
                ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $projectId]],
                ['method' => 'getColumns', 'params' => ['project_id' => $projectId]],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to fetch project data: ' . $e->getMessage());
        }

        $tasks = ($batchResults[0]['success'] ?? false) ? ($batchResults[0]['result'] ?? []) : [];
        $overdueTasks = ($batchResults[1]['success'] ?? false) ? ($batchResults[1]['result'] ?? []) : [];
        $columns = ($batchResults[2]['success'] ?? false) ? ($batchResults[2]['result'] ?? []) : [];

        if (!is_array($tasks) || $tasks === []) {
            return ToolResult::success(json_encode([
                'project_id' => $projectId,
                'actions' => [],
                'message' => 'No open tasks. Decompose a new goal or close the project.',
            ], JSON_PRETTY_PRINT) ?: '{}');
        }

        if (!is_array($overdueTasks)) {
            $overdueTasks = [];
        }

        $overdueIds = [];
        foreach ($overdueTasks as $ot) {
            if (is_array($ot) && isset($ot['id'])) {
                $overdueIds[(int) $ot['id']] = true;
            }
        }

        // Build column name map
        $columnMap = [];
        if (is_array($columns)) {
            foreach ($columns as $col) {
                if (is_array($col) && isset($col['id'], $col['title'])) {
                    $columnMap[(int) $col['id']] = (string) $col['title'];
                }
            }
        }

        // Fetch subtasks for all tasks (batch)
        $subtaskData = $this->fetchSubtasks($tasks);

        // Score and rank tasks
        $scored = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $taskId = (int) ($task['id'] ?? 0);
            $priority = (int) ($task['priority'] ?? 0);
            $columnId = (int) ($task['column_id'] ?? 0);

            $score = 0.0;

            // Priority weight (0-3 mapped to 0-30)
            $score += $priority * 10;

            // Overdue bonus
            if (isset($overdueIds[$taskId])) {
                $score += 50;
            }

            // High subtask completion = closer to done = higher priority
            $subtasks = $subtaskData[$taskId] ?? [];
            $subtaskTotal = count($subtasks);
            $subtaskDone = 0;
            foreach ($subtasks as $st) {
                if (is_array($st) && ($st['status'] ?? 0) == 2) {
                    $subtaskDone++;
                }
            }

            if ($subtaskTotal > 0) {
                $completionRatio = $subtaskDone / $subtaskTotal;
                // Tasks near completion get a bonus (finish what you started)
                $score += $completionRatio * 20;
            }

            // Smallest effort bonus — fewer remaining subtasks = easier to finish
            $remaining = $subtaskTotal - $subtaskDone;
            if ($remaining > 0 && $remaining <= 2) {
                $score += 15; // Quick win bonus
            }

            $scored[] = [
                'task_id' => $taskId,
                'title' => (string) ($task['title'] ?? ''),
                'column' => $columnMap[$columnId] ?? 'Unknown',
                'priority' => $priority,
                'score' => round($score, 1),
                'is_overdue' => isset($overdueIds[$taskId]),
                'subtask_progress' => $subtaskTotal > 0
                    ? "{$subtaskDone}/{$subtaskTotal}"
                    : 'no subtasks',
                'date_due' => (string) ($task['date_due'] ?? ''),
            ];
        }

        // Sort by score descending
        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $actions = array_slice($scored, 0, $limit);

        return ToolResult::success(json_encode([
            'project_id' => $projectId,
            'total_open' => count($tasks),
            'actions' => $actions,
            'directive' => 'Pick #1 and execute NOW. No deliberation.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Fetch subtasks for all given tasks via batch.
     *
     * @return array<int, array> task_id → subtask list
     */
    private function fetchSubtasks(array $tasks): array
    {
        $requests = [];
        $taskIds = [];

        foreach ($tasks as $task) {
            if (!is_array($task) || !isset($task['id'])) {
                continue;
            }

            $taskId = (int) $task['id'];
            $requests[] = ['method' => 'getAllSubtasks', 'params' => ['task_id' => $taskId]];
            $taskIds[] = $taskId;
        }

        if ($requests === []) {
            return [];
        }

        try {
            $results = $this->client->batch($requests);
        } catch (\Throwable) {
            return [];
        }

        $data = [];
        foreach ($results as $i => $result) {
            $taskId = $taskIds[$i] ?? 0;
            if ($result['success'] && is_array($result['result'])) {
                $data[$taskId] = $result['result'];
            }
        }

        return $data;
    }

    private function requireInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            return null;
        }

        return (int) $args[$key];
    }
}
