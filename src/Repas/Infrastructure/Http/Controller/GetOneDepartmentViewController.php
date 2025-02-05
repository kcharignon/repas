<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetOneDepartmentViewController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }

    #[Route(path: '/department/{slug}', name: 'view_department', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);
        $department = $this->departmentRepository->findOneBySlug($slug);
        $ingredients = $this->ingredientRepository->findByDepartmentAndOwner($department, $connectedUser);

        return $this->render('@Repas/Department/department.html.twig', [
            'department' => $department,
            'ingredients' => $ingredients,
        ]);
    }

}
