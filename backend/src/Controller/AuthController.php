<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Presentation\UserPresenter;
use App\Enum\Plan;
use App\Repository\DatasetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuthController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly DatasetRepository $datasetRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly string $inviteKeys,
    ) {
    }

    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
        }

        $rawEmail = \is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = \is_string($payload['password'] ?? null) ? $payload['password'] : '';
        $rawAccessKey = \is_string($payload['access_key'] ?? null)
            ? trim($payload['access_key'])
            : (\is_string($payload['accessKey'] ?? null) ? trim($payload['accessKey']) : '');

        if (str_contains($rawEmail, "\0") || str_contains($password, "\0") || str_contains($rawAccessKey, "\0")) {
            return new JsonResponse(['status' => 'error', 'message' => 'Null bytes are not permitted.'], 400);
        }

        $validKeys = array_values(array_filter(array_map('trim', explode(',', $this->inviteKeys))));
        if ($rawAccessKey === '' || !\in_array($rawAccessKey, $validKeys, true)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Clé d'accès requise ou invalide. Pour obtenir une clé d'accès au projet, veuillez contacter Enirack sur GitHub ou par email.",
                'contact' => [
                    'author' => 'Enirack',
                    'github' => 'https://github.com/Enirack',
                    'email' => 'enirackjerrydada@gmail.com',
                ],
            ], 403);
        }

        $email = mb_strtolower($rawEmail);

        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        $violations->addAll($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 128)]));

        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            return new JsonResponse(['status' => 'error', 'message' => 'Invalid registration data.', 'errors' => $errors], 422);
        }

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            return new JsonResponse(['status' => 'error', 'message' => 'An account with this email already exists.'], 422);
        }

        $isProKey = str_contains(strtoupper($rawAccessKey), 'VIP') || str_contains(strtoupper($rawAccessKey), 'PRO');
        $initialPlan = $isProKey ? Plan::Pro : Plan::Free;

        $user = new User($email, '', $initialPlan);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse(UserPresenter::present($user, 0), 201);
    }

    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): never
    {
        // Intercepted by the json_login authenticator on the "login" firewall
        // before the router ever dispatches to this action.
        throw new \LogicException('This should never be reached.');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Authentication required.'], 401);
        }

        $count = $this->datasetRepository->countForOwner($user);

        return new JsonResponse(UserPresenter::present($user, $count));
    }

    #[Route('/api/me/upgrade', name: 'api_me_upgrade', methods: ['POST'])]
    public function upgrade(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Authentication required.'], 401);
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
        }

        $targetPlan = strtolower((string) ($payload['plan'] ?? $payload['targetPlan'] ?? 'pro'));
        $planEnum = match ($targetPlan) {
            'business' => Plan::Business,
            'free' => Plan::Free,
            default => Plan::Pro,
        };

        $user->setPlan($planEnum);
        $this->em->flush();

        $count = $this->datasetRepository->countForOwner($user);

        return new JsonResponse(UserPresenter::present($user, $count));
    }
}
