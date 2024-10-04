<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlternateName extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('alternate_names', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('alternate_name_id'); //alternateNameId
            $table->unsignedInteger('geo_id'); //geonameid
            $table->char('isolanguage', 60); //isolanguage
            $table->char('alternate_name', 60); //alternate name
            $table->char('is_preferred_name', 60)->default('1');
            $table->char('is_short_name', 60)->default('1');
            $table->char('is_colloquial', 60)->default('1');
            $table->char('is_historic', 60)->default('1'); 
            $table->char('from', 10);
            $table->char('to', 10);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alternate_names');
    }
}
