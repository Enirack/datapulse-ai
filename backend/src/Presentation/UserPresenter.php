<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\User;

/**
 * Never includes the password hash.
 */
final class UserPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
