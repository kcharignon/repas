<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class UserLoginController extends AbstractController
{


    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route("/login", name: "view_login_email", methods: ["GET", "POST"])]
    public function __invoke(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $identifier = $authenticationUtils->getLastUsername();

        $csrfToken = $this->csrfTokenManager->getToken('authenticate')->getValue();

        return $this->render('@user/login.html.twig', [
            'error' => $error,
            'identifier' => $identifier,
            'csrfToken' => $csrfToken,
        ]);
    }
}
