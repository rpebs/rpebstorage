<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Disconnected = 'disconnected';
}
