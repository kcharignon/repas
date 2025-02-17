<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Infrastructure\Http\Form\CreateUnitType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminCreateUnitViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UnitRepository $unitRepository,
    ) {
    }

    #[Route(path: '/admin/unit/create', name: 'view_admin_unit_create', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateUnitType::class);
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
