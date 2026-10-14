<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Infrastructure\Http\Form\CreateUnitType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminCreateUnitViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/admin/unit/create', name: 'view_admin_unit_create', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateUnitType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->render('@Repas/Unit/admin_units.html.twig', [
                'units' => $this->queryBus->ask(new ListUnitsQuery()),
            ]);
        }

        return $this->render('@Repas/Unit/_admin_unit_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}
