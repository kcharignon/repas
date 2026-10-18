<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use ReflectionClass;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou de l'interrupteur `DRIVE_ENABLED` (ADR 0022 règle 10) : une route du drive qui oublie `#[RequiresDrive]` reste
 * ouverte quand la fonction est coupée, et rien d'autre ne le dirait. Toute route de `/api/v1/drive/*`, de `Drive` et de
 * l'envoi d'une liste (`send`, `reopen`) doit porter l'attribut, sauf les deux exceptions de `ALWAYS_ANSWERING` : une exception
 * se décide (ADR 0023 §9), elle ne se glisse pas. Le test échoue aussi dans l'autre sens : une route de la liste qui reçoit
 * l'attribut n'est plus une exception.
 */
class DriveRoutesAreGuardedBySwitchTest extends KernelTestCase
{
    /**
     * Routes du drive qui répondent même quand la fonction est coupée, et pourquoi (liste explicite) :
     * - `GET /drive/settings` répond `enabled: false`, c'est ainsi que le front sait masquer la fonction ;
     * - `POST /shopping-lists/{id}/reopen` (DRV-613, constat C3) : rouvrir une liste n'appelle rien de l'enseigne, et une coupure
     *   d'urgence ne doit pas laisser une liste figée `SENT`. La sécurité et le voter propriétaire restent.
     *
     * @var list<string>
     */
    private const array ALWAYS_ANSWERING = ['api_get_drive_settings', 'api_reopen_shopping_list'];

    /** Routes hors de `/api/v1/drive/` qui appartiennent au drive : l'envoi d'une liste et sa réouverture (exception ci-dessus). */
    private const array SWITCHED_OUTSIDE_THE_DRIVE_PATHS = ['api_send_shopping_list', 'api_reopen_shopping_list'];

    public function testEveryDriveRouteIsSwitchedOffWithTheFunction(): void
    {
        // Given
        self::bootKernel();
        $driveRoutes = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            $inTheDriveContext = str_starts_with($controller, 'Repas\\Drive\\Infrastructure\\Http\\');
            if ($inTheDriveContext || str_starts_with($route->getPath(), '/api/v1/drive/') || in_array($name, self::SWITCHED_OUTSIDE_THE_DRIVE_PATHS, true)) {
                $driveRoutes[$name] = $controller;
            }
        }

        // When
        $unguarded = $guardedButExempt = [];
        foreach ($driveRoutes as $name => $controller) {
            $guarded = $this->isGuarded($controller);
            if (!$guarded && !in_array($name, self::ALWAYS_ANSWERING, true)) {
                $unguarded[] = $name;
            }
            if ($guarded && in_array($name, self::ALWAYS_ANSWERING, true)) {
                $guardedButExempt[] = $name;
            }
        }

        // Then : les dix routes connues sont vues (huit du drive, `send` et `reopen`), seules les exceptions listées n'ont pas l'attribut
        $this->assertGreaterThanOrEqual(10, count($driveRoutes), implode(', ', array_keys($driveRoutes)));
        $this->assertSame([], $unguarded, 'Routes du drive sans #[RequiresDrive] : ' . implode(', ', $unguarded));
        $this->assertSame([], $guardedButExempt, 'Routes qui doivent répondre interrupteur coupé (voir ALWAYS_ANSWERING) mais portent #[RequiresDrive] : ' . implode(', ', $guardedButExempt));
        $this->assertSame(self::ALWAYS_ANSWERING, array_values(array_intersect(self::ALWAYS_ANSWERING, array_keys($driveRoutes))), 'Une exception de la liste ne désigne plus une route du drive');
    }

    /**
     * L'attribut est lu comme le fait `DriveEnabledListener` (`ControllerEvent::getAttributes()`) : sur la classe ou sur la méthode.
     */
    private function isGuarded(string $controller): bool
    {
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller) : [$controller, '__invoke'];
        $reflection = new ReflectionClass($class);

        return $reflection->getAttributes(RequiresDrive::class) !== []
            || ($reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(RequiresDrive::class) !== []);
    }
}
