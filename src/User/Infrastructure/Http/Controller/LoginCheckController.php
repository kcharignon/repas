<?php

declare(strict_types=1);

namespace Repas\User\Infrastructure\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoginCheckController extends AbstractController
{
    #[Route(path:'/login-check', name: 'view_login_check')]
    public function __invoke(): Response
    {
        return $this->render('login_check/index.html.twig');
    }
}
