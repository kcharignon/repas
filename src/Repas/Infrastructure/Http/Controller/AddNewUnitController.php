<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class AddNewUnitController extends AbstractController
{
    #[Route('addNewUnit', name: 'addNewUnit')]
    public function __invoke(Request $request): Response
    {

    }

}
