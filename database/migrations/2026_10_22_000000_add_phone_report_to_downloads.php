<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened on the phone after the server's part was done.
 *
 * The server only ever knew that it produced a file. Whether the phone then
 * got it into the gallery was invisible: on 22 Oct 2026 a user reported
 * Facebook videos never reaching his gallery while every record here said
 * "completed". These columns let the app say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('phone_status', 20)->nullable();
            $table->string('phone_error', 500)->nullable();
            $table->string('phone_device', 200)->nullable();
            $table->timestamp('phone_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['phone_status', 'phone_error', 'phone_device', 'phone_reported_at']);
        });
    }
};
