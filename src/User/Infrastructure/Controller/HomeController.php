<?php

namespace Repas\User\Infrastructure\Controller;


use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route("/", name: "view_home", methods: ["GET"])]
    public function __invoke(): Response
    {
        $this->logger->info("HomeController invoked");
        return new Response("Bienvenue dans Repas");
    }
}
