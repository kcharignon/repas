<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllUnitViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/admin/unit', name: 'view_admin_units', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        return $this->render('@Repas/Unit/admin_units.html.twig', [
            'units' => $this->queryBus->ask(new ListUnitsQuery()),
        ]);
    }
}
