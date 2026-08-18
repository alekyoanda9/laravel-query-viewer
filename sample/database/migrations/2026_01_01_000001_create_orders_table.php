<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('branch_id');
            $table->string('no_order');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
