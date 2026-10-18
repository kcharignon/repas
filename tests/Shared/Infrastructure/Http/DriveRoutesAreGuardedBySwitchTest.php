<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use ReflectionClass;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou de l'interrupteur `DRIVE_ENABLED` (ADR 0022 règle 10) : une route du drive qui oublie `#[RequiresDrive]` reste
 * ouverte quand la fonction est coupée, et rien d'autre ne le dirait. Toute route de `/api/v1/drive/*`, de `Drive` et de
 * l'envoi d'une liste (`send`, `reopen`) doit porter l'attribut, sauf `GET /drive/settings`, qui répond `enabled: false`.
 */
class DriveRoutesAreGuardedBySwitchTest extends KernelTestCase
{
    /** Routes du drive qui répondent même quand la fonction est coupée. */
    private const array ALWAYS_ANSWERING = ['api_get_drive_settings'];

    /** Routes hors de `/api/v1/drive/` qui n'ont de sens que si la fonction est allumée. */
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

        // Then : les dix routes connues sont vues (huit du drive, `send` et `reopen`), aucune n'oublie l'attribut
        $this->assertGreaterThanOrEqual(10, count($driveRoutes), implode(', ', array_keys($driveRoutes)));
        $this->assertSame([], $unguarded, 'Routes du drive sans #[RequiresDrive] : ' . implode(', ', $unguarded));
        $this->assertSame([], $guardedButExempt, 'GET /drive/settings doit répondre `enabled: false` interrupteur coupé : ' . implode(', ', $guardedButExempt));
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
