<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Http\Form\RegisterNewUserType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class RegistrationViewController extends AbstractController
{


    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route(path: '/registration', name: 'view_registration', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(RegisterNewUserType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Grâce à empty_data, $form->getData() est l’instance de RegisterNewUserCommand
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            $user = $this->userRepository->findOneByEmail($command->email);

            // Création du token pour le firewall "main"
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

            // Définir le token dans le tokenStorage
            $this->container->get('security.token_storage')->setToken($token);

            // Enregistrer le token en session
            $request->getSession()->set('_security_main', serialize($token));
            $request->getSession()->save();

            return $this->redirectToRoute('view_home');
        }

        return $this->render('@User/registration.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
