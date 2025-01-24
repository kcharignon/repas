<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CreateNewShoppingListController
{
    #[Route(path: '/shopping-list', name: 'view_shopping_list_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        return new Response("Under construction");
    }
}
