<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitAbcCo\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * ABC Decompose tool — breaks goals into full Kanboard projects.
 *
 * Creates a complete project structure (project + swimlanes + categories + tasks + subtasks)
 * in one call using JSON-RPC batch requests, or adds tasks to an existing project.
 */
final readonly class DecomposeTool
{
    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'abc_decompose',
            description: 'Break down a goal into a full Kanboard project with tasks and subtasks in one call. Actions: "create" builds a new project; "add_tasks" adds more tasks to an existing project.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', ['create', 'add_tasks']),
                new StringParameter('project_name', 'Project name (required for create)', required: false),
                new StringParameter('goal', 'The goal this project achieves — stored as project description', required: false),
                new NumberParameter('project_id', 'Existing project ID (required for add_tasks)', required: false, integer: true),
                new StringParameter('tasks', 'JSON array of tasks: [{title, description?, priority?, color_id?, subtasks?: [{title}], category?, swimlane?}]', required: false),
                new StringParameter('swimlanes', 'JSON array of swimlane names to create (optional, for create)', required: false),
                new StringParameter('categories', 'JSON array of category names to create (optional, for create)', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'create' => $this->create($args),
            'add_tasks' => $this->addTasks($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $projectName = trim((string) ($args['project_name'] ?? ''));
        if ($projectName === '') {
            return ToolResult::error('project_name is required for create.');
        }

        $goal = trim((string) ($args['goal'] ?? ''));
        $tasks = $this->parseJsonArray($args, 'tasks');
        if ($tasks === null || $tasks === []) {
            return ToolResult::error('tasks (JSON array) is required for create.');
        }

        if (count($tasks) > 8) {
            return ToolResult::error('ABC rule: max 8 top-level tasks per project. Break into multiple projects.');
        }

        // Step 1: Create the project
        try {
            $params = ['name' => $projectName];
            if ($goal !== '') {
                $params['description'] = "# Goal\n\n{$goal}";
            }

            $projectId = $this->client->call('createProject', $params);
            if (!is_int($projectId) || $projectId < 1) {
                return ToolResult::error('Failed to create project — Kanboard returned: ' . json_encode($projectId));
            }
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to create project: ' . $e->getMessage());
        }

        // Step 2: Create swimlanes (batch)
        $swimlaneMap = [];
        $swimlanes = $this->parseJsonArray($args, 'swimlanes');
        if ($swimlanes !== null && $swimlanes !== []) {
            $swimlaneMap = $this->createSwimlanes($projectId, $swimlanes);
        }

        // Step 3: Create categories (batch)
        $categoryMap = [];
        $categories = $this->parseJsonArray($args, 'categories');
        if ($categories !== null && $categories !== []) {
            $categoryMap = $this->createCategories($projectId, $categories);
        }

        // Step 4: Create tasks with resolved IDs (batch)
        $taskResults = $this->createTasks($projectId, $tasks, $swimlaneMap, $categoryMap);

        // Step 5: Create subtasks for tasks that have them (batch)
        $subtaskCount = $this->createSubtasks($tasks, $taskResults);

        $successCount = count(array_filter($taskResults, fn(array $r): bool => $r['success']));

        return ToolResult::success(json_encode([
            'project_id' => $projectId,
            'project_name' => $projectName,
            'task_count' => $successCount,
            'subtask_count' => $subtaskCount,
            'swimlanes' => $swimlaneMap,
            'categories' => $categoryMap,
            'tasks' => array_map(fn(array $r): array => [
                'title' => $r['title'],
                'task_id' => $r['task_id'] ?? null,
                'status' => $r['success'] ? 'created' : 'failed',
            ], $taskResults),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function addTasks(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for add_tasks.');
        }

        $tasks = $this->parseJsonArray($args, 'tasks');
        if ($tasks === null || $tasks === []) {
            return ToolResult::error('tasks (JSON array) is required for add_tasks.');
        }

        // Resolve existing swimlanes and categories for name matching
        $swimlaneMap = $this->getExistingSwimlanes($projectId);
        $categoryMap = $this->getExistingCategories($projectId);

        $taskResults = $this->createTasks($projectId, $tasks, $swimlaneMap, $categoryMap);
        $subtaskCount = $this->createSubtasks($tasks, $taskResults);

        $successCount = count(array_filter($taskResults, fn(array $r): bool => $r['success']));

        return ToolResult::success(json_encode([
            'project_id' => $projectId,
            'tasks_added' => $successCount,
            'subtask_count' => $subtaskCount,
            'tasks' => array_map(fn(array $r): array => [
                'title' => $r['title'],
                'task_id' => $r['task_id'] ?? null,
                'status' => $r['success'] ? 'created' : 'failed',
            ], $taskResults),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @param string[] $names
     * @return array<string, int> name → ID map
     */
    private function createSwimlanes(int $projectId, array $names): array
    {
        $requests = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $requests[] = [
                    'method' => 'addSwimlane',
                    'params' => ['project_id' => $projectId, 'name' => $name],
                ];
            }
        }

        if ($requests === []) {
            return [];
        }

        try {
            $results = $this->client->batch($requests);
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($results as $i => $result) {
            $name = trim((string) ($names[$i] ?? ''));
            if ($result['success'] && is_int($result['result']) && $result['result'] > 0) {
                $map[$name] = $result['result'];
            }
        }

        return $map;
    }

    /**
     * @param string[] $names
     * @return array<string, int> name → ID map
     */
    private function createCategories(int $projectId, array $names): array
    {
        $requests = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $requests[] = [
                    'method' => 'createCategory',
                    'params' => ['project_id' => $projectId, 'name' => $name],
                ];
            }
        }

        if ($requests === []) {
            return [];
        }

        try {
            $results = $this->client->batch($requests);
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($results as $i => $result) {
            $name = trim((string) ($names[$i] ?? ''));
            if ($result['success'] && is_int($result['result']) && $result['result'] > 0) {
                $map[$name] = $result['result'];
            }
        }

        return $map;
    }

    /**
     * @param array<string, int> $swimlaneMap
     * @param array<string, int> $categoryMap
     * @return array<int, array{title: string, success: bool, task_id?: int}>
     */
    private function createTasks(int $projectId, array $tasks, array $swimlaneMap, array $categoryMap): array
    {
        $requests = [];
        $titles = [];

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $params = [
                'title' => $title,
                'project_id' => $projectId,
            ];

            if (isset($task['description']) && trim((string) $task['description']) !== '') {
                $params['description'] = trim((string) $task['description']);
            }

            if (isset($task['priority'])) {
                $params['priority'] = (int) $task['priority'];
            }

            if (isset($task['color_id']) && trim((string) $task['color_id']) !== '') {
                $params['color_id'] = trim((string) $task['color_id']);
            }

            // Resolve swimlane name to ID
            if (isset($task['swimlane']) && trim((string) $task['swimlane']) !== '') {
                $swimlaneName = trim((string) $task['swimlane']);
                if (isset($swimlaneMap[$swimlaneName])) {
                    $params['swimlane_id'] = $swimlaneMap[$swimlaneName];
                }
            }

            // Resolve category name to ID
            if (isset($task['category']) && trim((string) $task['category']) !== '') {
                $categoryName = trim((string) $task['category']);
                if (isset($categoryMap[$categoryName])) {
                    $params['category_id'] = $categoryMap[$categoryName];
                }
            }

            $requests[] = ['method' => 'createTask', 'params' => $params];
            $titles[] = $title;
        }

        if ($requests === []) {
            return [];
        }

        try {
            $results = $this->client->batch($requests);
        } catch (\Throwable) {
            return array_map(fn(string $t): array => ['title' => $t, 'success' => false], $titles);
        }

        $taskResults = [];
        foreach ($results as $i => $result) {
            $entry = ['title' => $titles[$i] ?? '', 'success' => $result['success']];
            if ($result['success'] && is_int($result['result']) && $result['result'] > 0) {
                $entry['task_id'] = $result['result'];
            }
            $taskResults[] = $entry;
        }

        return $taskResults;
    }

    /**
     * Create subtasks for tasks that define them.
     *
     * @param array<int, array{title: string, success: bool, task_id?: int}> $taskResults
     * @return int Total subtasks created
     */
    private function createSubtasks(array $tasks, array $taskResults): int
    {
        $requests = [];

        foreach ($tasks as $i => $task) {
            if (!is_array($task) || !isset($task['subtasks']) || !is_array($task['subtasks'])) {
                continue;
            }

            $taskId = $taskResults[$i]['task_id'] ?? null;
            if ($taskId === null) {
                continue;
            }

            $subtasks = $task['subtasks'];
            if (count($subtasks) > 5) {
                $subtasks = array_slice($subtasks, 0, 5); // ABC rule: max 5 subtasks per task
            }

            foreach ($subtasks as $subtask) {
                $title = trim((string) (is_array($subtask) ? ($subtask['title'] ?? '') : $subtask));
                if ($title !== '') {
                    $requests[] = [
                        'method' => 'createSubtask',
                        'params' => ['task_id' => $taskId, 'title' => $title],
                    ];
                }
            }
        }

        if ($requests === []) {
            return 0;
        }

        try {
            $results = $this->client->batch($requests);
            return count(array_filter($results, fn(array $r): bool => $r['success']));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, int> name → ID map
     */
    private function getExistingSwimlanes(int $projectId): array
    {
        try {
            $swimlanes = $this->client->call('getActiveSwimlanes', ['project_id' => $projectId]);
            if (!is_array($swimlanes)) {
                return [];
            }

            $map = [];
            foreach ($swimlanes as $sl) {
                if (is_array($sl) && isset($sl['name'], $sl['id'])) {
                    $map[(string) $sl['name']] = (int) $sl['id'];
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int> name → ID map
     */
    private function getExistingCategories(int $projectId): array
    {
        try {
            $categories = $this->client->call('getAllCategories', ['project_id' => $projectId]);
            if (!is_array($categories)) {
                return [];
            }

            $map = [];
            foreach ($categories as $cat) {
                if (is_array($cat) && isset($cat['name'], $cat['id'])) {
                    $map[(string) $cat['name']] = (int) $cat['id'];
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseJsonArray(array $args, string $key): ?array
    {
        $value = $args[$key] ?? null;

        // Already an array (php-agents decoded it)
        if (is_array($value)) {
            return $value === [] ? null : $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function requireInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            return null;
        }

        return (int) $args[$key];
    }
}
