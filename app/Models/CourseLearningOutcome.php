<?php

namespace App\Models;

use App\Models\Course;
use Illuminate\Database\Eloquent\Model;

class CourseLearningOutcome extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'position',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
