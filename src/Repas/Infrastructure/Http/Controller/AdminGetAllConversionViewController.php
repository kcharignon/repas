<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllConversionViewController extends AbstractController
{
    public function __construct(
        private readonly ConversionRepository $conversionRepository,
    ) {
    }

    #[Route(path: '/admin/conversion', name: 'view_admin_conversions', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        $conversions = $this->conversionRepository->findAll();

        return $this->render('@Repas/Conversion/admin_conversions.html.twig', [
            'conversions' => $conversions,
        ]);
    }

}
