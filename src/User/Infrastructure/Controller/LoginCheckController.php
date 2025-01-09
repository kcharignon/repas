<?php

declare(strict_types=1);

namespace Repas\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoginCheckController extends AbstractController
{
    #[Route('/login-check')]
    public function __invoke(): Response
    {
        return $this->render('login_check/index.html.twig');
    }
}
