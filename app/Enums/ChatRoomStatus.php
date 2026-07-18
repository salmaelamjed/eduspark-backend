<?php

namespace App\Enums;

enum ChatRoomStatus: string
{
    case ACTIVE = 'active';
    case CLOSED = 'closed';
}