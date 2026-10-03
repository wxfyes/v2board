<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMissingServerTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('v2_server_mieru')) {
            Schema::create('v2_server_mieru', function (Blueprint $table) {
                $table->increments('id');
                $table->string('group_id', 255);
                $table->string('route_id', 255)->nullable();
                $table->string('name', 255);
                $table->integer('parent_id')->nullable();
                $table->string('host', 255);
                $table->string('port', 11);
                $table->integer('server_port');
                $table->string('tags', 255)->nullable();
                $table->string('rate', 11);
                $table->tinyInteger('show')->default(0);
                $table->integer('sort')->nullable();
                $table->string('port_range', 255)->nullable();
                $table->string('transport', 64)->default('tcp');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_server_anytls')) {
            Schema::create('v2_server_anytls', function (Blueprint $table) {
                $table->increments('id');
                $table->string('group_id', 255);
                $table->string('route_id', 255)->nullable();
                $table->string('name', 255);
                $table->integer('parent_id')->nullable();
                $table->string('host', 255);
                $table->string('port', 11);
                $table->integer('server_port');
                $table->tinyInteger('tls')->default(0);
                $table->string('tags', 255)->nullable();
                $table->string('rate', 11);
                $table->tinyInteger('show')->default(0);
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_server_v2node')) {
            Schema::create('v2_server_v2node', function (Blueprint $table) {
                $table->increments('id');
                $table->string('group_id', 255);
                $table->string('route_id', 255)->nullable();
                $table->string('name', 255);
                $table->integer('parent_id')->nullable();
                $table->string('host', 255);
                $table->string('port', 11);
                $table->integer('server_port');
                $table->string('tags', 255)->nullable();
                $table->string('rate', 11);
                $table->tinyInteger('show')->default(0);
                $table->integer('sort')->nullable();
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
        // 安全保护生产环境数据，不回滚删除节点表
    }
}
