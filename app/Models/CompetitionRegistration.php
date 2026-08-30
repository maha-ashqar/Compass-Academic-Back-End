<?php

namespace App\Models;

use App\Models\Competition;
use App\Models\CompetitionRegistrationMember;
use App\Models\CompetitionResult;
use App\Models\CompetitionSubmission;
use Illuminate\Database\Eloquent\Model;

class CompetitionRegistration extends Model
{
    protected $fillable = [
        'competition_id',
        'team_name',
        'status',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function members()
    {
        return $this->hasMany(
            CompetitionRegistrationMember::class
        );
    }

    public function submission()
    {
        return $this->hasOne(
            CompetitionSubmission::class
        );
    }

    public function result()
    {
        return $this->hasOne(
            CompetitionResult::class
        );
    }
}
