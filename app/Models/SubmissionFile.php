<?php

namespace App\Models;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }
}
