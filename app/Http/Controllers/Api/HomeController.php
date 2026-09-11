<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function index()
    {
        $courses = DB::table('courses as c')
            ->join('trainers as t', 't.id', '=', 'c.trainer_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->join('categories as cat', 'cat.id', '=', 'c.category_id')
            ->where('c.status', 'published')
            ->where('t.status', 'active')
            ->where('u.role', 'trainer')
            ->orderByDesc('c.published_at')
            ->orderByDesc('c.id')
            ->limit(6)
            ->get([
                'c.id',
                'c.title',
                'c.slug',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'cat.name as category',
                'u.name as instructor',
            ])
            ->map(function ($course) {
                $lessons = DB::table('lessons as l')
                    ->join(
                        'course_modules as cm',
                        'cm.id',
                        '=',
                        'l.course_module_id'
                    )
                    ->where('cm.course_id', $course->id)
                    ->where('l.is_published', true)
                    ->count();

                $rating = DB::table('course_reviews')
                    ->where('course_id', $course->id)
                    ->avg('rating');

                return [
                    'id' => (int) $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'category' => $course->category,
                    'instructor' => $course->instructor,
                    'level' => ucfirst($course->level),
                    'duration' => $course->duration_weeks
                        ? $course->duration_weeks . ' weeks'
                        : 'Self-paced',
                    'lessons' => (int) $lessons,
                    'rating' => $rating
                        ? round((float) $rating, 1)
                        : 0,
                    'coverImage' => $this->storageUrl(
                        $course->cover_image
                    ),
                ];
            })
            ->values();

        $projects = DB::table('projects as p')
            ->join(
                'students as s',
                's.id',
                '=',
                'p.owner_student_id'
            )
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin(
                'categories as cat',
                'cat.id',
                '=',
                'p.category_id'
            )
            ->where('p.status', 'published')
            ->orderByDesc('p.is_featured')
            ->orderByDesc('p.published_at')
            ->orderByDesc('p.id')
            ->limit(3)
            ->get([
                'p.id',
                'p.title',
                'p.description',
                'p.cover_image',
                'p.is_featured',
                'u.name as student_name',
                'cat.name as category',
            ])
            ->map(function ($project) {
                $technologies = DB::table(
                    'project_technology as pt'
                )
                    ->join(
                        'technologies as tech',
                        'tech.id',
                        '=',
                        'pt.technology_id'
                    )
                    ->where(
                        'pt.project_id',
                        $project->id
                    )
                    ->orderBy('tech.name')
                    ->pluck('tech.name')
                    ->values();

                $stack = $technologies->isNotEmpty()
                    ? $technologies->implode(' · ')
                    : ($project->category ?: 'Student project');

                return [
                    'id' => (int) $project->id,
                    'title' => $project->title,
                    'student' => $project->student_name,
                    'description' => $project->description,
                    'stack' => $stack,
                    'badge' => $project->is_featured
                        ? 'Featured'
                        : 'Published',
                    'coverImage' => $this->storageUrl(
                        $project->cover_image
                    ),
                ];
            })
            ->values();

        $trainers = DB::table('trainers as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.status', 'active')
            ->where('u.role', 'trainer')
            ->orderByDesc('t.is_verified')
            ->orderByDesc('t.id')
            ->limit(2)
            ->get([
                't.id',
                't.job_title',
                't.bio',
                't.university',
                't.faculty',
                't.department',
                't.is_verified',
                'u.name',
                'u.avatar',
            ])
            ->map(function ($trainer) {
                $specialty =
                    $trainer->department
                    ?: $trainer->faculty
                    ?: $trainer->university
                    ?: 'Instructor';

                return [
                    'id' => (int) $trainer->id,
                    'name' => $trainer->name,
                    'role' => $trainer->job_title
                        ?: 'Compass Academy Instructor',
                    'specialty' => $specialty,
                    'bio' => $trainer->bio
                        ?: 'Practical guidance for students building real skills and projects.',
                    'image' => $this->storageUrl(
                        $trainer->avatar
                    ),
                    'isVerified' => (bool) $trainer->is_verified,
                ];
            })
            ->values();

        $stats = [
            'published_courses' => DB::table('courses')
                ->where('status', 'published')
                ->count(),

            'active_trainers' => DB::table('trainers')
                ->where('status', 'active')
                ->count(),

            'published_projects' => DB::table('projects')
                ->where('status', 'published')
                ->count(),

            'students' => DB::table('students')->count(),
        ];

        return response()->json([
            'courses' => $courses,
            'projects' => $projects,
            'trainers' => $trainers,
            'stats' => $stats,
        ]);
    }

    private function storageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (
            Str::startsWith(
                $path,
                ['http://', 'https://']
            )
        ) {
            return $path;
        }

        return asset(
            'storage/' . ltrim($path, '/')
        );
    }
}
