<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Infrastructure\Http\Form\CreateConversionType;
use Repas\Repas\Infrastructure\Http\Form\UpdateConversionType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminUpdateConversionViewController extends AbstractController
{
    public function __construct(
        private readonly ConversionRepository $conversionRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: "/admin/conversion/{id}/update", name: "view_admin_conversion_update", methods: ["GET", "POST"])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(string $id, Request $request): Response
    {
        $conversion = $this->conversionRepository->findById($id);

        $form = $this->createForm(UpdateConversionType::class, $conversion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_admin_conversions');
        }

        return $this->render('@Repas/Conversion/_admin_conversion_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}
