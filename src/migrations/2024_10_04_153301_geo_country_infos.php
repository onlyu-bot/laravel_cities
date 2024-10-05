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
        Schema::create('geo_country_infos', function (Blueprint $table) {
            $table->increments('id');
            $table->char('country', 2); //ISO
            $table->text('currency_code'); //CurrencyCode
            $table->text('languages'); //Languages
            $table->unsignedInteger('geo_id'); //geonameid
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('geo_country_infos');
    }
};
