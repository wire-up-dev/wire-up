<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('manage_token', 64)->unique();
            $table->string('status')->index();
            $table->string('source');
            $table->unsignedBigInteger('record_id')->nullable()->index();
            $table->string('service_name');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->string('locale', 10);
            $table->string('timezone', 64);
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('clashed')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('booking_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->index()->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at')->unique();
        });
    }
};
