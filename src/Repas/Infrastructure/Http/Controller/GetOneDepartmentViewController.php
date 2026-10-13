<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneDepartmentViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/department/{slug}', name: 'view_department', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof AuthenticatedUser);
        $departmentIngredients = $this->queryBus->ask(
            new ListIngredientsByDepartmentQuery($slug, $connectedUser->getId(), $this->isGranted('ROLE_ADMIN')),
        );
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($connectedUser->getId()));

        return $this->render('@Repas/Department/department.html.twig', [
            'department' => $departmentIngredients->department,
            'ingredients' => $departmentIngredients->ingredients,
            'activeSummary' => $activeSummary,
        ]);
    }

}
