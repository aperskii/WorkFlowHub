<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Done = 'done';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Todo => __('Todo'),
            self::InProgress => __('In progress'),
            self::InReview => __('In review'),
            self::Done => __('Done'),
        };
    }

    /**
     * Determine whether a task in this status still counts as open work.
     */
    public function isOpen(): bool
    {
        return $this !== self::Done;
    }

    /**
     * Get the Flux badge colour used to present the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Todo => 'zinc',
            self::InProgress => 'sky',
            self::InReview => 'amber',
            self::Done => 'lime',
        };
    }
}
