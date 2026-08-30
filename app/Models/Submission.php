<?php

namespace App\Models;

use App\Models\Assignment;
use App\Models\Student;
use App\Models\SubmissionFile;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'student_id',
        'submission_name',
        'note',
        'status',
        'submitted_at',
        'grade',
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'grade' => 'decimal:2',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function grader()
    {
        return $this->belongsTo(Trainer::class, 'graded_by');
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
