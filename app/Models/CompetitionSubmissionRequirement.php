<?php

namespace App\Models;

use App\Models\Competition;
use Illuminate\Database\Eloquent\Model;

class CompetitionSubmissionRequirement extends Model
{
    protected $fillable = [
        'competition_id',
        'title',
        'type',
        'position'
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
