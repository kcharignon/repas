<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ShoppingListRemoveRecipeController extends AbstractController
{
    #[Route(path: '/shopping-list/remove/recipe/{id_recipe}', name: 'view_shopping_list_remove_recipe')]
    public function __invoke(): Response
    {
        // TODO: Implement __invoke() method.
        return new Response();
    }

}
