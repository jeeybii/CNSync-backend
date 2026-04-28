<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Extracted = 'extracted';
    case Analyzed = 'analyzed';
    case TosReady = 'tos_ready';
    case QuestionsReady = 'questions_ready';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStatuses(), true);
    }

    /**
     * @return array<self>
     */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::Uploaded => [self::Parsing, self::Failed],
            self::Parsing => [self::Extracted, self::Failed],
            self::Extracted => [self::Analyzed, self::Failed],
            self::Analyzed => [self::TosReady, self::Failed],
            self::TosReady => [self::QuestionsReady, self::Failed],
            self::QuestionsReady => [],
            self::Failed => [],
        };
    }
}
