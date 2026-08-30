<?php

namespace App\Models;

use App\Models\CourseModule;
use App\Models\LessonBookmark;
use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_module_id',
        'title',
        'description',
        'type',
        'content_url',
        'duration_minutes',
        'position',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function module()
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }
    public function progress()
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function bookmarks()
    {
        return $this->hasMany(LessonBookmark::class);
    }
}
