<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserBonusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_bonuses', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['invest', 'balance']);
            $table->integer('user_id');
            $table->string('token');
            $table->integer('plan_id')->nullable();
            $table->string('currency_code');
            $table->decimal('amount', 10, 2)->nullable();
            $table->boolean('pay_referral')->default(0);
            $table->boolean('notify')->default(0);
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
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
        Schema::dropIfExists('user_bonuses');
    }
}
