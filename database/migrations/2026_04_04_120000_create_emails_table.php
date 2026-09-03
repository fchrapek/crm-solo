<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->index();
            $table->string('gmail_message_id', 100)->index();
            $table->string('gmail_thread_id', 100)->index();
            $table->string('subject', 500)->nullable();
            $table->text('body_text')->nullable();
            $table->string('from_email', 255);
            $table->string('from_name', 255)->nullable();
            $table->json('to_emails');
            $table->json('cc_emails')->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->timestamp('email_date');
            $table->json('labels')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'gmail_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
