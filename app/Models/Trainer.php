<?php

namespace App\Models;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Competition;
use App\Models\CompetitionScore;
use App\Models\Course;
use App\Models\MentorEvaluation;
use App\Models\ProjectReview;
use App\Models\Submission;
use App\Models\TrainerCertificate;
use App\Models\TrainerExperience;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trainer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'job_title',
        'bio',
        'phone',
        'university',
        'faculty',
        'department',
        'office',
        'office_hours',
        'extension',
        'github_url',
        'linkedin_url',
        'status',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function courses()
    {
        return $this->hasMany(Course::class);
    }
    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function gradedSubmissions()
    {
        return $this->hasMany(Submission::class, 'graded_by');
    }
    public function projectReviews()
    {
        return $this->hasMany(ProjectReview::class);
    }
    public function competitions()
    {
        return $this->hasMany(
            Competition::class,
            'created_by'
        );
    }

    public function competitionScores()
    {
        return $this->hasMany(
            CompetitionScore::class,
            'judge_id'
        );
    }
    public function announcements()
    {
        return $this->hasMany(
            Announcement::class,
            'created_by'
        );
    }
    public function experiences()
    {
        return $this->hasMany(TrainerExperience::class);
    }

    public function certificates()
    {
        return $this->hasMany(TrainerCertificate::class);
    }

    public function mentorEvaluations()
    {
        return $this->hasMany(MentorEvaluation::class);
    }
}
