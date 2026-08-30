<?php

namespace App\Models;

use App\Models\Student;
use App\Models\TrainingProgram;
use Illuminate\Database\Eloquent\Model;

class StudentTrainingProgram extends Model
{
    protected $fillable = [
        'student_id',
        'training_program_id',
        'completed_at',
        'is_verified',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function trainingProgram()
    {
        return $this->belongsTo(TrainingProgram::class);
    }
}
