<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('room_types', function (Blueprint $row) {
            $row->dropColumn(['early_check_in_start_time', 'late_check_out_end_time']);
        });
    }

    public function down()
    {
        Schema::table('room_types', function (Blueprint $row) {
            $row->string('early_check_in_start_time')->nullable();
            $row->string('late_check_out_end_time')->nullable();
        });
    }
};
