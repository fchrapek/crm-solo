<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Literal, never the clock: the month financial settlement starts for accounts that existed when it shipped.
    private const string BASELINE = '2026-09';

    public function up(): void
    {
        foreach (DB::table('accounts')->get(['id', 'settings']) as $account) {
            $settings = json_decode((string) $account->settings, true);
            $settings = is_array($settings) ? $settings : [];
            if (isset($settings['settlement_from'])) {
                continue;
            }

            $settings['settlement_from'] = self::BASELINE;
            DB::table('accounts')->where('id', $account->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('accounts')->get(['id', 'settings']) as $account) {
            $settings = json_decode((string) $account->settings, true);
            if (! is_array($settings) || ($settings['settlement_from'] ?? null) !== self::BASELINE) {
                continue;
            }

            unset($settings['settlement_from']);
            DB::table('accounts')->where('id', $account->id)->update(['settings' => $settings === [] ? null : json_encode($settings)]);
        }
    }
};
