<?php

namespace App\Enums;

enum QueueTypeEnum: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';
    case PROCESSING = 'processing';
}
