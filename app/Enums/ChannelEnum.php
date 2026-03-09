<?php

namespace App\Enums;

enum ChannelEnum: string
{
    case DISCORD = 'discord';
    case TELEGRAM = 'telegram';
    case WHATSAPP = 'whatsapp';
    case CLI = 'cli';
    case WEBSOCKET = 'ws';
}
