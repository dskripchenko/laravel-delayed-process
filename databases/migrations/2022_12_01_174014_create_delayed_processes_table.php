<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDelayedProcessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('delayed_processes', function (Blueprint $table) {
            $table->id();

            $table->string('uuid')
                ->index()
                ->unique();

            $table->string('entity')
                ->nullable();
            $table->string('method');

            $table->text('parameters');
            $table->text('data');

            $table->longText('logs');

            $table->string('status')
                ->default('new')
                ->index();

            $table->unsignedTinyInteger('attempts')
                ->default(5);

            $table->unsignedTinyInteger('try')
                ->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('delayed_processes');
    }
}
