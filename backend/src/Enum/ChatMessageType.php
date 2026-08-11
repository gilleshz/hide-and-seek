<?php

declare(strict_types=1);

namespace App\Enum;

enum ChatMessageType: string
{
    case Text = 'text';
    case System = 'system';
    case Image = 'image';
    case Question = 'question';
    case Answer = 'answer';
    case QuestionInfo = 'question_info';

    /**
     * Question and answer messages are the game log: they carry a questionUuid other
     * features resolve against, so only free-form chatter can be retracted.
     */
    public function isRetractable(): bool
    {
        return match ($this) {
            self::Text, self::Image => true,
            self::System, self::Question, self::Answer, self::QuestionInfo => false,
        };
    }
}
