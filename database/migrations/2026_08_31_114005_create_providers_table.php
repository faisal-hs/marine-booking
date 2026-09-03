<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->string('business_license')->nullable();
            $table->text('bio')->nullable();
            $table->string('logo')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
