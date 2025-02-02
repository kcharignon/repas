<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class CreateUnitViewController extends AbstractController
{
    #[Route('/unit', name: 'view_unit_add', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        //TODO: implement AddNewUnitController::__invoke() method
        return new Response();
    }

}
