<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllUnitViewController extends AbstractController
{
    public function __construct(
        public UnitRepository $unitRepository,
    ) {
    }

    #[Route(path: '/admin/unit', name: 'view_admin_units', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        $units = $this->unitRepository->findAll();

        return $this->render('@Repas/Unit/admin_units.html.twig', [
            'units' => $units,
        ]);
    }
}
