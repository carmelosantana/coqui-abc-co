<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitAbcCo;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\DecomposeTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\NextActionTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\ReviewTool;
use CarmeloSantana\CoquiToolkitAbcCo\Tool\StatusTool;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * ABC (Action Breakdown & Creation) toolkit for Coqui.
 *
 * Provides 4 high-level orchestration tools that batch multiple Kanboard
 * operations into single goal-oriented calls. Inspired by PaperclipAI's
 * hierarchical task decomposition and Alex Hormozi's action-biased philosophy.
 *
 * Tools:
 * - abc_decompose — Break down goals into Kanboard projects with tasks and subtasks
 * - abc_status — Comprehensive progress report with action recommendations
 * - abc_next — Prioritized next actions to take
 * - abc_review — Review progress and suggest project adjustments
 *
 * Depends on coquibot/coqui-toolkit-kanboard for the KanboardClient.
 */
final class AbcToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly KanboardClient $client,
    ) {}

    public static function fromEnv(): self
    {
        return new self(client: KanboardClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            (new DecomposeTool($this->client))->build(),
            (new StatusTool($this->client))->build(),
            (new NextActionTool($this->client))->build(),
            (new ReviewTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <ABC-TOOLKIT-GUIDELINES>
            ## ABC — Action Breakdown & Creation

            You have 4 high-level orchestration tools that wrap Kanboard operations into
            goal-oriented workflows. These are built for speed — bias to action, not analysis.

            ### Philosophy
            - **Action**: Ship fast, iterate faster. 80% confidence = go.
            - **Breakdown**: If it takes > 2 hours, split it. Max 8 tasks per project. Max 5 subtasks per task.
            - **Creation**: Every task produces a deliverable. No "research" tasks without an output.

            ### Tool Overview
            - `abc_decompose` — Break a goal into a full Kanboard project (project + swimlanes + categories + tasks + subtasks) in one call. Or add tasks to an existing project.
            - `abc_status` — Get comprehensive progress report with Hormozi-style action recommendations. Shows completion %, stalled tasks, overdue items, and WIP warnings.
            - `abc_next` — Get prioritized next actions. Scores tasks by priority, overdue status, and effort. Pick the top one and execute.
            - `abc_review` — Review and adjust: full analysis, kill stalled tasks, or rebalance workload.

            ### Workflow
            1. Decompose the goal: `abc_decompose(action: "create", ...)`
            2. Check status before work: `abc_status(project_id: N)`
            3. Pick next action: `abc_next(project_id: N)`
            4. Execute the task using appropriate tools
            5. Review progress: `abc_review(project_id: N, action: "review")`
            6. Kill stalled work: `abc_review(project_id: N, action: "kill_stalled")`

            ### Operating Principles
            - Never spend > 5 minutes deciding what to do. Use `abc_next` and start.
            - "What if it were easy?" — simplify before decomposing.
            - Volume negates luck — do more, think less.
            - If 2 iterations produce no progress, kill it or pivot.
            - Stop starting, start finishing — WIP limits matter.
            </ABC-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
