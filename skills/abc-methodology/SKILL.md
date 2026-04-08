# ABC Methodology — Action Breakdown & Creation

A framework for aggressive task decomposition and execution, inspired by PaperclipAI's hierarchical goal alignment and Alex Hormozi's bias-to-action philosophy. Use this skill when managing projects through Kanboard via the ABC toolkit.

## The ABC Framework

### Action
Ship fast, iterate faster. Every task must produce a tangible deliverable — no "research" or "investigate" tasks without a defined output. If you're unsure, build a prototype instead of writing a document.

- **80% confidence = GO.** Don't wait for perfect information.
- **Time-box everything.** No task should take more than one focused session.
- **Default to doing.** When in doubt between planning and executing, execute.

### Breakdown
If a task takes more than 2 hours, it's too big. Split it.

- **Max 8 top-level tasks per project.** If you need more, your goal is actually multiple goals.
- **Max 5 subtasks per task.** If you need more, promote the task to a project.
- **Each task has acceptance criteria.** "Done" is defined before work begins.
- **Subtasks are atomic.** Completable in one sitting, no dependencies on other subtasks.

### Creation
Every task must create something. The output can be code, a config file, a decision document, a test result, or a deployment. But there must be an artifact.

- **No open-ended tasks.** "Explore options for X" → "Write comparison doc for X with recommendation"
- **No status tasks.** "Monitor Y" → "Create dashboard for Y" or "Write alert rules for Y"
- **No vague tasks.** "Improve performance" → "Reduce endpoint latency to <200ms"

## Hormozi Operating Principles

These principles from Alex Hormozi's approach to business apply directly to task execution:

1. **Volume negates luck.** Do more, think less. Ship 10 small things instead of planning 1 big thing.
2. **Time is the bottleneck.** Not money, not skill, not knowledge — time. Protect it ruthlessly.
3. **"What if it were easy?"** Before decomposing a complex goal, ask this. The simple version is usually 80% as good.
4. **Kill fast.** 2 iterations with no measurable progress → kill the task or pivot the approach. No sunk cost fallacy.
5. **Stop starting, start finishing.** WIP limits exist for a reason. Finish current work before picking up new work.
6. **Constraint creates freedom.** Tight deadlines and small scopes produce better work than open-ended exploration.
7. **Momentum > perfection.** A shipped 80% solution beats a planned 100% solution every time.

## Goal Hierarchy Pattern

Every piece of work traces back to a goal. The hierarchy is:

```
Goal → Project → Task → Subtask
```

- **Goal**: The business outcome. "Launch the API by Friday." "Reduce churn by 20%."
- **Project**: A Kanboard project that achieves the goal. Contains up to 8 tasks.
- **Task**: An executable unit of work with a clear deliverable. Has priority, category, and due date.
- **Subtask**: An atomic step within a task. Status: Todo → In Progress → Done.

Every task should answer: **"Why does this matter?"** If you can't trace it back to the goal, kill it.

## Decomposition Rules

When breaking down a goal with `abc_decompose`:

1. **Start with the goal statement.** Write it as the project description.
2. **Identify 3-8 workstreams.** These become your top-level tasks or swimlanes.
3. **For each workstream, define 1-5 concrete subtasks.** Each subtask is one action.
4. **Assign priorities.** Use 0 (none), 1 (low), 2 (medium), 3 (high).
5. **Set color coding:**
   - `red` — Blocking/critical
   - `orange` — High priority
   - `yellow` — Normal
   - `green` — Quick win / easy
   - `blue` — Research-dependent
   - `purple` — External dependency

## Progress Cadence

- **Before each work block:** Run `abc_status(project_id)` to see where things stand.
- **Pick next action:** Run `abc_next(project_id)` and execute the top item. No deliberating.
- **After each work block:** Update task status. Mark subtasks done. Add completion comments.
- **Daily review:** Run `abc_review(project_id, action: "review")` for full analysis.
- **Weekly cleanup:** Run `abc_review(project_id, action: "kill_stalled")` to close dead work.
- **Never spend > 5 minutes deciding what to do.** Use `abc_next` and start.

## Kill Criteria

- **2 iterations with no progress** → Kill or fundamentally pivot the approach.
- **Stalled > 2 days** → Escalate or close. Something is blocking — identify it or move on.
- **Scope creep detected** → Split off the new scope into a separate project. Keep original focused.
- **External dependency blocking** → Create a tracking comment, move to "Waiting" swimlane, work on something else.
- **Overdue > 3 days** → Either the deadline was wrong or the task is too big. Re-date with smaller scope or close.

## Kanboard Conventions

When using ABC tools with Kanboard:

### Default Columns (Kanboard defaults)
- **Backlog** — Tasks not yet started
- **Ready** — Tasks ready to be picked up
- **Work in progress** — Actively being worked on (WIP limit: 3)
- **Done** — Completed tasks (close after review)

### Swimlane Usage
Use swimlanes to separate workstreams within a project:
- Frontend / Backend / Infrastructure
- Sprint 1 / Sprint 2 / Sprint 3
- Must-have / Nice-to-have / Stretch

### Tag Taxonomy
- `quick-win` — Completable in < 30 minutes
- `blocked` — Waiting on external dependency
- `tech-debt` — Cleanup work, not new features
- `mvp` — Required for minimum viable delivery
