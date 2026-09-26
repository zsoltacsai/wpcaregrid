<?php

namespace App\Security;

/**
 * How sure the engine is that a finding is what it says, independent of
 * severity (how bad it would be). "confirmed" is only ever set by an operator.
 */
enum Confidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Confirmed = 'confirmed';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Confirmed => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** What a scanner may report: never "confirmed". */
    public static function reportable(): array
    {
        return [self::Low, self::Medium, self::High];
    }

    public function label(): string
    {
        return __('security.confidences.'.$this->value);
    }
}
