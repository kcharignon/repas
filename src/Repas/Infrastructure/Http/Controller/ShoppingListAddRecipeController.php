<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ShoppingListAddRecipeController extends AbstractController
{
    #[Route(path: '/shopping-list/add/recipe/{id_recipe}', name: 'view_shopping_list_add_recipe')]
    public function __invoke(): Response
    {
        // TODO: Implement __invoke() method.
        return new Response();
    }
}
