<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Client::query()
            ->whereDoesntHave('projects', fn ($q) => $q->whereNull('trello_board_id'))
            ->each(function (Client $client) {
                Project::create([
                    'account_id' => $client->account_id,
                    'client_id' => $client->id,
                    'name' => 'General',
                ]);
            });
    }

    public function down(): void
    {
        // Intentionally not destructive — General projects may have manual tasks
        // or have been renamed/repurposed by users.
    }
};
