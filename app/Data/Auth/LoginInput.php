<?php

declare(strict_types=1);

namespace App\Data\Auth;

use SensitiveParameter;

final readonly class LoginInput
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $password,
        public string $deviceName,
    ) {}
}
