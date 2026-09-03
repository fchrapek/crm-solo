<?php

declare(strict_types=1);

use App\Http\Controllers\AgentBoardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FetchTranslationsController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskAttachmentsController;
use App\Http\Controllers\TaskPreviewsController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\TerminalSessionsController;
use App\Http\Controllers\TimeEntriesController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// Unauthenticated by necessity: the frontend fetches strings before a session
// exists. The locale is constrained because it lands in a filesystem path and
// '..' is a single valid URL segment.
Route::get('/locales/{locale}/translation.json', FetchTranslationsController::class)
    ->where('locale', '[a-z]{2}(_[A-Z]{2})?')
    ->name('i18next.fetch');

Route::controller(AuthenticatedSessionController::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', 'create')->name('login');
        Route::post('login', 'store')->name('login.store');
    });
    Route::post('logout', 'destroy')->name('logout');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Daily session — browser viewport onto the herdr daemon (status-first;
    // the native terminal stays the primary attach point).
    Route::post('/daily-session/attach', [App\Http\Controllers\DailySessionController::class, 'attach'])->name('daily-session.attach');
    Route::delete('/daily-session/attach', [App\Http\Controllers\DailySessionController::class, 'detach'])->name('daily-session.detach');

    // Demo-only crm prompt (whitelisted verbs, in-process, no shell) - the
    // controller 404s unless DEMO_MODE is on.
    Route::post('/demo/cli', App\Http\Controllers\DemoCliController::class)->middleware('throttle:30,1')->name('demo.cli');

    Route::resource('users', UsersController::class)->except(['show']);
    Route::put('users/{user}/restore', [UsersController::class, 'restore'])->name('users.restore');

    // Revenue analytics (Infakt-sourced)
    Route::get('revenue', [RevenueController::class, 'index'])->name('revenue.index');

    // Lead-gen funnels (config/leadgen.php — vault plan section 05)
    Route::resource('leads', App\Http\Controllers\LeadsController::class)->except(['show']);
    Route::put('leads/{lead}/restore', [App\Http\Controllers\LeadsController::class, 'restore'])->name('leads.restore');
    Route::patch('leads/{lead}/stage', [App\Http\Controllers\LeadsController::class, 'transitionStage'])->name('leads.transition-stage');
    Route::delete('lead-events/{event}', [App\Http\Controllers\LeadsController::class, 'destroyEvent'])->name('lead-events.destroy');
    Route::post('leads/{lead}/convert', [App\Http\Controllers\LeadsController::class, 'convertToClient'])->name('leads.convert');

    Route::resource('clients', ClientsController::class)->except(['show']);
    Route::put('clients/{client}/restore', [ClientsController::class, 'restore'])->name('clients.restore');
    Route::put('clients/{client}/pin', [ClientsController::class, 'pin'])->name('clients.pin');
    Route::patch('clients/{client}/stage', [ClientsController::class, 'transitionStage'])->name('clients.transition-stage');
    Route::patch('clients/{client}/month-close-type', [ClientsController::class, 'updateMonthCloseType'])->name('clients.month-close-type');
    Route::patch('clients/{client}/report-mode', [ClientsController::class, 'updateReportMode'])->name('clients.report-mode');
    Route::put('clients/{client}/report-baseline', [ClientsController::class, 'updateReportBaseline'])->name('clients.update-report-baseline');
    Route::post('clients/{client}/lifecycle-events', [App\Http\Controllers\ClientLifecycleEventsController::class, 'store'])->name('lifecycle-events.store');
    Route::patch('lifecycle-events/{event}/note', [App\Http\Controllers\ClientLifecycleEventsController::class, 'updateNote'])->name('lifecycle-events.update-note');

    // Client retainers (contracted hours, periodized).
    Route::post('clients/{client}/retainers', [App\Http\Controllers\ClientRetainersController::class, 'store'])->name('client-retainers.store');
    Route::put('clients/{client}/retainers/{retainer}', [App\Http\Controllers\ClientRetainersController::class, 'update'])->name('client-retainers.update');
    Route::delete('clients/{client}/retainers/{retainer}', [App\Http\Controllers\ClientRetainersController::class, 'destroy'])->name('client-retainers.destroy');

    // Client documents (signed contracts, GDPR clauses — stored locally).
    Route::post('clients/{client}/documents', [App\Http\Controllers\ClientDocumentsController::class, 'store'])->name('client-documents.store');
    Route::get('clients/{client}/documents/{document}', [App\Http\Controllers\ClientDocumentsController::class, 'show'])->name('client-documents.show');
    Route::delete('clients/{client}/documents/{document}', [App\Http\Controllers\ClientDocumentsController::class, 'destroy'])->name('client-documents.destroy');

    // Client reports (weekly/monthly retainer reports — draft → finalize flow).
    Route::post('clients/{client}/reports', [App\Http\Controllers\ClientReportsController::class, 'store'])->name('client-reports.store');
    Route::get('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'edit'])->name('client-reports.edit');
    Route::put('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'update'])->name('client-reports.update');
    Route::patch('clients/{client}/reports/{report}/opening-balance', [App\Http\Controllers\ClientReportsController::class, 'updateOpeningBalance'])->name('client-reports.opening-balance');
    Route::post('clients/{client}/reports/{report}/regenerate', [App\Http\Controllers\ClientReportsController::class, 'regenerate'])->name('client-reports.regenerate');
    Route::post('clients/{client}/reports/{report}/finalize', [App\Http\Controllers\ClientReportsController::class, 'finalize'])->name('client-reports.finalize');
    Route::post('clients/{client}/reports/{report}/reopen', [App\Http\Controllers\ClientReportsController::class, 'reopen'])->name('client-reports.reopen');
    Route::delete('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'destroy'])->name('client-reports.destroy');

    // Month close (per-maintained-client monthly checklist worklist).
    Route::get('month-close', [App\Http\Controllers\MonthCloseController::class, 'index'])->name('month-close.index');
    Route::post('month-close', [App\Http\Controllers\MonthCloseController::class, 'store'])->name('month-close.store');
    Route::patch('month-close/steps/{step}', [App\Http\Controllers\MonthCloseController::class, 'updateStep'])->name('month-close.update-step');

    Route::resource('contacts', ContactsController::class)->except(['show']);
    Route::put('contacts/{contact}/restore', [ContactsController::class, 'restore'])->name('contacts.restore');

    // Projects (private projects are first-class; Trello board attached via connect-trello)
    Route::post('clients/{client}/projects', [ProjectsController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectsController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('projects.destroy');
    Route::post('projects/{project}/connect-trello', [ProjectsController::class, 'connectTrello'])->name('projects.connect-trello');
    Route::post('projects/{project}/disconnect-trello', [ProjectsController::class, 'disconnectTrello'])->name('projects.disconnect-trello');
    Route::post('projects/{project}/sync-trello', [ProjectsController::class, 'syncTrello'])->name('projects.sync-trello')->middleware('throttle:5,1');
    Route::put('projects/{project}/trello-list-mapping', [ProjectsController::class, 'updateTrelloListMapping'])->name('projects.trello-list-mapping');
    Route::get('projects/{project}/available-trello-boards', [ProjectsController::class, 'availableTrelloBoards'])->name('projects.available-trello-boards');

    // Repositories (linked to projects)
    Route::post('projects/{project}/repositories', [RepositoriesController::class, 'store'])->name('repositories.store');
    Route::put('repositories/{repository}', [RepositoriesController::class, 'update'])->name('repositories.update');
    Route::delete('repositories/{repository}', [RepositoriesController::class, 'destroy'])->name('repositories.destroy');
    Route::get('repositories/{repository}/branches', [RepositoriesController::class, 'branches'])->name('repositories.branches');

    Route::post('projects/{project}/tasks', [TasksController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{task}', [TasksController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TasksController::class, 'destroy'])->name('tasks.destroy');

    // Terminal sessions (claude/codex CLI launched in a per-task git worktree via ttyd)
    Route::post('/tasks/{task}/start-session', [TerminalSessionsController::class, 'start'])->name('tasks.start-session');
    Route::get('/tasks/{task}/session-branches', [TerminalSessionsController::class, 'branches'])->name('tasks.session-branches');
    Route::delete('/tasks/{task}/stop-session', [TerminalSessionsController::class, 'stop'])->name('tasks.stop-session');
    Route::post('/tasks/{task}/resume-session', [TerminalSessionsController::class, 'resume'])->name('tasks.resume-session');
    Route::delete('/tasks/{task}/kill-session', [TerminalSessionsController::class, 'kill'])->name('tasks.kill-session');
    Route::post('/tasks/{task}/clear-session-attention', [TerminalSessionsController::class, 'clearAttention'])->name('tasks.clear-session-attention');

    // Per-task preview — spawn the project's preview_command in the task's
    // worktree via ttyd. Stack-agnostic: ddev/vite/rails/whatever.
    Route::post('/tasks/{task}/preview/start', [TaskPreviewsController::class, 'start'])->name('tasks.preview.start');
    Route::delete('/tasks/{task}/preview/stop', [TaskPreviewsController::class, 'stop'])->name('tasks.preview.stop');

    // Kanban lane + CLI mutations
    Route::patch('/tasks/{task}/agent-lane', [TasksController::class, 'updateAgentLane'])->name('tasks.agent-lane');
    Route::patch('/tasks/{task}/list-name', [TasksController::class, 'updateListName'])->name('tasks.list-name');
    Route::patch('/tasks/{task}/archived', [TasksController::class, 'updateArchived'])->name('tasks.archived');
    Route::patch('/tasks/{task}/reportable', [TasksController::class, 'updateReportable'])->name('tasks.reportable');
    Route::patch('/tasks/{task}/cli', [TasksController::class, 'updateCli'])->name('tasks.cli');

    // Task attachments (manual uploads — context for the agent session)
    Route::get('/tasks/{task}/attachments', [TaskAttachmentsController::class, 'index'])->name('task-attachments.index');
    Route::post('/tasks/{task}/attachments', [TaskAttachmentsController::class, 'store'])->name('task-attachments.store');
    Route::get('/task-attachments/{attachment}', [TaskAttachmentsController::class, 'show'])
        ->name('task-attachments.show')
        ->where('attachment', '[0-9]+');
    Route::delete('/task-attachments/{attachment}', [TaskAttachmentsController::class, 'destroy'])->name('task-attachments.destroy');

    // Manual time entries (auto-tracked terminal-session entries land in the
    // same table via TerminalSessionLauncher, not via these routes).
    Route::get('/time-entries/running', [TimeEntriesController::class, 'running'])->name('time-entries.running');
    Route::post('/tasks/{task}/time-entries', [TimeEntriesController::class, 'store'])->name('time-entries.store');
    Route::post('/tasks/{task}/time-entries/start', [TimeEntriesController::class, 'start'])->name('time-entries.start');
    Route::post('/time-entries/{timeEntry}/stop', [TimeEntriesController::class, 'stop'])->name('time-entries.stop');
    Route::put('/time-entries/{timeEntry}', [TimeEntriesController::class, 'update'])->name('time-entries.update');
    Route::delete('/time-entries/{timeEntry}', [TimeEntriesController::class, 'destroy'])->name('time-entries.destroy');

    // Per-project boards + task detail. The plain board is where manual drag
    // lives; the Work tab only lists projects.
    Route::get('/clients/{client}/projects/{project}', [ProjectBoardController::class, 'show'])->name('project-board.show');
    Route::get('/clients/{client}/projects/{project}/agent-board', [AgentBoardController::class, 'show'])->name('agent-board.show');
    Route::get('/tasks/{task}', [TasksController::class, 'show'])->name('tasks.show');

    // Settings hub — config defaults + per-account overrides (settings table)
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('/settings/leadgen', [SettingsController::class, 'updateLeadgen'])->name('settings.leadgen.update');

    Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/{provider}', [IntegrationsController::class, 'edit'])->name('integrations.edit');
    Route::put('/integrations/{provider}', [IntegrationsController::class, 'update'])->name('integrations.update');
    Route::post('/integrations/{provider}/sync', [IntegrationsController::class, 'sync'])->name('integrations.sync');
});
