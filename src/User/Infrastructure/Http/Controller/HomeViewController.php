<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeViewController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route("/", name: "view_home", methods: ["GET"])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(): Response
    {
        $this->logger->info("HomeController invoked");
        return $this->render("@User/home.html.twig");
    }
}
