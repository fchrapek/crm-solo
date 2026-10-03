# Useful commands

CLI-friendly artisan commands for everyday data management on local dev. All examples target a made-up project, **bakery.test** (`project_id=42`, `client_id=7`, account `1` — private project, no Trello). Substitute your own ids; the shapes are what matter.

> Resolution rule shared by every command: any `--project` / `--client` / `<project>` argument accepts **either a numeric ID or a (sub)string of the name**. Names use case-insensitive `LIKE %needle%`. Ambiguity → error with the full match list so you can disambiguate by ID.

---

## Tasks

### `tasks:create`

Mass-create tasks on a project.

```
php artisan tasks:create
    --project=<id|name>              # required
    --name="…"                       # repeatable — one task per --name
    --count=N                        # bulk-create N copies; requires a single --name (used as prefix)
    --list=Backlog                   # Backlog | To-Do | Doing | Testing | Done   (default: Backlog)
    --source=manual                  # manual | email | trello | planner          (default: manual)
    --priority=low|medium|high       # optional
    --description="…"                # applied to every task
    --account=1                      # must be the acting account (the default); another fails
```

Examples:

```bash
# Three distinct tasks
php artisan tasks:create --project=bakery.test \
  --name="Bug: header overlaps hero on mobile" \
  --name="Add testimonials section to About page" \
  --name="Optimize hero image (LCP > 4s)"

# 20 stress-test copies on a single project (kanban load)
php artisan tasks:create --project=42 --name="Load test" --count=20

# Land in the review queue (skip the is_reviewed default)
# Tip: bulk-creates default to is_reviewed=true; use the UI flow for review-queue work.

# Custom lane + priority
php artisan tasks:create --project=42 --name="Cookie banner QA" --list=Testing --priority=high
```

Notes:
- Defaults to `is_reviewed=true` so the task doesn't queue at `/tasks/review`.
- `source=manual` keeps the task CRM-only on a Trello-backed project (no `trello_card_id` → invisible to the sync upserter).
- To flag a task as agent-track (so it appears on the agent kanban with a Start Session button), set `cli` via `php artisan tasks:cli <task-id> claude|codex` or the "Send to agent kanban" dropdown on any task card.

### `tasks:delete`

Mass-delete (or soft-archive) tasks. Confirmation by default; `--force` skips.

```
php artisan tasks:delete
    --project=<id|name>              # required
    --source=manual|email|trello     # optional filter
    --list=<lane>                    # optional filter
    --archived                       # only target already-archived rows
    --archive-only                   # soft-archive (set archived_at) instead of deleting
    --force                          # skip confirmation
    --account=1                      # must be the acting account (the default); another fails
```

Without any filter, it targets ALL tasks on the project — the confirmation prompt makes this explicit (`filter: ALL tasks (no filters)`).

Examples:

```bash
# Wipe manual tasks only (keeps planner/Trello/email rows intact)
php artisan tasks:delete --project=bakery.test --source=manual --force

# Wipe only what's in the Done lane
php artisan tasks:delete --project=42 --list=Done

# Soft-archive every task on a project (reversible)
php artisan tasks:delete --project=bakery.test --archive-only --force

# Hard-delete only already-archived tasks (cleanup pass)
php artisan tasks:delete --project=42 --archived --force

# Nuke everything (asks first unless --force)
php artisan tasks:delete --project=42
```

Cascade: deleting a task drops `task_runs`, `task_run_artifacts`, `task_attachments`, `task_design_references` via FK. Files in `storage/app/private/run-artifacts/{run_id}/` and `task-attachments/{task_id}/` are **not** cleaned automatically.

---

## Projects

### `projects:create`

Create one or more **private** projects under a client (no Trello board attached).

```
php artisan projects:create
    --client=<id|name>               # required
    --name="…"                       # repeatable — one project per --name
    --description="…"                # applied to every project
    --account=1                      # must be the acting account (the default); another fails
```

Examples:

```bash
# Single project
php artisan projects:create --client="Bakery Co" --name="bakery.test — staging tests"

# Several at once with a shared description
php artisan projects:create --client=125 \
  --name="Sprint 1" --name="Sprint 2" --name="Sprint 3" \
  --description="Throwaway scrum board for the May sprint"
```

Note: creating a **Client** via `ClientsController::store` auto-spawns a `"General"` private project. This command does not — you control naming, and `settings` start empty.

### `projects:delete`

Delete a single project, or wipe every project for a client.

```
php artisan projects:delete <project>           # single-project mode
php artisan projects:delete --client=<id|name>  # wipe-all mode (preserves "General")

    --include-general                # also delete the "General" project (wipe-all mode)
    --force                          # skip confirmation
    --account=1                      # must be the acting account (the default); another fails
```

Examples:

```bash
# Delete one project (by id or name) and its tasks; their time entries stay, detached
php artisan projects:delete cli-test-project-A
php artisan projects:delete 192 --force

# Wipe ALL projects for a client except "General"
php artisan projects:delete --client="Bakery Co"

# Same, but also drop the "General" project
php artisan projects:delete --client=125 --include-general --force
```

The prompt prints every targeted project with `[Trello-linked]` / `[INBOX]` tags so you can bail before destroying anything important:

- **Trello-linked** (`trello_board_id IS NOT NULL`) → command removes CRM data only; the Trello board remains. Prefer the UI's *Disconnect* action when you want clean separation.
- **Inbox** (`is_inbox=true`) → per-account synthetic project (legacy of the removed email pipeline). Safe to drop but flagged.

Cascade: tasks + runs + artifacts + attachments + design references. Files on disk are not cleaned.

---

## Reset to a known state (bakery.test)

End-to-end reset — wipe non-planner tasks and reseed with a small handful of fixtures:

```bash
php artisan tasks:delete --project=bakery.test --source=manual --force

php artisan tasks:create --project=bakery.test \
  --name="Bug: header overlaps hero on mobile" \
  --name="Add testimonials section to About page" \
  --name="Optimize hero image (LCP > 4s)" \
  --name="QA: footer cookie banner on mobile"
```

---

## Related

- `php artisan demo:reset` — wipe + reseed the fictional `DemoSeeder` world. Refuses to run unless `DEMO_MODE=true` (it drops every table).
- `php artisan db:backup` / `php artisan db:restore` — snapshot before risky bulk ops. See [setup.md](setup.md).
- `php artisan trello:sync --force --sync` — refresh Trello-backed projects (read-only direction Trello → CRM).
