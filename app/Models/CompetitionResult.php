<?php

namespace App\Models;

use App\Models\CompetitionRegistration;
use Illuminate\Database\Eloquent\Model;

class CompetitionResult extends Model
{
    protected $fillable = [
        'competition_registration_id',
        'rank',
        'final_score',
        'award',
        'published_at',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(
            CompetitionRegistration::class,
            'competition_registration_id'
        );
    }
}
