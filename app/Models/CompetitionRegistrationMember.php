<?php

namespace App\Models;

use App\Models\CompetitionRegistration;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class CompetitionRegistrationMember extends Model
{
    protected $fillable = [
        'competition_registration_id',
        'student_id',
        'role',
    ];

    public function registration()
    {
        return $this->belongsTo(
            CompetitionRegistration::class,
            'competition_registration_id'
        );
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
