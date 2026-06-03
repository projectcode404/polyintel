<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Portfolio Balance
            $table->decimal('balance', 20, 4)->default(1000.0000)
                  ->comment('Available USD for paper trading');
            
            // Trading Settings
            $table->boolean('is_auto_trade')->default(false)
                  ->comment('If true, automatically enter trade when signal is fired');
            $table->boolean('is_auto_close')->default(false)
                  ->comment('If true, automatically close trade when market resolves');
                  
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_accounts');
    }
};
