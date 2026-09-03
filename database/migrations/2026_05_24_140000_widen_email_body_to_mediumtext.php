<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // body_text was TEXT (~64KB MariaDB UTF-8 max). Real emails with
        // long quoted history + base64-inline images blow past that, and
        // every too-long row got silently dropped in the per-client sync
        // ("Data too long for column 'body_text'" SQLSTATE 22001 warnings).
        // MEDIUMTEXT (~16MB) covers every email body we'd ever realistically
        // sync; LONGTEXT (4GB) is overkill.
        Schema::table('emails', function (Blueprint $table) {
            $table->mediumText('body_text')->nullable()->change();
            $table->mediumText('body_text_clean')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->text('body_text')->nullable()->change();
            $table->text('body_text_clean')->nullable()->change();
        });
    }
};
