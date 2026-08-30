<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    protected $fillable = [
        'name',
        'description',
        'icon',
        'condition_type',
        'condition_value',
    ];

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'student_badges'
        )
        ->withPivot('earned_at')
        ->withTimestamps();
    }
}
