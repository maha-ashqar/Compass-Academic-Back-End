<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    private const EDIT_WINDOW_MINUTES = 15;

    public function directory(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $trainers = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->join('trainers as t', 't.id', '=', 'c.trainer_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('e.student_id', $student->id)
            ->whereIn('e.status', ['active', 'completed'])
            ->where('c.status', 'published')
            ->where('t.status', 'active')
            ->select([
                't.id as trainer_id',
                'u.id as user_id',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at',
                't.job_title',
                't.department',
                'c.title as course_title',
            ])
            ->orderBy('u.name')
            ->get()
            ->groupBy('trainer_id')
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'id' => (int) $first->trainer_id,
                    'recipient_user_id' => (int) $first->user_id,
                    'type' => 'trainer',
                    'user_id' => (int) $first->user_id,
                    'email' => $first->email,
                    'name' => $first->name,
                    'role' => 'Instructor',
                    'specialty' =>
                    $first->job_title
                        ?: $first->department
                        ?: 'Instructor',
                    'course' => $rows
                        ->pluck('course_title')
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(' · '),
                    'status' => $this->presenceStatus(
                        $first->last_active_at
                    ),
                    'avatar' => $this->avatarUrl(
                        $first->avatar
                    ),
                ];
            })
            ->values();

        $students = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('u.role', 'student')
            ->where('u.id', '!=', $student->user_id)
            ->orderBy('u.name')
            ->get([
                's.id as student_id',
                's.professional_summary',
                'u.id as user_id',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at',
            ])
            ->map(function ($row) {
                $education = DB::table('student_educations')
                    ->where('student_id', $row->student_id)
                    ->orderByDesc('is_current')
                    ->orderByDesc('id')
                    ->first([
                        'major',
                        'department',
                        'university',
                    ]);

                return [
                    'id' => (int) $row->student_id,
                    'recipient_user_id' => (int) $row->user_id,
                    'type' => 'student',
                    'user_id' => (int) $row->user_id,
                    'email' => $row->email,
                    'name' => $row->name,
                    'role' => 'Student',
                    'specialty' =>
                    $education?->major
                        ?: $education?->department
                        ?: 'Student',
                    'course' =>
                    $education?->university
                        ?: 'Compass Academy',
                    'status' => $this->presenceStatus(
                        $row->last_active_at
                    ),
                    'avatar' => $this->avatarUrl(
                        $row->avatar
                    ),
                ];
            });

        return response()->json([
            'directory' => $trainers
                ->concat($students)
                ->sortBy(
                    fn($person) =>
                    strtolower($person['name'])
                )
                ->values(),
        ]);
    }

    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $conversationIds = DB::table(
            'conversation_participants as cp'
        )
            ->join(
                'conversations as c',
                'c.id',
                '=',
                'cp.conversation_id'
            )
            ->where('cp.user_id', $student->user_id)
            ->orderByDesc('c.updated_at')
            ->orderByDesc('c.id')
            ->pluck('c.id');

        $conversations = $conversationIds
            ->map(
                fn($conversationId) =>
                $this->conversationData(
                    (int) $conversationId,
                    (int) $student->user_id
                )
            )
            ->filter()
            ->values();

        return response()->json([
            'conversations' => $conversations,
            'unread_count' => $conversations
                ->sum('unreadForStudent'),
        ]);
    }

    public function show(
        Request $request,
        int $conversationId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $student->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        return response()->json([
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    public function storeConversation(
        Request $request
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $request->validate([
            'recipient_user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $recipient = DB::table('users')
            ->where('id', $validated['recipient_user_id'])
            ->whereIn('role', ['student', 'trainer'])
            ->first([
                'id',
                'name',
                'role',
            ]);

        if (!$recipient) {
            return response()->json([
                'message' => 'Recipient not found.',
            ], 404);
        }

        if ((int) $recipient->id === (int) $student->user_id) {
            return response()->json([
                'message' => 'You cannot start a conversation with yourself.',
            ], 422);
        }

        $topic = 'Student chat';
        $acceptedAt = now();

        if ($recipient->role === 'trainer') {
            $trainer = DB::table('trainers')
                ->where('user_id', $recipient->id)
                ->where('status', 'active')
                ->first();

            if (!$trainer) {
                return response()->json([
                    'message' => 'Instructor not found.',
                ], 404);
            }

            $course = DB::table('enrollments as e')
                ->join('courses as c', 'c.id', '=', 'e.course_id')
                ->where('e.student_id', $student->id)
                ->where('c.trainer_id', $trainer->id)
                ->whereIn('e.status', ['active', 'completed'])
                ->where('c.status', 'published')
                ->orderByDesc('e.id')
                ->first([
                    'c.id',
                    'c.title',
                ]);

            if (!$course) {
                return response()->json([
                    'message' =>
                    'You can only message instructors connected to your courses.',
                ], 403);
            }

            $topic = $course->title;
            $acceptedAt = null;
        } else {
            $recipientStudent = DB::table('students')
                ->where('user_id', $recipient->id)
                ->first();

            if (!$recipientStudent) {
                return response()->json([
                    'message' => 'Student recipient not found.',
                ], 404);
            }
        }

        $existingConversationId =
            $this->conversationBetweenUsers(
                (int) $student->user_id,
                (int) $recipient->id
            );

        if ($existingConversationId) {
            return response()->json([
                'message' => 'Conversation already exists.',
                'conversation' =>
                $this->conversationData(
                    $existingConversationId,
                    (int) $student->user_id
                ),
            ]);
        }

        $conversationId = DB::transaction(
            function () use (
                $student,
                $recipient,
                $topic,
                $acceptedAt
            ) {
                $conversationId =
                    DB::table('conversations')
                    ->insertGetId([
                        'topic' => $topic,
                        'accepted_at' => $acceptedAt,
                        'declined_at' => null,
                        'blocked_by_user_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table(
                    'conversation_participants'
                )->insert([
                    [
                        'conversation_id' =>
                        $conversationId,
                        'user_id' =>
                        $student->user_id,
                        'last_read_at' => now(),
                        'cleared_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'conversation_id' =>
                        $conversationId,
                        'user_id' =>
                        $recipient->id,
                        'last_read_at' => null,
                        'cleared_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                return $conversationId;
            }
        );

        return response()->json([
            'message' => 'Conversation created.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ], 201);
    }

    public function sendMessage(
        Request $request,
        int $conversationId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $conversation =
            $this->conversationForUser(
                $conversationId,
                (int) $student->user_id
            );

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->blocked_by_user_id) {
            return response()->json([
                'message' => 'This conversation is blocked.',
            ], 422);
        }

        if ($conversation->declined_at) {
            return response()->json([
                'message' =>
                'This message request was declined.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'attachments' => [
                'nullable',
                'array',
                'max:5',
            ],
            'attachments.*' => [
                'file',
                'mimes:pdf,doc,docx,jpg,jpeg,png,webp,mp4',
                'max:25600',
            ],
        ]);

        $text = trim(
            (string) ($validated['message'] ?? '')
        );

        $files = $request->file(
            'attachments',
            []
        );

        if ($text === '' && count($files) === 0) {
            throw ValidationException::withMessages([
                'message' =>
                'Write a message or attach at least one file.',
            ]);
        }

        $messageId = DB::table('messages')
            ->insertGetId([
                'conversation_id' =>
                $conversationId,
                'sender_id' => $student->user_id,
                'message' =>
                $text !== '' ? $text : null,
                'attachment_path' => null,
                'read_at' => null,
                'edited_at' => null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ($files as $file) {
            $path = $file->store(
                'messages/' . $conversationId,
                'public'
            );

            DB::table(
                'message_attachments'
            )->insert([
                'message_id' => $messageId,
                'original_name' =>
                $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' =>
                $file->getMimeType(),
                'file_size' =>
                $file->getSize(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Message sent.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ], 201);
    }

    public function markAsRead(
        Request $request,
        int $conversationId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $student->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        DB::transaction(function () use (
            $conversationId,
            $student
        ) {
            DB::table('messages')
                ->where(
                    'conversation_id',
                    $conversationId
                )
                ->where(
                    'sender_id',
                    '!=',
                    $student->user_id
                )
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table(
                'conversation_participants'
            )
                ->where(
                    'conversation_id',
                    $conversationId
                )
                ->where(
                    'user_id',
                    $student->user_id
                )
                ->update([
                    'last_read_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'message' =>
            'Conversation marked as read.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    public function updateMessage(
        Request $request,
        int $conversationId,
        int $messageId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $student->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        $message = DB::table('messages')
            ->where('id', $messageId)
            ->where(
                'conversation_id',
                $conversationId
            )
            ->first();

        if (
            !$message ||
            (int) $message->sender_id !==
            (int) $student->user_id ||
            $message->deleted_at
        ) {
            return response()->json([
                'message' => 'Message not found.',
            ], 404);
        }

        if (!$this->messageIsEditable($message)) {
            return response()->json([
                'message' =>
                'The 15-minute edit window has expired.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:10000',
            ],
        ]);

        DB::table('messages')
            ->where('id', $messageId)
            ->update([
                'message' =>
                trim($validated['message']),
                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Message updated.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    public function deleteMessage(
        Request $request,
        int $conversationId,
        int $messageId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $student->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        $message = DB::table('messages')
            ->where('id', $messageId)
            ->where(
                'conversation_id',
                $conversationId
            )
            ->first();

        if (
            !$message ||
            (int) $message->sender_id !==
            (int) $student->user_id ||
            $message->deleted_at
        ) {
            return response()->json([
                'message' => 'Message not found.',
            ], 404);
        }

        if (!$this->messageIsEditable($message)) {
            return response()->json([
                'message' =>
                'The 15-minute delete window has expired.',
            ], 422);
        }

        $attachments = DB::table(
            'message_attachments'
        )
            ->where('message_id', $messageId)
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->file_path) {
                Storage::disk('public')->delete(
                    $attachment->file_path
                );
            }
        }

        DB::transaction(function () use (
            $messageId
        ) {
            DB::table('message_attachments')
                ->where('message_id', $messageId)
                ->delete();

            DB::table('messages')
                ->where('id', $messageId)
                ->update([
                    'message' => null,
                    'attachment_path' => null,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'message' => 'Message deleted.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    public function toggleBlock(
        Request $request,
        int $conversationId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $conversation =
            $this->conversationForUser(
                $conversationId,
                (int) $student->user_id
            );

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if (
            $conversation->blocked_by_user_id &&
            (int) $conversation->blocked_by_user_id !==
            (int) $student->user_id
        ) {
            return response()->json([
                'message' =>
                'Only the user who blocked this conversation can unblock it.',
            ], 403);
        }

        $nextBlockedBy =
            $conversation->blocked_by_user_id
            ? null
            : $student->user_id;

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'blocked_by_user_id' =>
                $nextBlockedBy,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => $nextBlockedBy
                ? 'Conversation blocked.'
                : 'Conversation unblocked.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    public function clearConversation(
        Request $request,
        int $conversationId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $student->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        DB::table('conversation_participants')
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where(
                'user_id',
                $student->user_id
            )
            ->update([
                'cleared_at' => now(),
                'last_read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
            'Conversation cleared from your view.',
            'conversation' =>
            $this->conversationData(
                $conversationId,
                (int) $student->user_id
            ),
        ]);
    }

    private function studentFromRequest(
        Request $request
    ) {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return null;
        }

        return DB::table('students')
            ->where('user_id', $user->id)
            ->first();
    }

    private function isParticipant(
        int $conversationId,
        int $userId
    ): bool {
        return DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where('user_id', $userId)
            ->exists();
    }

    private function conversationForUser(
        int $conversationId,
        int $userId
    ) {
        return DB::table('conversations as c')
            ->join(
                'conversation_participants as cp',
                'cp.conversation_id',
                '=',
                'c.id'
            )
            ->where('c.id', $conversationId)
            ->where('cp.user_id', $userId)
            ->select('c.*')
            ->first();
    }

    private function conversationBetweenUsers(
        int $studentUserId,
        int $trainerUserId
    ): ?int {
        $conversationId = DB::table(
            'conversation_participants as a'
        )
            ->join(
                'conversation_participants as b',
                'b.conversation_id',
                '=',
                'a.conversation_id'
            )
            ->where('a.user_id', $studentUserId)
            ->where('b.user_id', $trainerUserId)
            ->value('a.conversation_id');

        return $conversationId
            ? (int) $conversationId
            : null;
    }

    private function conversationData(
        int $conversationId,
        int $studentUserId
    ): ?array {
        $conversation =
            $this->conversationForUser(
                $conversationId,
                $studentUserId
            );

        if (!$conversation) {
            return null;
        }

        $participant = DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where(
                'user_id',
                $studentUserId
            )
            ->first();

        $contact = DB::table(
            'conversation_participants as cp'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cp.user_id'
            )
            ->leftJoin(
                'trainers as t',
                't.user_id',
                '=',
                'u.id'
            )
            ->leftJoin(
                'students as s',
                's.user_id',
                '=',
                'u.id'
            )
            ->where(
                'cp.conversation_id',
                $conversationId
            )
            ->where(
                'cp.user_id',
                '!=',
                $studentUserId
            )
            ->first([
                'u.id as user_id',
                'u.name',
                'u.email',
                'u.role',
                'u.avatar',
                'u.last_active_at',
                't.id as trainer_id',
                't.job_title',
                't.department as trainer_department',
                's.id as student_id',
                's.professional_summary',
            ]);

        if (!$contact) {
            return null;
        }

        $contactRole =
            $contact->role === 'student'
            ? 'Student'
            : 'Instructor';

        $contactId =
            $contact->role === 'student'
            ? (
                $contact->student_id
                ? (int) $contact->student_id
                : null
            )
            : (
                $contact->trainer_id
                ? (int) $contact->trainer_id
                : null
            );

        $contactSpecialty = 'Instructor';

        if ($contact->role === 'student') {
            $education = $contact->student_id
                ? DB::table('student_educations')
                ->where(
                    'student_id',
                    $contact->student_id
                )
                ->orderByDesc('is_current')
                ->orderByDesc('id')
                ->first([
                    'major',
                    'department',
                ])
                : null;

            $contactSpecialty =
                $education?->major
                ?: $education?->department
                ?: 'Student';
        } else {
            $contactSpecialty =
                $contact->job_title
                ?: $contact->trainer_department
                ?: 'Instructor';
        }

        $messagesQuery = DB::table('messages')
            ->where(
                'conversation_id',
                $conversationId
            );

        if ($participant?->cleared_at) {
            $messagesQuery->where(
                'created_at',
                '>',
                $participant->cleared_at
            );
        }

        $messages = $messagesQuery
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn($message) =>
                $this->messageData(
                    $message,
                    $studentUserId
                )
            )
            ->values();

        $unreadQuery = DB::table('messages')
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where(
                'sender_id',
                '!=',
                $studentUserId
            )
            ->whereNull('read_at');

        if ($participant?->cleared_at) {
            $unreadQuery->where(
                'created_at',
                '>',
                $participant->cleared_at
            );
        }

        $blockedBy = null;

        if ($conversation->blocked_by_user_id) {
            $blockedBy =
                (int) $conversation->blocked_by_user_id ===
                $studentUserId
                ? 'student'
                : 'other';
        }

        return [
            'id' => (int) $conversation->id,
            'topic' =>
            $conversation->topic
                ?: (
                    $contactRole === 'Student'
                    ? 'Student chat'
                    : 'General'
                ),
            'accepted' =>
            (bool) $conversation->accepted_at,
            'blockedBy' => $blockedBy,
            'unreadForStudent' =>
            $unreadQuery->count(),
            'messages' => $messages,
            'contact' => [
                'id' => $contactId,
                'user_id' =>
                (int) $contact->user_id,
                'type' =>
                $contact->role === 'student'
                    ? 'student'
                    : 'trainer',
                'role' => $contactRole,
                'email' => $contact->email,
                'name' => $contact->name,
                'avatar' =>
                $this->avatarUrl(
                    $contact->avatar
                ),
                'status' =>
                $this->presenceStatus(
                    $contact->last_active_at
                ),
                'specialty' =>
                $contactSpecialty,
            ],
        ];
    }

    private function messageData(
        $message,
        int $studentUserId
    ): array {
        $attachments = DB::table(
            'message_attachments'
        )
            ->where(
                'message_id',
                $message->id
            )
            ->orderBy('id')
            ->get()
            ->map(
                fn($attachment) => [
                    'id' =>
                    (int) $attachment->id,
                    'name' =>
                    $attachment->original_name,
                    'size' =>
                    (int) (
                        $attachment->file_size
                        ?? 0
                    ),
                    'type' =>
                    $attachment->file_type
                        ?? 'application/octet-stream',
                    'dataUrl' =>
                    $this->storageUrl(
                        $attachment->file_path
                    ),
                ]
            )
            ->values();

        return [
            'id' => (int) $message->id,
            'sender' =>
            (int) $message->sender_id ===
                $studentUserId
                ? 'student'
                : 'trainer',
            'text' =>
            $message->deleted_at
                ? ''
                : ($message->message ?? ''),
            'time' => $message->created_at,
            'read' => (bool) $message->read_at,
            'read' => (bool) $message->read_at,

            'deleted' =>
            (bool) $message->deleted_at,

            'canEdit' =>
            (int) $message->sender_id ===
                $studentUserId &&
                !$message->deleted_at &&
                $this->messageIsEditable($message),

            'editedAt' =>
            $message->edited_at,

            'attachments' =>
            $message->deleted_at
                ? []
                : $attachments,
        ];
    }

    private function messageIsEditable(
        $message
    ): bool {
        if (!$message->created_at) {
            return false;
        }

        return Carbon::parse(
            $message->created_at
        )
            ->addMinutes(
                self::EDIT_WINDOW_MINUTES
            )
            ->isFuture();
    }

    private function presenceStatus(
        $lastActiveAt
    ): string {
        if (!$lastActiveAt) {
            return 'offline';
        }

        $minutes = Carbon::parse(
            $lastActiveAt
        )->diffInMinutes(now());

        if ($minutes <= 5) {
            return 'online';
        }

        if ($minutes <= 30) {
            return 'away';
        }

        return 'offline';
    }

    private function avatarUrl(
        $avatar
    ): string {
        if (!$avatar) {
            return '';
        }

        if (
            str_starts_with(
                $avatar,
                'http://'
            ) ||
            str_starts_with(
                $avatar,
                'https://'
            )
        ) {
            return $avatar;
        }

        return $this->storageUrl($avatar);
    }

    private function storageUrl(
        string $path
    ): string {
        return url(
            Storage::url($path)
        );
    }
}
