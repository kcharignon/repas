<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Infrastructure\Http\Form\UpdateUnitType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminUpdateUnitViewController extends AbstractController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private UnitRepository $unitRepository,
    ) {
    }

    #[Route(path: '/admin/unit/{slug}/update', name: 'view_admin_unit_update', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(string $slug, Request $request): Response
    {
        $unit = $this->unitRepository->findOneBySlug($slug);

        $form = $this->createForm(UpdateUnitType::class, $unit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->render('@Repas/Unit/admin_units.html.twig', [
                'units' => $this->unitRepository->findAll(),
            ]);
        }

        return $this->render('@Repas/Unit/_admin_unit_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
