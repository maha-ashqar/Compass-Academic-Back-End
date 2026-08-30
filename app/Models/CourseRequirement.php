<?php

namespace App\Models;

use App\Models\Course;
use Illuminate\Database\Eloquent\Model;

class CourseRequirement extends Model
{
    protected $fillable = [
        'course_id',
        'requirement',
        'position',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
