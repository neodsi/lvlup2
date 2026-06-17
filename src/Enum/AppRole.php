<?php

declare(strict_types=1);

namespace App\Enum;

enum AppRole: string
{
    case AppDefault    = 'app_default';
    case AppModerator  = 'app_moderator';
    case AppAdmin      = 'app_admin';
    case AppSuperAdmin = 'app_super_admin';
}
