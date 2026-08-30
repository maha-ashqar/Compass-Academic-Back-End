<?php

namespace App\Models;

use App\Models\Badge;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class StudentBadge extends Model
{
    protected $fillable = [
        'student_id',
        'badge_id',
        'earned_at',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }
}
