<?php

namespace Repas\User\Domain\Service;


use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class AuthenticatorEmail extends AbstractLoginFormAuthenticator
{


    public function __construct(
        private RouterInterface $router,
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

        // Stocker l'email dans la session pour des raisons de convivialité (facultatif)
        $request->getSession()->set('LAST_USERNAME', $email);

        // Créer le Passport avec les informations nécessaires
        return new Passport(
            new UserBadge($email), // Trouve l'utilisateur via le UserProvider configuré
            new PasswordCredentials($password), // Valide le mot de passe
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')), // CSRF Token
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('view_departments'));
    }
}
