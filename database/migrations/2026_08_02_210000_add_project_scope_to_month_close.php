<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'month_close_steps_month_close_run_id_step_key_unique';

    private const NEW_UNIQUE = 'month_close_steps_run_project_step_unique';

    private const POSITION_INDEX = 'month_close_steps_run_project_position_index';

    public function up(): void
    {
        // Which projects are sites in the monthly close. Explicit rather than
        // inferred from "has a repository": it is what keeps an unreleased
        // rebuild out of the worklist and lets a site be parked without
        // deleting rows. Mirrors clients.include_in_month_close.
        if (! Schema::hasColumn('projects', 'include_in_month_close')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->boolean('include_in_month_close')->default(false)->after('is_inbox');
            });
        }

        // A run stays per client, but its site work repeats per site: the six
        // site steps carry a project_id, the client-level steps (reconcile,
        // report, invoice) keep it null because they genuinely happen once.
        // unsignedInteger, not foreignId — projects.id is legacy int(10) unsigned.
        if (! Schema::hasColumn('month_close_steps', 'project_id')) {
            Schema::table('month_close_steps', function (Blueprint $table) {
                $table->unsignedInteger('project_id')->nullable()->after('month_close_run_id');
            });
        }

        // Order matters: the old unique index is the one the month_close_run_id
        // foreign key is resolved against, so MariaDB refuses to drop it while
        // it is the only index leading with that column. Create the replacement
        // first (it also leads with month_close_run_id), then drop the old one.
        if (! Schema::hasIndex('month_close_steps', self::NEW_UNIQUE)) {
            Schema::table('month_close_steps', function (Blueprint $table) {
                $table->unique(['month_close_run_id', 'project_id', 'step_key'], self::NEW_UNIQUE);
            });
        }

        if (Schema::hasIndex('month_close_steps', self::OLD_UNIQUE)) {
            Schema::table('month_close_steps', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! Schema::hasIndex('month_close_steps', self::POSITION_INDEX)) {
            Schema::table('month_close_steps', function (Blueprint $table) {
                $table->index(['month_close_run_id', 'project_id', 'position'], self::POSITION_INDEX);
            });
        }

        if (! Schema::hasIndex('month_close_steps', 'month_close_steps_project_id_foreign')) {
            Schema::table('month_close_steps', function (Blueprint $table) {
                $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('month_close_steps', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(self::POSITION_INDEX);
            // Same ordering constraint in reverse: restore the old unique before
            // dropping the one that currently backs the foreign key.
            $table->unique(['month_close_run_id', 'step_key'], self::OLD_UNIQUE);
            $table->dropUnique(self::NEW_UNIQUE);
            $table->dropColumn('project_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('include_in_month_close');
        });
    }
};
