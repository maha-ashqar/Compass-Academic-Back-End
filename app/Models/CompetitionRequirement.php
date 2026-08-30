<?php

namespace App\Models;

use App\Models\Competition;
use Illuminate\Database\Eloquent\Model;

class CompetitionRequirement extends Model
{
    protected $fillable = [
        'competition_id',
        'requirement',
        'position'
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
