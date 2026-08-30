<?php

namespace App\Models;

use App\Models\CompetitionEvaluationCriterion;
use App\Models\CompetitionSubmission;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Model;

class CompetitionScore extends Model
{
    protected $fillable = [
        'competition_submission_id',
        'judge_id',
        'criterion_id',
        'score',
        'feedback',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function submission()
    {
        return $this->belongsTo(
            CompetitionSubmission::class,
            'competition_submission_id'
        );
    }

    public function judge()
    {
        return $this->belongsTo(
            Trainer::class,
            'judge_id'
        );
    }

    public function criterion()
    {
        return $this->belongsTo(
            CompetitionEvaluationCriterion::class,
            'criterion_id'
        );
    }
}
