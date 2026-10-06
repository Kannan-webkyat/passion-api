<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('room_types', 'adult_meal_price')) {
                $table->decimal('adult_meal_price', 10, 2)->default(0)->after('child_breakfast_price');
            }
            if (! Schema::hasColumn('room_types', 'child_meal_price')) {
                $table->decimal('child_meal_price', 10, 2)->default(0)->after('adult_meal_price');
            }
        });

        if (! Schema::hasColumn('room_types', 'adult_dinner_price')) {
            return;
        }

        foreach (DB::table('room_types')->orderBy('id')->get() as $row) {
            DB::table('room_types')->where('id', $row->id)->update([
                'adult_meal_price' => max((float) ($row->adult_dinner_price ?? 0), (float) ($row->adult_lunch_price ?? 0)),
                'child_meal_price' => max((float) ($row->child_dinner_price ?? 0), (float) ($row->child_lunch_price ?? 0)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['adult_meal_price', 'child_meal_price']);
        });
    }
};
