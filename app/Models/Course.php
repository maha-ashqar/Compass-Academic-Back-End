<?php

namespace App\Models;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\CourseLearningOutcome;
use App\Models\CourseModule;
use App\Models\CourseRequirement;
use App\Models\CourseResource;
use App\Models\CourseReview;
use App\Models\LearningPath;
use App\Models\Project;
use App\Models\Student;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'trainer_id',
        'category_id',
        'title',
        'slug',
        'description',
        'level',
        'duration_weeks',
        'cover_image',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function modules()
    {
        return $this->hasMany(CourseModule::class)
            ->orderBy('position');
    }
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'enrollments')
            ->withPivot([
                'status',
                'enrolled_at',
                'completed_at'
            ])
            ->withTimestamps();
    }
    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }
    public function learningPaths()
    {
        return $this->belongsToMany(
            LearningPath::class,
            'learning_path_courses'
        )
            ->withPivot('position')
            ->withTimestamps();
    }

    public function learningOutcomes()
    {
        return $this->hasMany(CourseLearningOutcome::class)
            ->orderBy('position');
    }

    public function requirements()
    {
        return $this->hasMany(CourseRequirement::class)
            ->orderBy('position');
    }

    public function resources()
    {
        return $this->hasMany(CourseResource::class)
            ->orderBy('position');
    }

    public function reviews()
    {
        return $this->hasMany(CourseReview::class);
    }
    public function projects()
    {
        return $this->hasMany(Project::class);
    }
    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
