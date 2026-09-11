<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TrainerMessageController extends Controller
{
    private const EDIT_WINDOW_MINUTES = 15;


    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
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
            ->where(
                'cp.user_id',
                $trainer->user_id
            )
            ->whereNull('c.declined_at')
            ->orderByDesc('c.updated_at')
            ->orderByDesc('c.id')
            ->pluck('c.id');

        $conversations = $conversationIds
            ->map(
                fn ($conversationId) =>
                    $this->conversationData(
                        (int) $conversationId,
                        (int) $trainer->user_id
                    )
            )
            ->filter(function ($conversation) {
                if (!$conversation) {
                    return false;
                }

                /*
                 * لا نظهر Request فارغ للمدرب.
                 * الطالب ينشئ المحادثة أولاً،
                 * وتظهر للمدرب بعد إرسال أول رسالة.
                 */
                return
                    $conversation['accepted'] ||
                    count($conversation['messages']) > 0;
            })
            ->values();

        return response()->json([
            'conversations' => $conversations,

            'unread_count' => $conversations
                ->sum('unreadForTrainer'),

            'pending_requests' => $conversations
                ->filter(
                    fn ($conversation) =>
                        !$conversation['accepted']
                )
                ->count(),
        ]);
    }

    public function show(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $conversation = $this->conversationForUser(
            $conversationId,
            (int) $trainer->user_id
        );

        if (
            !$conversation ||
            $conversation->declined_at
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        return response()->json([
            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }


    public function accept(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $conversation = $this->conversationForUser(
            $conversationId,
            (int) $trainer->user_id
        );

        if (
            !$conversation ||
            $conversation->declined_at
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->accepted_at) {
            return response()->json([
                'message' =>
                    'Conversation already accepted.',

                'conversation' =>
                    $this->conversationData(
                        $conversationId,
                        (int) $trainer->user_id
                    ),
            ]);
        }

        $hasMessages = DB::table('messages')
            ->where(
                'conversation_id',
                $conversationId
            )
            ->exists();

        if (!$hasMessages) {
            return response()->json([
                'message' =>
                    'This message request has no messages yet.',
            ], 422);
        }

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'accepted_at' => now(),
                'declined_at' => null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Message request accepted.',

            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }

    public function decline(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $conversation = $this->conversationForUser(
            $conversationId,
            (int) $trainer->user_id
        );

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->accepted_at) {
            return response()->json([
                'message' =>
                    'An accepted conversation cannot be declined.',
            ], 422);
        }

        if ($conversation->declined_at) {
            return response()->json([
                'message' =>
                    'Message request already declined.',
            ]);
        }

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'accepted_at' => null,
                'declined_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Message request declined.',
            'conversation_id' =>
                $conversationId,
        ]);
    }



    public function sendMessage(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $conversation = $this->conversationForUser(
            $conversationId,
            (int) $trainer->user_id
        );

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->declined_at) {
            return response()->json([
                'message' =>
                    'This message request was declined.',
            ], 422);
        }

        if (!$conversation->accepted_at) {
            return response()->json([
                'message' =>
                    'Accept the message request before replying.',
            ], 422);
        }

        if ($conversation->blocked_by_user_id) {
            return response()->json([
                'message' =>
                    'This conversation is blocked.',
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
            (string) (
                $validated['message'] ?? ''
            )
        );

        $files = $request->file(
            'attachments',
            []
        );

        if (
            $text === '' &&
            count($files) === 0
        ) {
            throw ValidationException::withMessages([
                'message' =>
                    'Write a message or attach at least one file.',
            ]);
        }

        $messageId = DB::table('messages')
            ->insertGetId([
                'conversation_id' =>
                    $conversationId,

                'sender_id' =>
                    $trainer->user_id,

                'message' =>
                    $text !== ''
                        ? $text
                        : null,

                'attachment_path' =>
                    null,

                'read_at' =>
                    null,

                'edited_at' =>
                    null,

                'deleted_at' =>
                    null,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        foreach ($files as $file) {
            $path = $file->store(
                'messages/' . $conversationId,
                'public'
            );

            DB::table(
                'message_attachments'
            )->insert([
                'message_id' =>
                    $messageId,

                'original_name' =>
                    $file->getClientOriginalName(),

                'file_path' =>
                    $path,

                'file_type' =>
                    $file->getMimeType(),

                'file_size' =>
                    $file->getSize(),

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
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
                    (int) $trainer->user_id
                ),
        ], 201);
    }



    public function markAsRead(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $trainer->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        DB::transaction(
            function () use (
                $conversationId,
                $trainer
            ) {
                DB::table('messages')
                    ->where(
                        'conversation_id',
                        $conversationId
                    )
                    ->where(
                        'sender_id',
                        '!=',
                        $trainer->user_id
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
                        $trainer->user_id
                    )
                    ->update([
                        'last_read_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        );

        return response()->json([
            'message' =>
                'Conversation marked as read.',

            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }


    public function updateMessage(
        Request $request,
        int $conversationId,
        int $messageId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $trainer->user_id
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
                (int) $trainer->user_id ||
            $message->deleted_at
        ) {
            return response()->json([
                'message' => 'Message not found.',
            ], 404);
        }

        if (
            !$this->messageIsEditable(
                $message
            )
        ) {
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
                'message' => trim(
                    $validated['message']
                ),

                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Message updated.',

            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }


    public function deleteMessage(
        Request $request,
        int $conversationId,
        int $messageId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $trainer->user_id
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
                (int) $trainer->user_id ||
            $message->deleted_at
        ) {
            return response()->json([
                'message' => 'Message not found.',
            ], 404);
        }

        if (
            !$this->messageIsEditable(
                $message
            )
        ) {
            return response()->json([
                'message' =>
                    'The 15-minute delete window has expired.',
            ], 422);
        }

        $attachments = DB::table(
            'message_attachments'
        )
            ->where(
                'message_id',
                $messageId
            )
            ->get();

        foreach (
            $attachments
            as $attachment
        ) {
            if ($attachment->file_path) {
                Storage::disk('public')
                    ->delete(
                        $attachment->file_path
                    );
            }
        }

        DB::transaction(
            function () use (
                $messageId
            ) {
                DB::table(
                    'message_attachments'
                )
                    ->where(
                        'message_id',
                        $messageId
                    )
                    ->delete();

                DB::table('messages')
                    ->where(
                        'id',
                        $messageId
                    )
                    ->update([
                        'message' => null,

                        'attachment_path' =>
                            null,

                        'deleted_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }
        );

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Message deleted.',

            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }



    public function toggleBlock(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $conversation = $this->conversationForUser(
            $conversationId,
            (int) $trainer->user_id
        );

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if (
            $conversation
                ->blocked_by_user_id &&
            (int) $conversation
                ->blocked_by_user_id !==
                (int) $trainer->user_id
        ) {
            return response()->json([
                'message' =>
                    'Only the user who blocked this conversation can unblock it.',
            ], 403);
        }

        $nextBlockedBy =
            $conversation
                ->blocked_by_user_id
                ? null
                : $trainer->user_id;

        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'blocked_by_user_id' =>
                    $nextBlockedBy,

                'updated_at' =>
                    now(),
            ]);

        return response()->json([
            'message' => $nextBlockedBy
                ? 'Conversation blocked.'
                : 'Conversation unblocked.',

            'conversation' =>
                $this->conversationData(
                    $conversationId,
                    (int) $trainer->user_id
                ),
        ]);
    }



    public function clearConversation(
        Request $request,
        int $conversationId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->isParticipant(
                $conversationId,
                (int) $trainer->user_id
            )
        ) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        DB::table(
            'conversation_participants'
        )
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where(
                'user_id',
                $trainer->user_id
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
                    (int) $trainer->user_id
                ),
        ]);
    }


    private function trainerFromRequest(
        Request $request
    ) {
        $user = $request->user();

        if (
            !$user ||
            $user->role !== 'trainer'
        ) {
            return null;
        }

        return DB::table('trainers')
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'status',
                'active'
            )
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
            ->where(
                'user_id',
                $userId
            )
            ->exists();
    }

    private function conversationForUser(
        int $conversationId,
        int $userId
    ) {
        return DB::table(
            'conversations as c'
        )
            ->join(
                'conversation_participants as cp',
                'cp.conversation_id',
                '=',
                'c.id'
            )
            ->where(
                'c.id',
                $conversationId
            )
            ->where(
                'cp.user_id',
                $userId
            )
            ->select('c.*')
            ->first();
    }

    private function conversationData(
        int $conversationId,
        int $trainerUserId
    ): ?array {
        $conversation =
            $this->conversationForUser(
                $conversationId,
                $trainerUserId
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
                $trainerUserId
            )
            ->first();


        $student = DB::table(
            'conversation_participants as cp'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cp.user_id'
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
                $trainerUserId
            )
            ->where(
                'u.role',
                'student'
            )
            ->first([
                'u.id as user_id',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at',
                's.id as student_id',
                's.professional_summary',
            ]);

        if (!$student) {
            return null;
        }

        $education = $student->student_id
            ? DB::table(
                'student_educations'
            )
                ->where(
                    'student_id',
                    $student->student_id
                )
                ->orderByDesc(
                    'is_current'
                )
                ->orderByDesc('id')
                ->first([
                    'major',
                    'department',
                    'university',
                ])
            : null;

        $studentSpecialty =
            $education?->major
            ?: $education?->department
            ?: 'Student';


        $messagesQuery = DB::table(
            'messages'
        )
            ->where(
                'conversation_id',
                $conversationId
            );

        if ($participant?->cleared_at) {
            $messagesQuery
                ->where(
                    'created_at',
                    '>',
                    $participant
                        ->cleared_at
                );
        }

        $messages = $messagesQuery
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($message) =>
                    $this->messageData(
                        $message,
                        $trainerUserId
                    )
            )
            ->values();


        $unreadQuery = DB::table(
            'messages'
        )
            ->where(
                'conversation_id',
                $conversationId
            )
            ->where(
                'sender_id',
                '!=',
                $trainerUserId
            )
            ->whereNull('read_at');

        if ($participant?->cleared_at) {
            $unreadQuery
                ->where(
                    'created_at',
                    '>',
                    $participant
                        ->cleared_at
                );
        }

        /*
         * Who blocked?
         */
        $blockedBy = null;

        if (
            $conversation
                ->blocked_by_user_id
        ) {
            $blockedBy =
                (int) $conversation
                    ->blocked_by_user_id ===
                $trainerUserId
                    ? 'trainer'
                    : 'student';
        }

        return [
            'id' =>
                (int) $conversation->id,

            'topic' =>
                $conversation->topic
                ?: 'Student chat',

            'accepted' =>
                (bool) $conversation
                    ->accepted_at,

            'declined' =>
                (bool) $conversation
                    ->declined_at,

            'blockedBy' =>
                $blockedBy,

            /*
             * ChatApp الحالي يعتمد على
             * هذه الأسماء للمدرب.
             */
            'studentId' =>
                $student->student_id
                    ? (int) $student
                        ->student_id
                    : null,

            'studentUserId' =>
                (int) $student
                    ->user_id,

            'studentName' =>
                $student->name,

            'studentAvatar' =>
                $this->avatarUrl(
                    $student->avatar
                ),

            'studentEmail' =>
                $student->email,

            'studentSpecialty' =>
                $studentSpecialty,

            'studentStatus' =>
                $this->presenceStatus(
                    $student
                        ->last_active_at
                ),

            'unreadForTrainer' =>
                $unreadQuery->count(),

            'messages' =>
                $messages,

            /*
             * نخليه أيضًا بشكل contact
             * للاستفادة منه لاحقًا.
             */
            'contact' => [
                'id' =>
                    $student->student_id
                        ? (int) $student
                            ->student_id
                        : null,

                'user_id' =>
                    (int) $student
                        ->user_id,

                'type' =>
                    'student',

                'role' =>
                    'Student',

                'email' =>
                    $student->email,

                'name' =>
                    $student->name,

                'avatar' =>
                    $this->avatarUrl(
                        $student->avatar
                    ),

                'status' =>
                    $this->presenceStatus(
                        $student
                            ->last_active_at
                    ),

                'specialty' =>
                    $studentSpecialty,

                'course' =>
                    $education?->university
                    ?: 'Compass Academy',
            ],
        ];
    }

    private function messageData(
        $message,
        int $trainerUserId
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
                fn ($attachment) => [
                    'id' =>
                        (int) $attachment->id,

                    'name' =>
                        $attachment
                            ->original_name,

                    'size' =>
                        (int) (
                            $attachment
                                ->file_size
                            ?? 0
                        ),

                    'type' =>
                        $attachment
                            ->file_type
                        ?? 'application/octet-stream',

                    'dataUrl' =>
                        $this->storageUrl(
                            $attachment
                                ->file_path
                        ),
                ]
            )
            ->values();

        return [
            'id' =>
                (int) $message->id,

            'sender' =>
                (int) $message
                    ->sender_id ===
                $trainerUserId
                    ? 'trainer'
                    : 'student',

            'text' =>
                $message->deleted_at
                    ? ''
                    : (
                        $message
                            ->message
                        ?? ''
                    ),

            'time' =>
                $message->created_at,

            'read' =>
                (bool) $message
                    ->read_at,

            'deleted' =>
                (bool) $message
                    ->deleted_at,

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

        return $this->storageUrl(
            $avatar
        );
    }

    private function storageUrl(
        string $path
    ): string {
        return url(
            Storage::url($path)
        );
    }
}