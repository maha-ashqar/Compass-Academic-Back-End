<?php

namespace App\Models;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AnnouncementRecipient extends Model
{
    protected $fillable = [
        'announcement_id',
        'user_id',
        'notified_at',
        'read_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
