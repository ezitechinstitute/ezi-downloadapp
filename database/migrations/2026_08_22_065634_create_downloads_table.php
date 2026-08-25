<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();

            $table->text('url');
            $table->string('platform');
            $table->string('quality')->default('1080p');

            $table->string('title')->nullable();
            $table->text('thumbnail_url')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);

            $table->text('video_url')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};