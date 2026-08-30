<?php

namespace App\Models;

use App\Models\Badge;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionRegistrationMember;
use App\Models\CourseReview;
use App\Models\LearningPath;
use App\Models\LessonBookmark;
use App\Models\LessonProgress;
use App\Models\MentorEvaluation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Skill;
use App\Models\StudentCredential;
use App\Models\StudentEducation;
use App\Models\Submission;
use App\Models\TrainingProgram;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'student_code',
        'professional_summary',
        'portfolio_code',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->withPivot([
                'status',
                'enrolled_at',
                'completed_at'
            ])
            ->withTimestamps();
    }

    public function lessonProgress()
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function lessonBookmarks()
    {
        return $this->hasMany(LessonBookmark::class);
    }
    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
    public function learningPaths()
    {
        return $this->belongsToMany(
            LearningPath::class,
            'student_learning_paths'
        )
            ->withPivot([
                'status',
                'started_at',
                'completed_at'
            ])
            ->withTimestamps();
    }

    public function courseReviews()
    {
        return $this->hasMany(CourseReview::class);
    }

    public function interests()
    {
        return $this->belongsToMany(
            Category::class,
            'student_interests'
        )->withTimestamps();
    }
    public function ownedProjects()
    {
        return $this->hasMany(
            Project::class,
            'owner_student_id'
        );
    }

    public function projectMemberships()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function projects()
    {
        return $this->belongsToMany(
            Project::class,
            'project_members'
        )
            ->withPivot([
                'role',
                'joined_at'
            ])
            ->withTimestamps();
    }
    public function competitionRegistrations()
    {
        return $this->hasManyThrough(
            CompetitionRegistration::class,
            CompetitionRegistrationMember::class,
            'student_id',
            'id',
            'id',
            'competition_registration_id'
        );
    }
    public function educations()
    {
        return $this->hasMany(StudentEducation::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    public function skills()
    {
        return $this->belongsToMany(
            Skill::class,
            'student_skills'
        )
            ->withPivot('is_verified')
            ->withTimestamps();
    }

    public function badges()
    {
        return $this->belongsToMany(
            Badge::class,
            'student_badges'
        )
            ->withPivot('earned_at')
            ->withTimestamps();
    }

    public function credentials()
    {
        return $this->hasMany(StudentCredential::class);
    }

    public function trainingPrograms()
    {
        return $this->belongsToMany(
            TrainingProgram::class,
            'student_training_programs'
        )
            ->withPivot([
                'completed_at',
                'is_verified'
            ])
            ->withTimestamps();
    }

    public function mentorEvaluations()
    {
        return $this->hasMany(MentorEvaluation::class);
    }
}
