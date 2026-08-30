<?php

namespace App\Models;

use App\Models\Lesson;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonBookmark extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'lesson_id',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
