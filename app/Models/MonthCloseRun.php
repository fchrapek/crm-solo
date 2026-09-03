<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MonthCloseRun extends Model
{
    public const string STATUS_OPEN = 'open';

    public const string STATUS_COMPLETED = 'completed';

    public const string TYPE_MAINTENANCE = 'maintenance';

    public const string TYPE_GIG = 'gig';

    public const string REPORT_MODE_REPORT = 'report';

    public const string REPORT_MODE_SUMMARY_EMAIL = 'summary_email';

    public const string REPORT_MODE_NONE = 'none';

    /**
     * Placeholder step key in the templates whose concrete identity depends on
     * the client's report_mode (full report vs summary email vs nothing).
     */
    public const string DELIVERABLE_STEP = 'report';

    /**
     * Steps that repeat once per site. Keys are stable identifiers (labels +
     * hints live in i18n); the bool is whether the step is optional ("it
     * depends"), presented as skip-friendly in the UI.
     *
     * The order is the actual working order: the production dump is filed into
     * the vault, imported locally, updated, verified, committed. Publishing to
     * live is the user's own step, hence optional from the CRM's side.
     *
     * @var array<string, bool>
     */
    public const SITE_STEPS = [
        'db_archived' => false,
        // Optional: the local copy only needs the production database when the
        // month's work actually depends on current data. Plugin and core
        // updates do not, so a stale local DB is often fine.
        'local_db_import' => true,
        'wp_updates' => false,
        'local_verify' => false,
        'commit_merge' => false,
        'live_deploy' => true,
    ];

    /**
     * Steps that happen once per client no matter how many sites it runs — the
     * session log, the deliverable and the invoice are per relationship, not
     * per site. `gig` clients (no site under contract) get only these.
     *
     * @var array<string, bool>
     */
    public const CLIENT_STEPS = [
        'reconcile_log' => false,
        'report' => false,
        'draft_invoice' => false,
    ];

    /**
     * Step keys seeded before the close went per site (June 2026 runs). Kept so
     * historical runs still render; nothing seeds them any more.
     *
     * @var list<string>
     */
    public const LEGACY_STEP_KEYS = ['live_check', 'db_dump', 'full_site_copy'];

    protected $fillable = [
        'account_id',
        'client_id',
        'period',
        'close_type',
        'report_mode',
        'status',
        'completed_at',
    ];

    /**
     * The checklist a client would get if its close were started now: the site
     * steps once per site, then the client steps. Drives the "Start close · N"
     * count and the not-started view of a period, so both agree with what
     * seedSteps() will actually write.
     *
     * @return list<array{key: string, optional: bool, project_id: int|null, project: string|null}>
     */
    public static function stepTemplateFor(Client $client, ?string $closeType = null, ?string $reportMode = null): array
    {
        $closeType ??= $client->month_close_type;
        $reportMode ??= $client->report_mode;
        $template = [];

        if ($closeType === self::TYPE_MAINTENANCE) {
            foreach ($client->monthCloseSites as $site) {
                foreach (self::SITE_STEPS as $key => $optional) {
                    $template[] = [
                        'key' => $key,
                        'optional' => $optional,
                        'project_id' => (int) $site->id,
                        'project' => (string) $site->name,
                    ];
                }
            }
        }

        foreach (self::CLIENT_STEPS as $key => $optional) {
            $resolved = self::resolveDeliverableKey($key, $reportMode);

            if ($resolved === null) {
                continue;
            }

            $template[] = ['key' => $resolved, 'optional' => $optional, 'project_id' => null, 'project' => null];
        }

        return $template;
    }

    /**
     * Get-or-create the run for a client/period, seeding its checklist from the
     * client's cohort + report_mode on first creation. Single source of truth
     * for "start a close" — used by the controller and the CLI command.
     */
    public static function startFor(Client $client, string $period): self
    {
        $run = $client->monthCloseRuns()->firstOrCreate(
            ['period' => $period],
            [
                'account_id' => $client->account_id,
                'close_type' => $client->month_close_type,
                'report_mode' => $client->report_mode,
                'status' => self::STATUS_OPEN,
            ],
        );

        if ($run->wasRecentlyCreated) {
            $run->seedSteps();
        }

        return $run;
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->firstOrFail();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MonthCloseStep::class)->orderBy('position');
    }

    /**
     * Seed the checklist from the template. Called once right after create.
     *
     * A maintenance client with no site flagged into the close still gets its
     * client steps — the run is then just the reconcile/report/invoice tail,
     * which is the honest picture rather than an empty checklist.
     */
    public function seedSteps(): void
    {
        $position = 0;

        // Built from the run's own snapshot, so retagging the client later never
        // rewrites a past run's checklist.
        foreach (self::stepTemplateFor($this->client, $this->close_type, $this->report_mode) as $step) {
            $this->steps()->create([
                'account_id' => $this->account_id,
                'project_id' => $step['project_id'],
                'step_key' => $step['key'],
                'position' => $position++,
                'state' => MonthCloseStep::STATE_PENDING,
            ]);
        }
    }

    /**
     * A run is completed once no step is still pending (every step is either
     * done or skipped). Recomputed after each step toggle.
     */
    public function refreshStatusFromSteps(): void
    {
        $hasPending = $this->steps()
            ->where('state', MonthCloseStep::STATE_PENDING)
            ->exists();

        $status = $hasPending ? self::STATUS_OPEN : self::STATUS_COMPLETED;

        if ($this->status !== $status) {
            $this->status = $status;
            $this->completed_at = $status === self::STATUS_COMPLETED ? now() : null;
            $this->save();
        }
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Swap the deliverable placeholder for the concrete step the client's
     * report_mode calls for (or null to drop it). All other steps pass through.
     */
    private static function resolveDeliverableKey(string $key, ?string $reportMode): ?string
    {
        if ($key !== self::DELIVERABLE_STEP) {
            return $key;
        }

        return match ($reportMode) {
            self::REPORT_MODE_SUMMARY_EMAIL => self::REPORT_MODE_SUMMARY_EMAIL,
            self::REPORT_MODE_NONE => null,
            default => self::DELIVERABLE_STEP,
        };
    }
}
