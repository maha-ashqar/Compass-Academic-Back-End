<?php

namespace App\Models;

use App\Models\Competition;
use App\Models\CompetitionScore;
use Illuminate\Database\Eloquent\Model;

class CompetitionEvaluationCriterion extends Model
{
    protected $fillable = [
        'competition_id',
        'title',
        'weight',
        'position'
    ];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function scores()
    {
        return $this->hasMany(
            CompetitionScore::class,
            'criterion_id'
        );
    }
}
