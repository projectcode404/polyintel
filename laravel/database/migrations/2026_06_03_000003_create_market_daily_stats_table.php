<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            
            $table->date('stat_date');
            
            $table->decimal('volume_7d_avg_usd', 20, 2)->nullable()
                  ->comment('Precomputed 7-day average volume for quick lookup');
            $table->decimal('oi_change_percent', 10, 6)->nullable()
                  ->comment('Open interest change percent vs previous day');
            $table->decimal('momentum_24h_percent', 10, 6)->nullable()
                  ->comment('Probability change in last 24h');
                  
            $table->timestamps();
            
            $table->unique(['market_id', 'stat_date'], 'mkt_daily_stats_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_daily_stats');
    }
};
