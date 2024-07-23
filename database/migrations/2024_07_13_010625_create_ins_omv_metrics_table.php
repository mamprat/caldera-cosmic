<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ins_omv_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('ins_omv_recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('user_1_id');
            $table->unsignedBigInteger('user_2_id');
            $table->foreign('user_1_id')->references('id')->on('users');
            $table->foreign('user_2_id')->references('id')->on('users');
            $table->enum('eval', ['too_early', 'on_time', 'too_late']);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('type', ['new', 'remixing', 'scrap']);
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_omv_metrics');
    }
};
