<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Infrastructure\Http\Form\CreateUnitType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Repas\Repas\Domain\Exception\UnitException;
use Symfony\Component\Form\FormError;
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
            try {
                $this->commandBus->dispatch($command);

                return $this->render('@Repas/Unit/admin_units.html.twig', [
                    'units' => $this->queryBus->ask(new ListUnitsQuery()),
                ]);
            } catch (UnitException $exception) {
                if ($exception->getCode() !== Response::HTTP_CONFLICT) {
                    throw $exception;
                }
                $form->get('name')->addError(new FormError('Une unité de ce nom existe déjà.'));
            }
        }

        return $this->render('@Repas/Unit/_admin_unit_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}
