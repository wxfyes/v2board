<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_security_logs')) {
            Schema::create('v2_security_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('ip', 45)->index();
                $table->string('location', 100)->nullable();
                $table->string('method', 10)->default('GET');
                $table->text('url');
                $table->text('user_agent')->nullable();
                $table->integer('status_code')->default(403)->index();
                $table->string('rule_name', 100)->nullable();
                $table->integer('duration')->default(0);
                $table->integer('created_at')->index();
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_ip_blacklist')) {
            Schema::create('v2_ip_blacklist', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('ip', 45)->unique();
                $table->string('reason', 255)->nullable();
                $table->string('operator', 100)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
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
        Schema::dropIfExists('v2_security_logs');
        Schema::dropIfExists('v2_ip_blacklist');
    }
};
