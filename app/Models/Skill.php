<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = [
        'name',
        'category',
    ];

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'student_skills'
        )
        ->withPivot('is_verified')
        ->withTimestamps();
    }
}
