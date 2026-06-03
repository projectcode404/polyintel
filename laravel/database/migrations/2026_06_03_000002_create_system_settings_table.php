<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string')
                  ->comment('string | boolean | integer | float | json');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        
        // Insert default setting
        \Illuminate\Support\Facades\DB::table('system_settings')->insert([
            'key' => 'trading_fee_percentage',
            'value' => '0.2',
            'type' => 'float',
            'description' => 'Simulated trading fee percentage per transaction (default 0.2%)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
