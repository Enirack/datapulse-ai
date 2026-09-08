<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Presentation\UserPresenter;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
        }

        $email = \is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = \is_string($payload['password'] ?? null) ? $payload['password'] : '';

        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        $violations->addAll($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)]));

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

        $user = new User($email, '');
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse(UserPresenter::present($user), 201);
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

        return new JsonResponse(UserPresenter::present($user));
    }
}
