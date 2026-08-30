<?php

namespace App\Models;

use App\Models\AnnouncementAudience;
use App\Models\AnnouncementRecipient;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'type',
        'content',
        'related_link',
        'attachment_path',
        'status',
        'scheduled_at',
        'published_at',
        'archived_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(
            Trainer::class,
            'created_by'
        );
    }

    public function audiences()
    {
        return $this->hasMany(
            AnnouncementAudience::class
        );
    }

    public function recipients()
    {
        return $this->hasMany(
            AnnouncementRecipient::class
        );
    }
}
