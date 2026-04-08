# ABC — Action Breakdown & Creation

A [Coqui](https://github.com/carmelosantana/coqui) toolkit that brings aggressive task decomposition and execution to [Kanboard](https://kanboard.org/) projects. Inspired by [PaperclipAI](https://github.com/nicholasgriffintn/paperclip)'s hierarchical task alignment and Alex Hormozi's bias-to-action philosophy.

## What It Does

ABC provides 4 high-level orchestration tools that batch multiple Kanboard operations into single goal-oriented calls:

| Tool | Purpose |
|------|---------|
| `abc_decompose` | Break a goal into a full Kanboard project (project + swimlanes + categories + tasks + subtasks) in one call |
| `abc_status` | Comprehensive progress report with completion stats, stalled/overdue detection, and action recommendations |
| `abc_next` | Prioritized next actions scored by priority, overdue status, and effort |
| `abc_review` | Review and adjust: full analysis, kill stalled tasks, or rebalance workload |

Plus a skill, two specialized roles, and a loop definition for fully autonomous execution.

## Installation

```bash
composer require carmelosantana/coqui-toolkit-abc-co
```

The toolkit is auto-discovered by Coqui on the next boot. Roles and loop definitions are auto-seeded into the workspace.

## Credentials

ABC uses the same Kanboard credentials as `coqui-toolkit-kanboard`. Set them via the Coqui `credentials` tool or in your workspace `.env`:

```
KANBOARD_URL=https://kanboard.example.com/jsonrpc.php
KANBOARD_USERNAME=jsonrpc
KANBOARD_API_TOKEN=your-api-token
```

## Quick Start

### Decompose a Goal

```
abc_decompose(
    action: "create",
    project_name: "Launch API v2",
    goal: "Ship a production-ready REST API with auth, CRUD endpoints, and docs by Friday",
    swimlanes: '["Backend", "Infrastructure", "Documentation"]',
    categories: '["API", "Auth", "DevOps"]',
    tasks: '[
        {"title": "Set up project scaffolding", "priority": 3, "color_id": "red", "subtasks": [{"title": "Init repo"}, {"title": "Add CI pipeline"}, {"title": "Configure linting"}]},
        {"title": "Implement auth endpoints", "priority": 3, "color_id": "orange", "swimlane": "Backend", "category": "Auth", "subtasks": [{"title": "Login endpoint"}, {"title": "Token refresh"}, {"title": "Add tests"}]},
        {"title": "Build CRUD endpoints", "priority": 2, "color_id": "yellow", "swimlane": "Backend", "category": "API", "subtasks": [{"title": "Create endpoint"}, {"title": "Read endpoint"}, {"title": "Update endpoint"}, {"title": "Delete endpoint"}]},
        {"title": "Write API documentation", "priority": 1, "color_id": "blue", "swimlane": "Documentation"}
    ]'
)
```

### Check Status

```
abc_status(project_id: 42)
```

Returns completion percentage, column distribution, overdue tasks, stalled tasks, and Hormozi-style recommendations like "3 tasks stalled > 2 days — move or kill them."

### Get Next Action

```
abc_next(project_id: 42, limit: 3)
```

Returns scored and ranked tasks. Pick #1 and execute.

### Review and Clean Up

```
abc_review(project_id: 42, action: "review")       # Full analysis
abc_review(project_id: 42, action: "kill_stalled")  # Close tasks stalled > 5 days
abc_review(project_id: 42, action: "rebalance")     # WIP distribution advice
```

## Roles

### ABC Executor (`abc-executor`)

An action-biased execution agent that picks the highest-priority task and ships it. Full access level with 48 max iterations.

```
/role abc-executor
```

Or spawn as a child agent:
```
spawn_agent(role: "abc-executor", task: "Execute all tasks in Kanboard project 42")
```

### ABC Decomposer (`abc-decomposer`)

A goal breakdown specialist that creates structured Kanboard projects from high-level objectives. Read-only access level (analyzes but doesn't execute).

```
/role abc-decomposer
```

## Loop

### ABC Execute (`abc-execute`)

A three-stage autonomous loop: **Decompose → Execute → Review**

1. **Decomposer** creates the Kanboard project structure
2. **Executor** implements the highest-priority tasks
3. **Reviewer** checks progress and approves or sends feedback

Terminates when the reviewer responds "APPROVED".

```
loop_start(definition: "abc-execute", goal: "Build a REST API with auth and CRUD")
```

## The ABC Methodology

### Action
Ship fast, iterate faster. 80% confidence = GO. Every task produces a deliverable.

### Breakdown
If it takes > 2 hours, split it. Max 8 tasks per project. Max 5 subtasks per task.

### Creation
Every task creates something tangible. No "research" without output. No "monitor" without dashboard.

See the full methodology in the [ABC Skill](skills/abc-methodology/SKILL.md).

## Dependencies

- [`coquibot/coqui-toolkit-kanboard`](https://github.com/carmelosantana/coqui-toolkit-kanboard) — Kanboard API client and low-level tools
- PHP 8.4+

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
composer analyse
```

## License

MIT
