<?php

namespace App\Enums;

enum Role: string
{
    case Requester = 'requester';
    case Approver = 'approver';
    case Custodian = 'custodian';

    public function label(): string
    {
        return match ($this) {
            self::Requester => 'Requester',
            self::Approver => 'Approving Staff',
            self::Custodian => 'Document Custodian',
        };
    }
}
