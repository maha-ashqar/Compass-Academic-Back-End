<?php

namespace App\Models;

use App\Models\Project;
use App\Models\Student;
use App\Models\StudentLearningPath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningPath extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_image',
        'level',
        'status',
    ];

    public function courses()
    {
        return $this->belongsToMany(
            Course::class,
            'learning_path_courses'
        )
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function studentLearningPaths()
    {
        return $this->hasMany(StudentLearningPath::class);
    }

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'student_learning_paths'
        )
            ->withPivot([
                'status',
                'started_at',
                'completed_at'
            ])
            ->withTimestamps();
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
