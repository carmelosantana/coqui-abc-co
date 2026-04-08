<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitAbcCo\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * ABC Status tool — comprehensive progress report with action recommendations.
 *
 * Analyzes a Kanboard project's board state, open/overdue tasks, and column
 * distribution, then generates Hormozi-style action recommendations.
 */
final readonly class StatusTool
{
    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'abc_status',
            description: 'Get a comprehensive progress report for a Kanboard project with completion stats, stalled/overdue detection, WIP analysis, and Hormozi-style action recommendations.',
            parameters: [
                new NumberParameter('project_id', 'Kanboard project ID', required: true, integer: true),
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

        try {
            // Fetch board state, all tasks, and overdue tasks in batch
            $batchResults = $this->client->batch([
                ['method' => 'getBoard', 'params' => ['project_id' => $projectId]],
                ['method' => 'getAllTasks', 'params' => ['project_id' => $projectId, 'status_id' => 1]],
                ['method' => 'getOverdueTasksByProject', 'params' => ['project_id' => $projectId]],
                ['method' => 'getProjectById', 'params' => ['project_id' => $projectId]],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to fetch project data: ' . $e->getMessage());
        }

        $board = ($batchResults[0]['success'] ?? false) ? ($batchResults[0]['result'] ?? []) : [];
        $allTasks = ($batchResults[1]['success'] ?? false) ? ($batchResults[1]['result'] ?? []) : [];
        $overdueTasks = ($batchResults[2]['success'] ?? false) ? ($batchResults[2]['result'] ?? []) : [];
        $project = ($batchResults[3]['success'] ?? false) ? ($batchResults[3]['result'] ?? []) : [];

        if (!is_array($board) || !is_array($allTasks)) {
            return ToolResult::error('Failed to read project board state.');
        }

        if (!is_array($overdueTasks)) {
            $overdueTasks = [];
        }

        if (!is_array($project)) {
            $project = [];
        }

        // Analyze column distribution
        $columns = $this->analyzeColumns($board);

        // Count total and completed tasks
        $totalTasks = count($allTasks);
        $closedTasks = 0;

        try {
            $closedResult = $this->client->call('getAllTasks', ['project_id' => $projectId, 'status_id' => 0]);
            $closedTasks = is_array($closedResult) ? count($closedResult) : 0;
        } catch (\Throwable) {
            // Non-critical — continue without closed count
        }

        $grandTotal = $totalTasks + $closedTasks;
        $completionPct = $grandTotal > 0 ? round(($closedTasks / $grandTotal) * 100, 1) : 0.0;

        // Detect stalled tasks (in progress columns with no recent activity)
        $stalledTasks = $this->detectStalled($allTasks);

        // Generate recommendations
        $recommendations = $this->generateRecommendations(
            totalOpen: $totalTasks,
            completed: $closedTasks,
            overdue: count($overdueTasks),
            stalled: count($stalledTasks),
            columns: $columns,
        );

        $report = [
            'project' => [
                'id' => $projectId,
                'name' => $project['name'] ?? 'Unknown',
            ],
            'progress' => [
                'total_tasks' => $grandTotal,
                'open' => $totalTasks,
                'completed' => $closedTasks,
                'completion_pct' => $completionPct,
                'overdue' => count($overdueTasks),
                'stalled' => count($stalledTasks),
            ],
            'columns' => $columns,
            'overdue_tasks' => array_map(fn(mixed $t): array => [
                'id' => is_array($t) ? ($t['id'] ?? null) : null,
                'title' => is_array($t) ? ($t['title'] ?? '') : '',
                'date_due' => is_array($t) ? ($t['date_due'] ?? '') : '',
            ], array_slice($overdueTasks, 0, 10)),
            'stalled_tasks' => array_map(fn(array $t): array => [
                'id' => $t['id'] ?? null,
                'title' => $t['title'] ?? '',
                'days_stalled' => $t['days_stalled'] ?? 0,
            ], array_slice($stalledTasks, 0, 10)),
            'recommendations' => $recommendations,
        ];

        return ToolResult::success(
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    /**
     * @return array<int, array{name: string, task_count: int}>
     */
    private function analyzeColumns(array $board): array
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

    /**
     * Detect tasks that appear stalled — open tasks with no modification in 48+ hours.
     *
     * @return array<int, array{id: int, title: string, days_stalled: int}>
     */
    private function detectStalled(array $tasks): array
    {
        $stalled = [];
        $now = time();
        $threshold = 48 * 3600; // 48 hours

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $modified = (int) ($task['date_modification'] ?? 0);
            if ($modified <= 0) {
                continue;
            }

            $elapsed = $now - $modified;
            if ($elapsed > $threshold) {
                $stalled[] = [
                    'id' => (int) ($task['id'] ?? 0),
                    'title' => (string) ($task['title'] ?? ''),
                    'days_stalled' => (int) round($elapsed / 86400),
                ];
            }
        }

        return $stalled;
    }

    /**
     * Generate Hormozi-style action recommendations based on project analysis.
     *
     * @param array<int, array{name: string, task_count: int}> $columns
     * @return string[]
     */
    private function generateRecommendations(
        int $totalOpen,
        int $completed,
        int $overdue,
        int $stalled,
        array $columns,
    ): array {
        $recs = [];

        if ($overdue > 0) {
            $recs[] = "{$overdue} tasks overdue — close or re-date NOW. Overdue tasks are lies on your board.";
        }

        if ($stalled > 0) {
            $recs[] = "{$stalled} tasks stalled > 2 days — move or kill them. Stalled = stuck = waste.";
        }

        // Check for WIP overload in any column
        foreach ($columns as $col) {
            if ($col['task_count'] > 5) {
                $recs[] = "Column \"{$col['name']}\" has {$col['task_count']} tasks — WIP limit exceeded. Stop starting, start finishing.";
            }
        }

        if ($completed === 0 && $totalOpen > 0) {
            $recs[] = '0 tasks completed — pick the smallest open task and ship it NOW. Momentum > perfection.';
        }

        if ($totalOpen === 0 && $completed === 0) {
            $recs[] = 'Empty project — decompose your goal with abc_decompose and start executing.';
        }

        if ($totalOpen > 0 && $overdue === 0 && $stalled === 0 && $recs === []) {
            $recs[] = 'Board is clean. Pick the next task and execute. No excuses.';
        }

        return $recs;
    }

    private function requireInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            return null;
        }

        return (int) $args[$key];
    }
}
