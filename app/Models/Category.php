<?php

namespace App\Models;

use App\Models\Course;
use App\Models\Project;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function courses()
    {
        return $this->hasMany(Course::class);
    }
    public function interestedStudents()
    {
        return $this->belongsToMany(
            Student::class,
            'student_interests'
        )->withTimestamps();
    }
    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
