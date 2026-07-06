<?php

namespace App\Support;

final class AuthTokenAbilities
{
    public const APP_ACCESS = 'app:access';

    public const AUTH_LOCAL = 'auth:local';

    public const AUTH_GOOGLE = 'auth:google';

    public const PASSWORD_ROTATE = 'password:rotate';

    public const LOCAL_FULL_ACCESS = [
        self::APP_ACCESS,
        self::AUTH_LOCAL,
    ];

    public const GOOGLE_FULL_ACCESS = [
        self::APP_ACCESS,
        self::AUTH_GOOGLE,
    ];

    public const PASSWORD_ROTATION_ONLY = [
        self::PASSWORD_ROTATE,
    ];

    private function __construct()
    {
    }
}
