<?php

namespace Repas\Tests\Helper\Http;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Route d'API réservée aux admins, chargée en environnement de test uniquement (config/routes/test/probes.yaml).
 * Sert à vérifier le 403 d'un utilisateur authentifié sans le rôle requis.
 */
final class AdminProbeController extends AbstractController
{
    #[Route(path: '/api/v1/_probe/admin', name: 'test_api_probe_admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['admin' => true]);
    }
}
