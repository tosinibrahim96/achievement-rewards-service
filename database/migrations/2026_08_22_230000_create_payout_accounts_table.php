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
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_recipient_code');
            $table->string('bank_code', 3);
            $table->string('bank_name');
            $table->string('account_name');
            $table->char('account_last_four', 4);
            $table->char('currency', 3);
            $table->timestampTz('verified_at');
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_recipient_code'],
                'payout_accounts_provider_recipient_unique',
            );
        });

        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_provider_check CHECK (provider IN ('fake', 'paystack'))");
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_bank_code_check CHECK (bank_code ~ '^[0-9]{3}$')");
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_last_four_check CHECK (account_last_four ~ '^[0-9]{4}$')");
        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_currency_check CHECK (currency = 'NGN')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
