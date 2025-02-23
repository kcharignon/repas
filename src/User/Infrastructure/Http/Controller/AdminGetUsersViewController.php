<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetUsersViewController extends AbstractController
{
    public function __construct(
        public readonly UserRepository $userRepository,
    ) {
    }

    #[Route(path: '/admin/user', name: 'view_admin_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response
    {
        $users = $this->userRepository->findAll();

        return $this->render('@User/admin_users.html.twig', [
            "users" => $users
        ]);
    }
}
