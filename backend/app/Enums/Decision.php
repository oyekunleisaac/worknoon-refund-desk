<?php

namespace App\Enums;

enum Decision: string
{
    case Approved = 'approved';
    case Denied = 'denied';
    case Escalated = 'escalated';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
