<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCardsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('v2_card_products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 255)->comment('商品名称');
            $table->text('description')->nullable()->comment('使用说明/商品描述');
            $table->integer('price')->comment('单价(分)');
            $table->tinyInteger('show')->default(1)->comment('是否上架: 0下架 1上架');
            $table->integer('sort')->default(0)->comment('排序');
            $table->timestamps();
        });

        Schema::create('v2_cards', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id')->comment('商品ID');
            $table->text('code')->comment('卡密文本');
            $table->tinyInteger('status')->default(0)->comment('状态: 0未售 1已售');
            $table->integer('user_id')->nullable()->comment('购买者用户ID');
            $table->integer('order_id')->nullable()->comment('绑定的订单ID');
            $table->timestamps();

            $table->index('product_id', 'idx_product_id');
            $table->index('status', 'idx_status');
            $table->index('user_id', 'idx_user_id');
            $table->index('order_id', 'idx_order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_cards');
        Schema::dropIfExists('v2_card_products');
    }
}
