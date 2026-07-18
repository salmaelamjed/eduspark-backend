<?php

namespace App\Enums;

enum SenderType: string
{
    case STUDENT = 'student';
    case TEACHER = 'teacher';
    case AI = 'ai';
    case SYSTEM = 'system';
}