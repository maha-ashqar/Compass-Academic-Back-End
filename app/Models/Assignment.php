<?php

namespace App\Models;

use App\Models\Course;
use App\Models\Submission;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'trainer_id',
        'title',
        'description',
        'submission_instructions',
        'max_grade',
        'opens_at',
        'deadline_at',
        'status',
        'published_at',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'deadline_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
