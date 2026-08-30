<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class StudentEducation extends Model
{
    protected $fillable = [
        'student_id',
        'degree',
        'major',
        'university',
        'faculty',
        'department',
        'academic_year',
        'start_year',
        'expected_graduation_date',
        'location',
        'is_current',
    ];

    protected $casts = [
        'expected_graduation_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
