<?php

namespace App\Models;

use App\Models\Trainer;
use Illuminate\Database\Eloquent\Model;

class TrainerCertificate extends Model
{
    protected $fillable = [
        'trainer_id',
        'name',
        'issuer',
        'issue_year',
        'credential_url',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }
}
