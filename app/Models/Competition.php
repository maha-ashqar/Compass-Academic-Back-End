<?php

namespace App\Models;

use App\Models\CompetitionEvaluationCriterion;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionRequirement;
use App\Models\CompetitionRule;
use App\Models\CompetitionSubmissionRequirement;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'title',
        'description',
        'objective',
        'participation_type',
        'max_team_members',
        'registration_start_at',
        'registration_end_at',
        'work_start_at',
        'work_end_at',
        'submission_deadline_at',
        'results_at',
        'prize',
        'status',
        'results_published_at',
    ];

    protected $casts = [
        'registration_start_at' => 'datetime',
        'registration_end_at' => 'datetime',
        'work_start_at' => 'datetime',
        'work_end_at' => 'datetime',
        'submission_deadline_at' => 'datetime',
        'results_at' => 'datetime',
        'results_published_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(
            Trainer::class,
            'created_by'
        );
    }

    public function requirements()
    {
        return $this->hasMany(
            CompetitionRequirement::class
        )->orderBy('position');
    }

    public function rules()
    {
        return $this->hasMany(
            CompetitionRule::class
        )->orderBy('position');
    }

    public function evaluationCriteria()
    {
        return $this->hasMany(
            CompetitionEvaluationCriterion::class
        )->orderBy('position');
    }

    public function submissionRequirements()
    {
        return $this->hasMany(
            CompetitionSubmissionRequirement::class
        )->orderBy('position');
    }

    public function registrations()
    {
        return $this->hasMany(
            CompetitionRegistration::class
        );
    }
}
