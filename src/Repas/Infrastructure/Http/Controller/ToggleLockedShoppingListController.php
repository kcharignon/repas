<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ToggleLockedShoppingListController extends AbstractController
{
    #[Route(path: 'shopping-list/{id}/locked/{isLocked}', name: 'shopping_list_locked')]
    #[isGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        return new Response('Under construction');
    }

}
