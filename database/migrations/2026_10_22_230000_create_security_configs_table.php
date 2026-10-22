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
        if (!Schema::hasTable('v2_security_configs')) {
            Schema::create('v2_security_configs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('key', 64)->unique('idx_security_key');
                $table->longText('value')->nullable();
                $table->integer('updated_at')->default(0);
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
        Schema::dropIfExists('v2_security_configs');
    }
};
