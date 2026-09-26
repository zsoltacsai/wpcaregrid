<?php

namespace App\Websites;

use App\Models\User;

/**
 * Who did something, for the audit log. The label ("operator:7") is kept
 * for continuity; the type and user ID are stored as their own columns, so
 * customer and operator actions are told apart by data, not by a UI label.
 */
final readonly class Actor
{
    public const CUSTOMER = 'customer';

    public const OPERATOR = 'operator';

    public const SYSTEM = 'system';

    private function __construct(public string $type, public ?int $userId) {}

    public static function user(User $user): self
    {
        return new self($user->isOperator() ? self::OPERATOR : self::CUSTOMER, $user->id);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM, null);
    }

    /** "customer:12", "operator:3", "operator", "system", "pairing"… */
    public static function parse(string $label): self
    {
        if (preg_match('/^(customer|operator):(\d+)$/', $label, $m)) {
            return new self($m[1], (int) $m[2]);
        }

        return new self(in_array($label, [self::CUSTOMER, self::OPERATOR], true) ? $label : self::SYSTEM, null);
    }

    public function label(): string
    {
        return $this->type.($this->userId !== null ? ':'.$this->userId : '');
    }
}
