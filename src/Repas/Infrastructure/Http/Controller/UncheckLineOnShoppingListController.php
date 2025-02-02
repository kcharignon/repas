<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UncheckLineOnShoppingListController extends AbstractController
{
    public function __construct()
    {
    }

    #[Route(path: '/shopping-list/{id}/line/{line}/uncheck', name: 'view_shopping_list_row_uncheck', methods: ['POST'])]
    #[isGranted('ROLE_USER')]
    public function __invoke()
    {
        // TODO: Implement __invoke() method.
    }

}
