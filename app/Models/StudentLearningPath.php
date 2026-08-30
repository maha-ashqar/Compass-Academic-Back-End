<?php

namespace App\Models;

use App\Models\LearningPath;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentLearningPath extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'learning_path_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function learningPath()
    {
        return $this->belongsTo(LearningPath::class);
    }
}
