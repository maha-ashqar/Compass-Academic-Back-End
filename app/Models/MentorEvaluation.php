<?php

namespace App\Models;

use App\Models\Student;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Model;

class MentorEvaluation extends Model
{
    protected $fillable = [
        'student_id',
        'trainer_id',
        'score',
        'evaluation',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }
}
