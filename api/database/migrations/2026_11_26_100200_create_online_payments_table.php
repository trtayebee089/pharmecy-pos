<?php

use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->string('payment_method', 20)->default('cod')->after('status'); // cod | sslcommerz | bkash
            // cod | pending (waiting for the gateway) | paid | refund_due (cancelled after paying)
            $t->string('payment_status', 12)->default('cod')->after('payment_method');
            $t->decimal('paid_online', 14, 2)->default(0)->after('payment_status');
        });

        Schema::create('online_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_order_id')->constrained()->restrictOnDelete();
            $t->string('gateway', 20); // sslcommerz | bkash
            $t->decimal('amount', 14, 2);
            $t->string('status', 12)->default('initiated'); // initiated | paid | failed | cancelled
            $t->string('reference', 64)->unique(); // our id sent to the gateway (tran_id / merchantInvoiceNumber)
            $t->string('gateway_id', 120)->nullable(); // SSLCommerz session key / bKash paymentID
            $t->string('transaction_id', 120)->nullable(); // bank_tran_id / trxID once paid
            $t->string('failure_reason')->nullable();
            $t->json('payload')->nullable(); // last gateway answer, for support
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['gateway', 'gateway_id']);
        });

        // Where gateway money is booked. bKash checkout uses the existing bKash method/account.
        if (Schema::hasTable('accounts') && Account::query()->exists()) {
            $ssl = Account::firstOrCreate(['code' => 'SSLCZ'], ['name' => 'SSLCommerz merchant', 'type' => 'bank']);
            PaymentMethod::firstOrCreate(['code' => 'sslcommerz'], ['name' => 'SSLCommerz', 'account_id' => $ssl->id, 'requires_reference' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn(['payment_method', 'payment_status', 'paid_online']);
        });
    }
};
