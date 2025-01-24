<?php

namespace Repas\User\Infrastructure\Http\Controller;


use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route("/", name: "view_home", methods: ["GET"])]
    public function __invoke(): Response
    {
        $this->logger->info("HomeController invoked");
        return $this->render("@User/home.html.twig");
//        return new Response("Bienvenue dans Repas");
    }
}
