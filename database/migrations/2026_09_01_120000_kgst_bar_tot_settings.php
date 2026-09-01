<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::set('kgst_bar_tot_cutover_date', '2026-04-01');
        Setting::set('kgst_bar_tot_rate_percent', '10');
        Setting::set('hotel_star_rating', '4');
    }

    public function down(): void
    {
        // Settings retained on rollback — remove manually if needed.
    }
};
