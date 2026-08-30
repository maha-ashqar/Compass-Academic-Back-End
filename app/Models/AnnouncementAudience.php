<?php

namespace App\Models;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Model;

class AnnouncementAudience extends Model
{
    protected $fillable = [
        'announcement_id',
        'audience_type',
        'audience_id',
    ];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }
}
