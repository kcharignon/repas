<?php

namespace Repas\User\Domain\Service;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class AuthenticatorEmail extends AbstractLoginFormAuthenticator
{


    public function __construct(
        private RouterInterface $router,
        private UserRepository $userRepository,
    ) {
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate('view_login_email');
    }

    public function authenticate(Request $request): Passport
    {
        // Récupérer les données de connexion (email et mot de passe)
        $email = $request->request->get('email', '');
        $password = $request->request->get('password', '');
        $rememberMe = $request->request->has('_remember_me');

        $badges = [
            new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')), // CSRF Token
        ];

        if ($rememberMe) {
            try {
                // Lève une erreur si l'email n'existe pas
                $user = $this->userRepository->findOneByEmail($email);

                // On ne peut pas se souvenir d'un admin
                if (!$user->isAdmin()) {
                    $badges[] = new RememberMeBadge();
                }
            } catch (UserException) {
            }
        }



        // Créer le Passport avec les informations nécessaires
        return new Passport(
            new UserBadge($email), // Trouve l'utilisateur via le UserProvider configuré
            new PasswordCredentials($password), // Valide le mot de passe
            $badges
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('view_departments'));
    }
}
