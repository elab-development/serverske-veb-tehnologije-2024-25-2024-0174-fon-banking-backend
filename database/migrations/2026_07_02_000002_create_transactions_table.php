<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('recipient_account_id');
            $table->string('recipient_name');
            $table->string('sender_account_id');
            $table->integer('model')->nullable();
            $table->string('reference_number')->nullable();
            $table->decimal('sender_amount', 12, 2);
            $table->string('sender_currency', 3);
            $table->decimal('recipient_amount', 12, 2);
            $table->string('recipient_currency', 3);
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->string('payment_purpose')->nullable();
            $table->string('payment_code')->nullable();
            $table->timestamp('transaction_time')->nullable();
            $table->string('status');
            $table->string('card_number')->nullable();
            $table->timestamps();

            $table->foreign('sender_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('recipient_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('card_number')->references('card_id')->on('cards')->nullOnDelete()->cascadeOnUpdate();
            $table->index('recipient_account_id');
            $table->index('sender_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
