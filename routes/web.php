<?php

declare(strict_types=1);

use App\Http\Controllers\AgentTokensController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\FetchTranslationsController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskAttachmentsController;
use App\Http\Controllers\TaskBriefController;
use App\Http\Controllers\TaskLinksController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\TimeEntriesController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Routing\RedirectController;
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

// Closed mode (APP_CLOSED): the /czesc splash guests are sent to instead of /login.
Route::middleware(['guest', 'closed'])->controller(WaitlistController::class)->group(function () {
    Route::get('czesc', 'show')->name('czesc');
    Route::post('czesc', 'store')->name('czesc.store')->middleware('throttle:waitlist');
});

// The demo write budget counts only state-changing requests and only under DEMO_MODE.
Route::middleware(['auth', 'throttle:demo-writes', App\Http\Middleware\LimitDemoTextWrites::class])->group(function () {
    Route::get('/', [App\Http\Controllers\TodayController::class, 'index'])->name('dashboard');
    Route::get('/jutro', [App\Http\Controllers\TodayController::class, 'plan'])->name('today.plan');
    Route::get('/zadania', [App\Http\Controllers\TodayController::class, 'tasks'])->name('today.tasks');
    Route::get('/tydzien', [App\Http\Controllers\TodayController::class, 'week'])->name('today.week');
    Route::post('/day/picks', [App\Http\Controllers\TodayController::class, 'storePick'])->name('day.picks.store');
    Route::delete('/day/picks/{dayPick}', [App\Http\Controllers\TodayController::class, 'destroyPick'])->name('day.picks.destroy');
    Route::post('/day/hidden-groups/toggle', [App\Http\Controllers\TodayController::class, 'toggleHiddenGroup'])->name('day.hidden.toggle');
    Route::post('/day/hidden-groups/client', [App\Http\Controllers\TodayController::class, 'toggleHiddenClient'])->name('day.hidden.client');
    Route::delete('/day/hidden-groups', [App\Http\Controllers\TodayController::class, 'restoreHiddenGroups'])->name('day.hidden.restore');
    Route::delete('/day/picks/{dayPick}/carried', [App\Http\Controllers\TodayController::class, 'dismissCarried'])->name('day.picks.dismiss');
    Route::post('/day/picks/{dayPick}/complete', [App\Http\Controllers\TodayController::class, 'completePick'])->name('day.picks.complete');
    Route::post('/day/tasks/{task}/complete', [App\Http\Controllers\TodayController::class, 'completeTask'])->name('day.tasks.complete');
    Route::delete('/day/tasks/{task}/complete', [App\Http\Controllers\TodayController::class, 'uncompleteTask'])->name('day.tasks.uncomplete');
    Route::post('/day/close', [App\Http\Controllers\TodayController::class, 'close'])->name('day.close');
    Route::delete('/day/close', [App\Http\Controllers\TodayController::class, 'reopen'])->name('day.reopen');

    Route::resource('users', UsersController::class)->only(['store', 'update', 'destroy'])->middlewareFor('update', 'throttle:password-change');
    // Users live in Ustawienia · Konto; the old pages' URLs land there.
    foreach (['users', 'users/create', 'users/{user}/edit'] as $old) {
        Route::get($old, RedirectController::class)->defaults('destination', '/settings/konto')->defaults('status', 302);
    }
    Route::put('users/{user}/restore', [UsersController::class, 'restore'])->name('users.restore');

    // Revenue analytics (Infakt-sourced)
    Route::get('revenue', [RevenueController::class, 'index'])->middleware('feature:billing')->name('revenue.index');

    // Lead-gen funnels (config/leadgen.php — vault plan section 05)
    Route::resource('leads', App\Http\Controllers\LeadsController::class)->except(['show']);
    Route::put('leads/{lead}/restore', [App\Http\Controllers\LeadsController::class, 'restore'])->name('leads.restore');
    Route::patch('leads/{lead}/stage', [App\Http\Controllers\LeadsController::class, 'transitionStage'])->name('leads.transition-stage');
    Route::delete('lead-events/{event}', [App\Http\Controllers\LeadsController::class, 'destroyEvent'])->name('lead-events.destroy');
    Route::post('leads/{lead}/convert', [App\Http\Controllers\LeadsController::class, 'convertToClient'])->name('leads.convert');

    Route::resource('clients', ClientsController::class)->except(['show']);
    Route::put('clients/{client}/restore', [ClientsController::class, 'restore'])->name('clients.restore');
    Route::get('clients/{client}', [ClientsController::class, 'record'])->name('clients.record');
    Route::get('clients/{client}/agent-context', [ClientsController::class, 'agentContext'])->name('clients.agent-context');
    Route::get('clients/{client}/{tab}', [ClientsController::class, 'show'])->whereIn('tab', ClientsController::TABS)->name('clients.show');
    Route::put('clients/{client}/pin', [ClientsController::class, 'pin'])->name('clients.pin');
    Route::patch('clients/{client}/fields', [App\Http\Controllers\ClientFieldValuesController::class, 'update'])->name('clients.fields.update');
    Route::get('sms', [App\Http\Controllers\MessagesController::class, 'index'])->name('messages.index');
    Route::post('messages/{message}/cancel', [App\Http\Controllers\MessagesController::class, 'cancel'])->whereNumber('message')->name('messages.cancel');
    Route::post('messages/{message}/resend', [App\Http\Controllers\MessagesController::class, 'resend'])->whereNumber('message')->name('messages.resend');
    Route::patch('clients/{client}/stage', [ClientsController::class, 'transitionStage'])->name('clients.transition-stage');
    Route::patch('clients/{client}/month-close-type', [ClientsController::class, 'updateMonthCloseType'])->middleware('feature:billing')->name('clients.month-close-type');
    Route::patch('clients/{client}/report-mode', [ClientsController::class, 'updateReportMode'])->middleware('feature:billing')->name('clients.report-mode');
    Route::put('clients/{client}/report-baseline', [ClientsController::class, 'updateReportBaseline'])->middleware('feature:billing')->name('clients.update-report-baseline');
    Route::post('clients/{client}/lifecycle-events', [App\Http\Controllers\ClientLifecycleEventsController::class, 'store'])->name('lifecycle-events.store');
    Route::patch('lifecycle-events/{event}/note', [App\Http\Controllers\ClientLifecycleEventsController::class, 'updateNote'])->name('lifecycle-events.update-note');

    // Client documents (signed contracts, GDPR clauses — stored locally).
    Route::post('clients/{client}/documents', [App\Http\Controllers\ClientDocumentsController::class, 'store'])->middleware('throttle:uploads')->name('client-documents.store');
    Route::get('clients/{client}/documents/{document}', [App\Http\Controllers\ClientDocumentsController::class, 'show'])->name('client-documents.show');
    Route::delete('clients/{client}/documents/{document}', [App\Http\Controllers\ClientDocumentsController::class, 'destroy'])->name('client-documents.destroy');

    Route::middleware('feature:billing')->group(function (): void {
        // Financial month close: freezes one client month in the hours bank.
        Route::get('clients/{client}/months/{period}/close', [App\Http\Controllers\ClientMonthsController::class, 'preview'])->name('client-months.preview');
        Route::post('clients/{client}/months/{period}/close', [App\Http\Controllers\ClientMonthsController::class, 'close'])->name('client-months.close');
        Route::delete('clients/{client}/months/{period}/close', [App\Http\Controllers\ClientMonthsController::class, 'reopen'])->name('client-months.reopen');

        // Client retainers (contracted hours, periodized).
        Route::post('clients/{client}/retainers', [App\Http\Controllers\ClientRetainersController::class, 'store'])->name('client-retainers.store');
        Route::put('clients/{client}/retainers/{retainer}', [App\Http\Controllers\ClientRetainersController::class, 'update'])->name('client-retainers.update');
        Route::delete('clients/{client}/retainers/{retainer}', [App\Http\Controllers\ClientRetainersController::class, 'destroy'])->name('client-retainers.destroy');

        // Client reports (weekly/monthly retainer reports — draft → finalize flow).
        Route::post('clients/{client}/reports', [App\Http\Controllers\ClientReportsController::class, 'store'])->name('client-reports.store');
        Route::get('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'edit'])->name('client-reports.edit');
        Route::put('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'update'])->name('client-reports.update');
        Route::patch('clients/{client}/reports/{report}/opening-balance', [App\Http\Controllers\ClientReportsController::class, 'updateOpeningBalance'])->name('client-reports.opening-balance');
        Route::post('clients/{client}/reports/{report}/regenerate', [App\Http\Controllers\ClientReportsController::class, 'regenerate'])->name('client-reports.regenerate');
        Route::post('clients/{client}/reports/{report}/finalize', [App\Http\Controllers\ClientReportsController::class, 'finalize'])->name('client-reports.finalize');
        Route::post('clients/{client}/reports/{report}/mark-sent', [App\Http\Controllers\ClientReportsController::class, 'markSent'])->name('client-reports.mark-sent');
        Route::post('clients/{client}/reports/{report}/reopen', [App\Http\Controllers\ClientReportsController::class, 'reopen'])->name('client-reports.reopen');
        Route::delete('clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportsController::class, 'destroy'])->name('client-reports.destroy');

        // Month close (per-maintained-client monthly checklist worklist).
        Route::get('month-close', [App\Http\Controllers\MonthCloseController::class, 'index'])->name('month-close.index');
        Route::post('month-close', [App\Http\Controllers\MonthCloseController::class, 'store'])->name('month-close.store');
        Route::patch('month-close/steps/{step}', [App\Http\Controllers\MonthCloseController::class, 'updateStep'])->name('month-close.update-step');
    });

    Route::resource('contacts', ContactsController::class)->except(['show']);
    // Create and edit are the dialog on the list; their old URLs open it there.
    Route::put('contacts/{contact}/restore', [ContactsController::class, 'restore'])->name('contacts.restore');

    // Projects (private projects are first-class; Trello board attached via connect-trello)
    Route::post('clients/{client}/projects', [ProjectsController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectsController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('projects.destroy');
    Route::middleware('not-in-demo')->group(function () {
        Route::post('projects/{project}/connect-trello', [ProjectsController::class, 'connectTrello'])->name('projects.connect-trello');
        Route::post('projects/{project}/disconnect-trello', [ProjectsController::class, 'disconnectTrello'])->name('projects.disconnect-trello');
        Route::post('projects/{project}/sync-trello', [ProjectsController::class, 'syncTrello'])->name('projects.sync-trello')->middleware('throttle:5,1');
        Route::put('projects/{project}/trello-list-mapping', [ProjectsController::class, 'updateTrelloListMapping'])->name('projects.trello-list-mapping');
        Route::get('projects/{project}/available-trello-boards', [ProjectsController::class, 'availableTrelloBoards'])->name('projects.available-trello-boards');
    });

    // Repositories (linked to projects): paths an outside agent reads from `crm task`.
    Route::post('projects/{project}/repositories', [RepositoriesController::class, 'store'])->name('repositories.store');
    Route::put('repositories/{repository}', [RepositoriesController::class, 'update'])->name('repositories.update');
    Route::delete('repositories/{repository}', [RepositoriesController::class, 'destroy'])->name('repositories.destroy');

    Route::post('projects/{project}/tasks', [TasksController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{task}', [TasksController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TasksController::class, 'destroy'])->name('tasks.destroy');

    Route::patch('/tasks/{task}/list-name', [TasksController::class, 'updateListName'])->name('tasks.list-name');
    Route::patch('/tasks/{task}/archived', [TasksController::class, 'updateArchived'])->name('tasks.archived');
    Route::patch('/tasks/{task}/reportable', [TasksController::class, 'updateReportable'])->name('tasks.reportable');
    Route::patch('/tasks/{task}/description', [TasksController::class, 'updateDescription'])->name('tasks.description');
    Route::put('/tasks/{task}/brief', [TaskBriefController::class, 'update'])->name('tasks.brief');
    Route::post('/tasks/{task}/links', [TaskLinksController::class, 'store'])->name('task-links.store');
    Route::delete('/task-links/{link}', [TaskLinksController::class, 'destroy'])->name('task-links.destroy');

    // Task attachments (manual uploads, listed by `crm task` for an agent)
    Route::get('/tasks/{task}/attachments', [TaskAttachmentsController::class, 'index'])->name('task-attachments.index');
    Route::post('/tasks/{task}/attachments', [TaskAttachmentsController::class, 'store'])->middleware('throttle:uploads')->name('task-attachments.store');
    Route::get('/task-attachments/{attachment}', [TaskAttachmentsController::class, 'show'])
        ->name('task-attachments.show')
        ->where('attachment', '[0-9]+');
    Route::delete('/task-attachments/{attachment}', [TaskAttachmentsController::class, 'destroy'])->name('task-attachments.destroy');

    // Manual time entries.
    Route::get('/time-entries/running', [TimeEntriesController::class, 'running'])->name('time-entries.running');
    Route::post('/tasks/{task}/time-entries', [TimeEntriesController::class, 'store'])->name('time-entries.store');
    Route::post('/tasks/{task}/time-entries/start', [TimeEntriesController::class, 'start'])->name('time-entries.start');
    Route::post('/time-entries/{timeEntry}/stop', [TimeEntriesController::class, 'stop'])->name('time-entries.stop');
    Route::put('/time-entries/{timeEntry}', [TimeEntriesController::class, 'update'])->name('time-entries.update');
    Route::delete('/time-entries/{timeEntry}', [TimeEntriesController::class, 'destroy'])->name('time-entries.destroy');

    // Per-project boards + task detail. The plain board is where manual drag
    // lives; the Work tab only lists projects.
    Route::get('/clients/{client}/projects/{project}', [ProjectBoardController::class, 'show'])->name('project-board.show');
    Route::get('/tasks/{task}', [TasksController::class, 'show'])->name('tasks.show');
    Route::get('/search', App\Http\Controllers\SearchController::class)->name('search');

    // Settings hub — config defaults + per-account overrides (settings table)
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/{tab}', [SettingsController::class, 'show'])->whereIn('tab', SettingsController::TABS)->name('settings.show');
    Route::middleware('not-in-demo')->group(function () {
        Route::post('/settings/agent-tokens', [AgentTokensController::class, 'store'])->middleware('throttle:password-change')->name('agent-tokens.store');
        Route::patch('/settings/agent-tokens/{token}', [AgentTokensController::class, 'update'])->whereNumber('token')->name('agent-tokens.update');
        Route::delete('/settings/agent-tokens/{token}', [AgentTokensController::class, 'destroy'])->whereNumber('token')->name('agent-tokens.destroy');
    });
    Route::patch('/settings/leadgen', [SettingsController::class, 'updateLeadgen'])->name('settings.leadgen.update');
    Route::put('/settings/client-fields/{key}', [App\Http\Controllers\Settings\ClientFieldDefinitionsController::class, 'update'])->name('settings.client-fields.update');
    Route::post('/settings/sms/templates', [App\Http\Controllers\Settings\MessageSettingsController::class, 'storeTemplate'])->name('settings.sms.templates.store');
    Route::put('/settings/sms/templates/{key}', [App\Http\Controllers\Settings\MessageSettingsController::class, 'updateTemplate'])->name('settings.sms.templates.update');
    Route::delete('/settings/sms/templates/{key}', [App\Http\Controllers\Settings\MessageSettingsController::class, 'destroyTemplate'])->name('settings.sms.templates.destroy');
    Route::post('/settings/sms/rules', [App\Http\Controllers\Settings\MessageSettingsController::class, 'storeRule'])->name('settings.sms.rules.store');
    Route::put('/settings/sms/rules/{key}', [App\Http\Controllers\Settings\MessageSettingsController::class, 'updateRule'])->name('settings.sms.rules.update');
    Route::delete('/settings/sms/rules/{key}', [App\Http\Controllers\Settings\MessageSettingsController::class, 'destroyRule'])->name('settings.sms.rules.destroy');
    Route::patch('/settings/sms/sending', [App\Http\Controllers\Settings\MessageSettingsController::class, 'sending'])->name('settings.sms.sending');
    Route::post('/settings/sms/preview', [App\Http\Controllers\Settings\MessageSettingsController::class, 'preview'])->name('settings.sms.preview');

    // Integrations live in Ustawienia · Integracje; on the demo that tab says they are not part of it.
    Route::get('integrations', RedirectController::class)->defaults('destination', '/settings/integracje')->defaults('status', 302);
    Route::middleware('not-in-demo')->group(function () {
        Route::get('integrations/{provider}', RedirectController::class)->defaults('destination', '/settings/integracje')->defaults('status', 302);
        Route::put('/integrations/{provider}', [IntegrationsController::class, 'update'])->name('integrations.update');
        Route::post('/integrations/{provider}/sync', [IntegrationsController::class, 'sync'])->name('integrations.sync');
        Route::delete('/integrations/{provider}', [IntegrationsController::class, 'disconnect'])->name('integrations.disconnect');
    });
});
