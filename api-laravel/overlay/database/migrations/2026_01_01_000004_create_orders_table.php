<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('original_amount');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('gross_amount');
            $table->string('status')->default('pending'); // pending | paid | failed
            $table->string('snap_token')->nullable();
            $table->text('redirect_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
