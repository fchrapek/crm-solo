<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What readiness needs from a card's cached details, kept beside the JSON so
 * a task list reads two small columns instead of every checklist and comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_card_details', function (Blueprint $table): void {
            $table->unsignedInteger('checklist_items')->default(0)->after('card_attachments');
            $table->boolean('has_link_attachment')->default(false)->after('checklist_items');
        });

        DB::table('task_card_details')->orderBy('id')->each(function (object $row): void {
            $checklists = json_decode((string) $row->checklists, true);
            $attachments = json_decode((string) $row->card_attachments, true);
            DB::table('task_card_details')->where('id', $row->id)->update([
                'checklist_items' => collect(is_array($checklists) ? $checklists : [])->sum(fn ($c): int => is_array($c) && is_array($c['items'] ?? null) ? count($c['items']) : 0),
                'has_link_attachment' => collect(is_array($attachments) ? $attachments : [])->contains(fn ($a): bool => is_array($a) && ($a['status'] ?? null) === 'link' && is_string($a['url'] ?? null)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('task_card_details', function (Blueprint $table): void {
            $table->dropColumn(['checklist_items', 'has_link_attachment']);
        });
    }
};
