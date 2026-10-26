<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo posts: what the download turned out to be, and where its pictures are.
 *
 * wants_photos is set only by app versions that can put pictures in the
 * gallery. Older ones would save a JPEG as if it were a video, so for them a
 * photo post still fails with "There is no video in this post".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->boolean('wants_photos')->default(false);
            $table->string('media_type', 10)->default('video');
            $table->text('photo_urls')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['wants_photos', 'media_type', 'photo_urls']);
        });
    }
};
