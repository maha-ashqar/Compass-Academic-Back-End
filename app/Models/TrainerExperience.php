<?php

namespace App\Models;

use App\Models\Trainer;
use Illuminate\Database\Eloquent\Model;

class TrainerExperience extends Model
{
    protected $fillable = [
        'trainer_id',
        'job_title',
        'organization',
        'start_year',
        'end_year',
        'is_current',
        'description',
    ];

    protected $casts = [
        'is_current' => 'boolean',
    ];

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }
}
