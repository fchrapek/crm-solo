<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit log for lead stage moves — the mirror of
        // client_lifecycle_events. THIS TABLE IS THE MEASUREMENT: the Month-2
        // checkpoint reads stage-move timestamps from here to replace the
        // plan's assumed conversion rates with measured ones.
        //
        // `from_stage` is null on the capture event (null -> entry_stage). That
        // is a creation, not a transition, and must stay distinguishable: a
        // synthetic hop would fabricate a conversion the funnel never made.
        // dropIfExists is defensive — recovers from a partial dev run.
        Schema::dropIfExists('lead_stage_events');
        // Existing tables (accounts, users) use int(10) unsigned for id —
        // match the type explicitly so the foreign-key constraint is well-formed.
        Schema::create('lead_stage_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('lead_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['lead_id', 'created_at']);
            // Conversion-rate reads scan by stage hop across a date range.
            $table->index(['account_id', 'to_stage', 'created_at']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_stage_events');
    }
};
