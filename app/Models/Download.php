<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    protected $fillable = [
        'url',
        'platform',
        'quality',
        'title',
        'thumbnail_url',
        'status',
        'progress',
        'video_url',
        'error_message',
        'phone_status',
        'phone_error',
        'phone_device',
        'phone_reported_at',
        'wants_photos',
        'media_type',
        'photo_urls',
    ];

    protected $casts = [
        'progress' => 'integer',
        'phone_reported_at' => 'datetime',
        'wants_photos' => 'boolean',
        'photo_urls' => 'array',
    ];
}