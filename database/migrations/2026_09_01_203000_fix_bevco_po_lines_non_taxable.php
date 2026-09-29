<?php

use App\Services\BevcoPoTaxCorrection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BevcoPoTaxCorrection::run();
    }

    public function down(): void
    {
        // Data correction is not reversed automatically.
    }
};
