<?php

namespace App\Enums;

enum ReasonCategory: string
{
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case NotReceived = 'not_received';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged / defective',
            self::WrongItem => 'Wrong or incorrect item',
            self::NotAsDescribed => 'Not as described',
            self::NotReceived => 'Not received',
            self::ChangedMind => 'Changed mind',
            self::Other => 'Other / unclear',
        };
    }

    /** Reasons where the merchant is at fault. */
    public function isMerchantFault(): bool
    {
        return in_array($this, [self::Damaged, self::WrongItem, self::NotAsDescribed], true);
    }
}
