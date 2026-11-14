<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A draft records where it came from (the retainer, or a copy of one Infakt invoice) and the projects of its invoice
 * group, so next month's copy of that group starts from it. Copies used to be recorded as group 0; one moves to its
 * real group only when that month had a single fee-bearing group and nothing is recorded there. The rest stay as
 * history, and their month needs a pick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->string('source', 16)->default('retainer')->after('invoice_group');
            $table->string('source_invoice_uuid', 64)->nullable()->after('source');
            $table->string('source_invoice_number', 64)->nullable()->after('source_invoice_uuid');
            $table->json('project_ids')->nullable()->after('source_invoice_number');
        });

        DB::table('infakt_draft_requests')->where('invoice_group', 0)->update(['source' => 'copy']);

        foreach (DB::table('infakt_draft_requests')->orderBy('id')->get(['id', 'client_id', 'period', 'invoice_group']) as $row) {
            $groups = $this->groupsOn((int) $row->client_id, (string) $row->period);
            $group = (int) $row->invoice_group;

            if ($group === 0) {
                $only = count($groups) === 1 ? array_key_first($groups) : null;
                $taken = $only !== null && DB::table('infakt_draft_requests')
                    ->where('client_id', $row->client_id)->where('period', $row->period)->where('invoice_group', $only)->exists();
                if ($only === null || $taken) {
                    continue;
                }
                DB::table('infakt_draft_requests')->where('id', $row->id)->update(['invoice_group' => $only, 'project_ids' => json_encode($groups[$only])]);

                continue;
            }

            if (isset($groups[$group])) {
                DB::table('infakt_draft_requests')->where('id', $row->id)->update(['project_ids' => json_encode($groups[$group])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_invoice_uuid', 'source_invoice_number', 'project_ids']);
        });
    }

    /**
     * The fee-bearing groups on the month's sale date and their projects, as InfaktService groups positions.
     *
     * @return array<int, list<int>>
     */
    private function groupsOn(int $clientId, string $period): array
    {
        $saleDate = Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();
        $groups = [];
        $positions = DB::table('client_retainers')->where('client_id', $clientId)->where('is_active', true)
            ->where('monthly_fee', '>', 0)->whereDate('effective_from', '<=', $saleDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $saleDate))
            ->get(['invoice_group', 'project_id']);
        foreach ($positions as $position) {
            $groups[(int) $position->invoice_group] ??= [];
            if ($position->project_id !== null) {
                $groups[(int) $position->invoice_group][] = (int) $position->project_id;
            }
        }

        return array_map(function (array $ids): array {
            $ids = array_values(array_unique($ids));
            sort($ids);

            return $ids;
        }, $groups);
    }
};
