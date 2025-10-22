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
        Schema::create('ins_ctc_auths', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('actions');

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_ctc_auths');
    }
};