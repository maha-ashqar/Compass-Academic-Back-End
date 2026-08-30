<?php

namespace App\Models;

use App\Models\CompetitionSubmission;
use Illuminate\Database\Eloquent\Model;

class CompetitionSubmissionFile extends Model
{
    protected $fillable = [
        'competition_submission_id',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    public function submission()
    {
        return $this->belongsTo(
            CompetitionSubmission::class,
            'competition_submission_id'
        );
    }
}
