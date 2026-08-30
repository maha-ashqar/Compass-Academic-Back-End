<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class TrainingProgram extends Model
{
    protected $fillable = [
        'title',
        'provider',
        'duration_hours',
        'description',
    ];

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'student_training_programs'
        )
        ->withPivot([
            'completed_at',
            'is_verified'
        ])
        ->withTimestamps();
    }
}
