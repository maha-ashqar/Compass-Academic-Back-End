<?php

namespace App\Models;

use App\Models\CompetitionRegistration;
use App\Models\CompetitionScore;
use App\Models\CompetitionSubmissionFile;
use Illuminate\Database\Eloquent\Model;

class CompetitionSubmission extends Model
{
    protected $fillable = [
        'competition_registration_id',
        'title',
        'description',
        'github_url',
        'demo_url',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(
            CompetitionRegistration::class,
            'competition_registration_id'
        );
    }

    public function files()
    {
        return $this->hasMany(
            CompetitionSubmissionFile::class
        );
    }

    public function scores()
    {
        return $this->hasMany(
            CompetitionScore::class
        );
    }
}
