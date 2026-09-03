<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit log for client lifecycle transitions.
        // `from_stage` null on the first event for a client. Same-stage events
        // are allowed when a note is attached (general timeline notes).
        // dropIfExists is defensive — recovers from a partial dev run.
        Schema::dropIfExists('client_lifecycle_events');
        // Existing tables (accounts, clients, users) use int(10) unsigned for id —
        // match the type explicitly so the foreign-key constraint is well-formed.
        Schema::create('client_lifecycle_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['client_id', 'created_at']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Backfill one event per existing client so the Activity timeline
        // is not empty for relationships that pre-date the events table.
        $clients = DB::table('clients')->get(['id', 'account_id', 'lifecycle_stage', 'lifecycle_stage_changed_at']);
        $rows = [];
        foreach ($clients as $client) {
            $rows[] = [
                'account_id' => $client->account_id,
                'client_id' => $client->id,
                'user_id' => null,
                'from_stage' => null,
                'to_stage' => $client->lifecycle_stage ?? 'prospect',
                'note' => null,
                'created_at' => $client->lifecycle_stage_changed_at ?? now(),
            ];
        }
        if ($rows !== []) {
            // chunk to keep MariaDB packet size sane on accounts with many clients
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('client_lifecycle_events')->insert($chunk);
            }
        }

        // Latest-event timestamp is now authoritative — drop the denorm column.
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('lifecycle_stage_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('lifecycle_stage_changed_at')->nullable()->after('lifecycle_stage');
        });

        Schema::dropIfExists('client_lifecycle_events');
    }
};
