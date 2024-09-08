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
        Schema::create('ins_stc_d_sums', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('ins_stc_device_id')->constrained();
            $table->foreignId('ins_stc_machine_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->decimal('preheat_temp', 3, 1);
            $table->decimal('z_1_temp', 3, 1);
            $table->decimal('z_2_temp', 3, 1);
            $table->decimal('z_3_temp', 3, 1);
            $table->decimal('z_4_temp', 3, 1);
            $table->smallInteger('speed')->unsigned();

            $table->index('ins_stc_device_id');
            $table->index('ins_stc_machine_id');
            $table->index('start_time');
            $table->index('end_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ins_stc_d_sums');
    }
};
