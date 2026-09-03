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
        // emails = JSON array of strings; first entry is the conventional
        // "primary" address (what UIs display by default). Replaces the
        // legacy `email` single-string column. Backfill below copies the
        // existing email into emails[0] for every contact that had one.
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('emails')->nullable()->after('last_name');
        });

        DB::table('contacts')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    DB::table('contacts')
                        ->where('id', $contact->id)
                        ->update(['emails' => json_encode([$contact->email])]);
                }
            });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email', 50)->nullable()->after('last_name');
        });

        // Restore the first email of each emails[] back into the singular
        // column. Subsequent emails are lost on downgrade — accept the data
        // loss since this column shape was the constraint we're moving away
        // from in the first place.
        DB::table('contacts')
            ->whereNotNull('emails')
            ->orderBy('id')
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $decoded = json_decode((string) $contact->emails, true);
                    if (is_array($decoded) && isset($decoded[0]) && is_string($decoded[0])) {
                        DB::table('contacts')
                            ->where('id', $contact->id)
                            ->update(['email' => $decoded[0]]);
                    }
                }
            });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('emails');
        });
    }
};
