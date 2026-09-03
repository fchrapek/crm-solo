<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client-scoped documents (contracts, GDPR clauses, agreements). Files
        // live LOCALLY under storage/app/private/client-documents/{client_id}/.
        // Mirrors task_attachments; account-scoped via the client chain.
        Schema::create('client_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id')->index();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('category')->nullable(); // e.g. umowa / rodo / podpowierzenie
            $table->string('label')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
    }
};
