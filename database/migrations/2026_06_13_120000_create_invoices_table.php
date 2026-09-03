<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoices mirrored from Infakt for revenue analytics. Monetary values
        // are stored as integer grosze (1/100 of the currency unit), exactly as
        // Infakt's API returns them — no float rounding. client_id is nullable:
        // an invoice whose Infakt client no longer matches a local client (e.g.
        // deleted/churned) is still kept and bucketed as "Other" in reports.
        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id')->nullable();

            // Infakt identity + matching snapshot (so unmatched invoices still
            // show a name/NIP in reports without a local client row).
            $table->string('external_id')->comment('Infakt invoice id');
            $table->string('number')->nullable();
            $table->string('status')->nullable();
            $table->string('client_company_name')->nullable();
            $table->string('client_tax_code')->nullable();

            $table->char('currency', 3)->default('PLN');

            // Amounts in grosze (integer). gross = net + tax.
            $table->bigInteger('net_price')->default(0);
            $table->bigInteger('gross_price')->default(0);
            $table->bigInteger('tax_price')->default(0);
            $table->bigInteger('paid_price')->default(0);
            $table->bigInteger('left_to_pay')->default(0);

            $table->date('invoice_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->date('paid_date')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'external_id']);
            $table->index(['account_id', 'invoice_date']);
            $table->index(['account_id', 'paid_date']);
            $table->index(['client_id', 'invoice_date']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
