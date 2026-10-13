<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListConversions\ListConversionsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllConversionViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/admin/conversion', name: 'view_admin_conversions', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        $conversions = $this->queryBus->ask(new ListConversionsQuery());

        return $this->render('@Repas/Conversion/admin_conversions.html.twig', [
            'conversions' => $conversions,
        ]);
    }

}
