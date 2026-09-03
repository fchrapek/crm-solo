<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lead-gen funnel rows for the two brand pipelines (config/leadgen.php).
        // Distinct from Client: clients are heavyweight (NIP, Infakt, retainers)
        // and both funnels operate entirely *before* anything becomes a client.
        // `client_id` is the conversion seam, set when a lead is won.
        //
        // No enum columns: pipeline/source/stage are plain strings validated
        // against config('leadgen.*') so the plan and the code cannot drift.
        // dropIfExists is defensive — recovers from a partial dev run.
        Schema::dropIfExists('leads');
        // Existing tables (accounts, clients, users) use int(10) unsigned for id —
        // match the type explicitly so the foreign-key constraint is well-formed.
        Schema::create('leads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('pipeline', 32);
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            // "No source tag -> the channel doesn't exist" — set at capture,
            // immutable afterwards (enforced in the model, not the DB, so the
            // rule is testable and carries a readable error).
            $table->string('source', 32);
            $table->string('stage', 32);
            $table->unsignedInteger('client_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'pipeline', 'stage']);
            $table->index(['account_id', 'source']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
