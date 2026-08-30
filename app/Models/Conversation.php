<?php

namespace App\Models;

use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [];

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'conversation_participants'
        )
        ->withPivot('last_read_at')
        ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
