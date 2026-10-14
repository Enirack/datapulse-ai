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
    public static function present(User $user, ?int $datasetCount = null): array
    {
        $plan = $user->getPlan();

        $data = [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'plan' => $plan->value,
            'limits' => [
                'maxDatasets' => $plan->maxDatasets(),
                'maxUploadSizeBytes' => $plan->maxUploadSizeBytes(),
                'canUseAiAnalyst' => $plan->canUseAiAnalyst(),
                'canApplyCleaning' => $plan->canApplyCleaning(),
            ],
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($datasetCount !== null) {
            $data['usage'] = [
                'datasetCount' => $datasetCount,
            ];
        }

        return $data;
    }
}
