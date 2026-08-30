<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class StudentCredential extends Model
{
    protected $fillable = [
        'student_id',
        'title',
        'issuer',
        'issue_date',
        'credential_id',
        'credential_url',
        'file_path',
        'is_verified',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'is_verified' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
