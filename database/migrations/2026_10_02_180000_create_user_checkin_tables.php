<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserCheckinTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_user_checkin_log')) {
            Schema::create('v2_user_checkin_log', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id')->comment('用户ID');
                $table->integer('plan_id')->comment('签到时套餐ID');
                $table->bigInteger('traffic')->comment('签到赠送流量(Bytes)');
                $table->date('checkin_date')->comment('签到日期(YYYY-MM-DD)');
                $table->string('month', 7)->comment('所属月份(YYYY-MM)');
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['user_id', 'checkin_date'], 'uniq_user_date');
                $table->index(['user_id', 'month'], 'idx_user_month');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_user_checkin_log');
    }
}
