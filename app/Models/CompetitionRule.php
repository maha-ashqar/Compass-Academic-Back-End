<?php

namespace App\Models;

use App\Models\Competition;
use Illuminate\Database\Eloquent\Model;

class CompetitionRule extends Model
{
    protected $fillable = [
        'competition_id',
        'rule',
        'position'
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
