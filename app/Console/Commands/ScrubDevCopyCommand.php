<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AccountScope(AccountScope::OPERATOR)]
#[AsCommand(name: 'dev:scrub', description: 'Delete every account but one, with its rows and files, from a copy of app loaded into dev (DEMO_RESET_ALLOWED only; operator: every account)')]
final class ScrubDevCopyCommand extends Command
{
    /** Emptied whatever account their rows belong to. */
    private const EMPTIED = ['personal_access_tokens', 'sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'];

    protected $signature = 'dev:scrub {--keep-account= : The id of the one account that stays}';

    public function handle(): int
    {
        if (config('app.demo') || ! config('app.demo_reset_allowed')) {
            $this->error('dev:scrub deletes accounts. It only runs on the dev stack: DEMO_RESET_ALLOWED=true and DEMO_MODE=false.');

            return self::FAILURE;
        }

        $keep = (int) $this->option('keep-account');
        if ($keep <= 0 || ! DB::table('accounts')->where('id', $keep)->exists()) {
            $this->error('Name the account that stays with --keep-account=<id> of an existing account.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($keep): void {
                $this->deleteForeignRows($keep);
                $this->assertOnlyAccountLeft($keep);
            });
        } catch (RuntimeException $e) {
            $this->error("dev:scrub stopped, nothing was deleted: {$e->getMessage()}");

            return self::FAILURE;
        }

        [$deleted, $failed] = $this->sweepUploads();
        if ($failed !== []) {
            $this->error('The rows are scrubbed, but these files could not be deleted: '.implode(', ', $failed));

            return self::FAILURE;
        }
        $this->info(sprintf('Kept account %d. Deleted %d files no kept row points to.', $keep, $deleted));

        return self::SUCCESS;
    }

    /**
     * Runs after the commit, so a rolled-back scrub never loses a file.
     *
     * @return array{int, list<string>}
     */
    private function sweepUploads(): array
    {
        $disk = Storage::disk('local');
        $kept = DB::table('task_attachments')->pluck('file_path')
            ->merge(DB::table('client_documents')->pluck('file_path'))
            ->filter()->flip();

        $deleted = 0;
        $failed = [];
        foreach (ResetDemoCommand::UPLOAD_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                if ($kept->has($path)) {
                    continue;
                }
                if ($disk->delete($path)) {
                    $deleted++;
                } else {
                    $failed[] = $path;
                }
            }
            foreach (array_reverse($disk->allDirectories($directory)) as $folder) {
                if ($disk->allFiles($folder) === []) {
                    $disk->deleteDirectory($folder);
                }
            }
        }

        return [$deleted, $failed];
    }

    private function deleteForeignRows(int $keep): void
    {
        // Tasks and repositories carry no account_id and their project_id has no foreign key, so a task of a deleted project would survive any cascade.
        DB::table('task_attachments')->whereIn('task_id', $this->foreignTasks($keep))->delete();
        DB::table('tasks')->whereNotIn('project_id', $this->keptProjects($keep))->delete();
        DB::table('repositories')->whereNotIn('project_id', $this->keptProjects($keep))->delete();

        // message_rules.template_id restricts deleting a template that a rule still uses.
        DB::table('message_rules')->where('account_id', '!=', $keep)->delete();

        foreach ($this->tablesWithAccountId() as $table) {
            DB::table($table)->where('account_id', '!=', $keep)->delete();
        }
        DB::table('accounts')->where('id', '!=', $keep)->delete();

        foreach (self::EMPTIED as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('integrations')->update(['is_enabled' => false, 'api_key' => null]);
    }

    private function assertOnlyAccountLeft(int $keep): void
    {
        $left = [];
        foreach ($this->tablesWithAccountId() as $table) {
            if (DB::table($table)->where('account_id', '!=', $keep)->exists()) {
                $left[] = $table;
            }
        }
        if (DB::table('accounts')->where('id', '!=', $keep)->exists()) {
            $left[] = 'accounts';
        }
        if (DB::table('tasks')->whereIn('id', $this->foreignTasks($keep))->exists()) {
            $left[] = 'tasks';
        }
        if (DB::table('task_attachments')->whereNotIn('task_id', DB::table('tasks')->select('id'))->exists()) {
            $left[] = 'task_attachments';
        }

        if ($left !== []) {
            throw new RuntimeException('rows of another account are left in '.implode(', ', $left).'.');
        }
    }

    private function foreignTasks(int $keep): Builder
    {
        return DB::table('tasks')->select('id')->whereNotIn('project_id', $this->keptProjects($keep));
    }

    private function keptProjects(int $keep): Builder
    {
        return DB::table('projects')->select('id')->where('account_id', $keep);
    }

    /** @return list<string> */
    private function tablesWithAccountId(): array
    {
        return array_values(array_filter(
            Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false),
            fn (string $table): bool => $table !== 'accounts' && Schema::hasColumn($table, 'account_id'),
        ));
    }
}
