<?php

namespace App\Models;

use App\Models\Category;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\ProjectMember;
use App\Models\ProjectReview;
use App\Models\Student;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_student_id',
        'course_id',
        'learning_path_id',
        'category_id',
        'title',
        'idea',
        'description',
        'problem',
        'solution',
        'project_type',
        'cover_image',
        'logo',
        'intro_video',
        'github_url',
        'live_url',
        'presentation_file',
        'documentation_file',
        'status',
        'is_featured',
        'submitted_for_review_at',
        'published_at',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'submitted_for_review_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(
            Student::class,
            'owner_student_id'
        );
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function learningPath()
    {
        return $this->belongsTo(LearningPath::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function technologies()
    {
        return $this->belongsToMany(
            Technology::class,
            'project_technology'
        )->withTimestamps();
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProjectReview::class);
    }
}
