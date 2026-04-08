<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitAbcCo\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * ABC Review tool — reviews progress and suggests project adjustments.
 *
 * Three actions:
 * - review: Full progress analysis with recommendations
 * - kill_stalled: Close tasks stalled beyond a threshold
 * - rebalance: Analyze column distribution and suggest moves
 */
final readonly class ReviewTool
{
    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'abc_review',
            description: 'Review a Kanboard project and suggest adjustments. Actions: "review" for full analysis, "kill_stalled" to close stalled tasks, "rebalance" for WIP distribution advice.',
            parameters: [
                new EnumParameter('action', 'Review operation', ['review', 'kill_stalled', 'rebalance']),
                new NumberParameter('project_id', 'Kanboard project ID', required: true, integer: true),
                new NumberParameter('days_threshold', 'Days without activity before a task is considered stalled (default: 5, for kill_stalled)', required: false, integer: true),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'review' => $this->review($args),
            'kill_stalled' => $this->killStalled($args),
            'rebalance' => $this->rebalance($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function review(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required.');
        }

        try {
            $batchResults = $this->client->batch([
                ['method' => 'getAllTasks', 'params' => ['project_id' => $projectId, 'status_id' => 1]],
                ['method' => 'getAllTasks', 'params' => ['project_id' => $projectId, 'status_id' => 0]],
                ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $projectId]],
                ['method' => 'getBoard', 'params' => ['project_id' => $projectId]],
                ['method' => 'getProjectById', 'params' => ['project_id' => $projectId]],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to fetch project data: ' . $e->getMessage());
        }

        $openTasks = ($batchResults[0]['success'] ?? false) ? ($batchResults[0]['result'] ?? []) : [];
        $closedTasks = ($batchResults[1]['success'] ?? false) ? ($batchResults[1]['result'] ?? []) : [];
        $overdueTasks = ($batchResults[2]['success'] ?? false) ? ($batchResults[2]['result'] ?? []) : [];
        $board = ($batchResults[3]['success'] ?? false) ? ($batchResults[3]['result'] ?? []) : [];
        $project = ($batchResults[4]['success'] ?? false) ? ($batchResults[4]['result'] ?? []) : [];

        if (!is_array($openTasks)) {
            $openTasks = [];
        }
        if (!is_array($closedTasks)) {
            $closedTasks = [];
        }
        if (!is_array($overdueTasks)) {
            $overdueTasks = [];
        }
        if (!is_array($board)) {
            $board = [];
        }
        if (!is_array($project)) {
            $project = [];
        }

        $totalOpen = count($openTasks);
        $totalClosed = count($closedTasks);
        $totalOverdue = count($overdueTasks);
        $grandTotal = $totalOpen + $totalClosed;
        $completionPct = $grandTotal > 0 ? round(($totalClosed / $grandTotal) * 100, 1) : 0.0;

        // Categorize tasks by staleness
        $now = time();
        $stalled = [];
        $active = [];

        foreach ($openTasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $modified = (int) ($task['date_modification'] ?? 0);
            $daysSinceModified = $modified > 0 ? (int) round(($now - $modified) / 86400) : 999;

            $entry = [
                'id' => (int) ($task['id'] ?? 0),
                'title' => (string) ($task['title'] ?? ''),
                'priority' => (int) ($task['priority'] ?? 0),
                'days_inactive' => $daysSinceModified,
            ];

            if ($daysSinceModified >= 2) {
                $stalled[] = $entry;
            } else {
                $active[] = $entry;
            }
        }

        // Column analysis
        $columnAnalysis = $this->analyzeBoard($board);

        // Generate one-thing-to-do-right-now
        $oneThing = $this->pickOneThing($totalOpen, $totalOverdue, count($stalled), $active);

        $report = [
            'project' => [
                'id' => $projectId,
                'name' => $project['name'] ?? 'Unknown',
            ],
            'summary' => [
                'total' => $grandTotal,
                'open' => $totalOpen,
                'completed' => $totalClosed,
                'completion_pct' => $completionPct,
                'overdue' => $totalOverdue,
                'stalled_2d' => count($stalled),
                'active' => count($active),
            ],
            'column_distribution' => $columnAnalysis,
            'stalled_tasks' => array_slice($stalled, 0, 10),
            'one_thing_right_now' => $oneThing,
        ];

        return ToolResult::success(
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    private function killStalled(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required.');
        }

        $daysThreshold = $this->requireInt($args, 'days_threshold') ?? 5;
        $daysThreshold = max(1, $daysThreshold);

        try {
            $batchResults = $this->client->batch([
                ['method' => 'getAllTasks', 'params' => ['project_id' => $projectId, 'status_id' => 1]],
                ['method' => 'getMe', 'params' => []],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to fetch tasks: ' . $e->getMessage());
        }

        $tasks = ($batchResults[0]['success'] ?? false) ? ($batchResults[0]['result'] ?? []) : [];
        $me = ($batchResults[1]['success'] ?? false) ? ($batchResults[1]['result'] ?? []) : [];
        $userId = (int) ($me['id'] ?? 0);

        if (!is_array($tasks) || $tasks === []) {
            return ToolResult::error('No tasks found.');
        }

        $now = time();
        $threshold = $daysThreshold * 86400;
        $toKill = [];

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $modified = (int) ($task['date_modification'] ?? 0);
            if ($modified > 0 && ($now - $modified) > $threshold) {
                $toKill[] = [
                    'id' => (int) ($task['id'] ?? 0),
                    'title' => (string) ($task['title'] ?? ''),
                    'days_stalled' => (int) round(($now - $modified) / 86400),
                ];
            }
        }

        if ($toKill === []) {
            return ToolResult::success(json_encode([
                'killed' => 0,
                'message' => "No tasks stalled > {$daysThreshold} days. Board is healthy.",
            ], JSON_PRETTY_PRINT) ?: '{}');
        }

        // Close stalled tasks and add comments explaining why (batch)
        $requests = [];
        foreach ($toKill as $task) {
            if ($userId > 0) {
                $requests[] = [
                    'method' => 'createComment',
                    'params' => [
                        'task_id' => $task['id'],
                        'content' => "[ABC Auto-Kill] Task stalled for {$task['days_stalled']} days with no activity. Closed by ABC review. Re-open if still relevant.",
                        'user_id' => $userId,
                    ],
                ];
            }
            $requests[] = [
                'method' => 'closeTask',
                'params' => ['task_id' => $task['id']],
            ];
        }

        try {
            $this->client->batch($requests);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to close stalled tasks: ' . $e->getMessage());
        }

        return ToolResult::success(json_encode([
            'killed' => count($toKill),
            'days_threshold' => $daysThreshold,
            'tasks' => $toKill,
            'message' => count($toKill) . " stalled tasks killed. No mercy for dead weight.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function rebalance(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required.');
        }

        try {
            $board = $this->client->call('getBoard', ['project_id' => $projectId]);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to fetch board: ' . $e->getMessage());
        }

        if (!is_array($board)) {
            return ToolResult::error('Failed to read board state.');
        }

        $columnAnalysis = $this->analyzeBoard($board);

        // Find overloaded and empty columns
        $overloaded = [];
        $starving = [];
        $suggestions = [];

        foreach ($columnAnalysis as $col) {
            if ($col['task_count'] > 5) {
                $overloaded[] = $col;
            } elseif ($col['task_count'] === 0) {
                $starving[] = $col;
            }
        }

        if ($overloaded !== []) {
            foreach ($overloaded as $col) {
                $suggestions[] = "Column \"{$col['name']}\" has {$col['task_count']} tasks — WIP too high. Move lowest-priority tasks back to Backlog or close them.";
            }
        }

        $totalTasks = array_sum(array_column($columnAnalysis, 'task_count'));
        $numColumns = count($columnAnalysis);

        if ($numColumns > 0 && $totalTasks > 0) {
            $ideal = (int) ceil($totalTasks / $numColumns);
            $suggestions[] = "Ideal distribution: ~{$ideal} tasks per column. Move tasks to balance.";
        }

        if ($suggestions === []) {
            $suggestions[] = 'Board is balanced. Keep executing.';
        }

        return ToolResult::success(json_encode([
            'project_id' => $projectId,
            'total_tasks' => $totalTasks,
            'columns' => $columnAnalysis,
            'overloaded_columns' => count($overloaded),
            'empty_columns' => count($starving),
            'suggestions' => $suggestions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @return array<int, array{name: string, task_count: int}>
     */
    private function analyzeBoard(array $board): array
    {
        $columns = [];

        foreach ($board as $swimlane) {
            if (!is_array($swimlane) || !isset($swimlane['columns'])) {
                continue;
            }

            foreach ($swimlane['columns'] as $column) {
                if (!is_array($column)) {
                    continue;
                }

                $colId = (int) ($column['id'] ?? 0);
                $colName = (string) ($column['title'] ?? 'Unknown');
                $taskCount = is_array($column['tasks'] ?? null) ? count($column['tasks']) : 0;

                if (!isset($columns[$colId])) {
                    $columns[$colId] = ['name' => $colName, 'task_count' => 0];
                }
                $columns[$colId]['task_count'] += $taskCount;
            }
        }

        return array_values($columns);
    }

    private function pickOneThing(int $totalOpen, int $overdue, int $stalled, array $activeTasks): string
    {
        if ($overdue > 0) {
            return 'Close or re-date your overdue tasks. Right now. Stop reading and do it.';
        }

        if ($stalled > 2) {
            return "You have {$stalled} stalled tasks. Run abc_review(action: 'kill_stalled') to clean house.";
        }

        if ($totalOpen === 0) {
            return 'All tasks complete or no tasks exist. Decompose the next goal.';
        }

        if ($activeTasks !== []) {
            // Pick highest priority active task
            usort($activeTasks, fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
            $top = $activeTasks[0];
            return "Execute task #{$top['id']}: \"{$top['title']}\" — it's active and highest priority.";
        }

        return 'Pick any open task and start. Movement creates clarity.';
    }

    private function requireInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            return null;
        }

        return (int) $args[$key];
    }
}
