<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Http\Form\UserSettingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UpdateUserSettingsViewController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route('/user/{id}', name: 'view_user_settings', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('HIMSELF', 'id')]
    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->userRepository->findOneById($id);

        $form = $this->createForm(UserSettingType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);


        }

        return $this->render('@User/_user_setting.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

}
