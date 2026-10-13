<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Infrastructure\Http\Form\UserSettingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UpdateUserSettingsViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route('/user/{id}', name: 'view_user_settings', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('HIMSELF', 'id')]
    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->queryBus->ask(new GetCurrentUserQuery($id));

        $form = $this->createForm(UserSettingType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            try {
                $this->commandBus->dispatch($command);
            } catch (UserException $exception) {
                if ($exception->getMessage() !== UserException::INVALID_PASSWORD) {
                    throw $exception;
                }
                $form->get('actualPassword')->addError(new FormError('Mot de passe actuel invalide'));
            }


        }

        return $this->render('@User/_user_setting.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}
