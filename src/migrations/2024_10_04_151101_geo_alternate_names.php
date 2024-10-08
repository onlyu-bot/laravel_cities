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
        Schema::create('geo_alternate_names', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('alternate_name_id'); //alternateNameId
            $table->unsignedInteger('geo_id'); //geonameid
            $table->char('isolanguage', 60); //isolanguage
            $table->text('alternate_name'); //alternate name
            $table->char('is_preferred_name', 5);
            $table->char('is_short_name', 5);
            $table->char('is_colloquial', 5);
            $table->char('is_historic', 5); 
            $table->char('from', 5);
            $table->char('to', 5);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('geo_alternate_names');
    }
};
