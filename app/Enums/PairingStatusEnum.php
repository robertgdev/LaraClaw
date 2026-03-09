<?php

namespace App\Enums;

enum PairingStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
}
