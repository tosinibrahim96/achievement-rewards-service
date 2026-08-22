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
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('external_reference')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('completed_at');
            $table->ulid('correlation_id');
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index(['user_id', 'currency']);
            $table->index('correlation_id');
        });

        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_amount_minor_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_currency_check CHECK (currency = 'NGN')");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
