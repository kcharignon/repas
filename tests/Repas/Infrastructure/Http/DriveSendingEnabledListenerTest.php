<?php

namespace Repas\Tests\Repas\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\OpenHostService\DriveSwitchQueryOHS;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Infrastructure\Acl\DriveSwitchQueryAdapter;
use Repas\Repas\Infrastructure\Http\DriveSendingEnabledListener;
use Repas\Repas\Infrastructure\Http\RequiresDriveSending;
use Repas\Tests\Helper\ControlledDriveFeature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * La route d'envoi d'une liste suit l'interrupteur du drive par l'Open Host Service de `Drive` (ADR 0035) : le vrai adapter et le vrai
 * Open Host Service sur le double de l'interrupteur.
 */
class DriveSendingEnabledListenerTest extends TestCase
{
    public function testAMarkedControllerIsRefusedWithTheDriveCodeWhenTheSwitchIsOff(): void
    {
        // Given
        $controller = new #[RequiresDriveSending] class {
            public function __invoke(): void
            {
            }
        };
        $listener = self::listener(false);

        // Then
        $this->expectException(ShoppingListException::class);
        $this->expectExceptionMessage('DRIVE_DISABLED');
        $this->expectExceptionCode(404);

        // When
        $listener($this->controllerEvent($controller));
    }

    public function testAMarkedControllerPassesWhenTheSwitchIsOn(): void
    {
        // Given
        $controller = new #[RequiresDriveSending] class {
            public function __invoke(): void
            {
            }
        };

        // When
        self::listener(true)($this->controllerEvent($controller));

        // Then
        $this->expectNotToPerformAssertions();
    }

    public function testAControllerWithoutTheMarkPassesWhateverTheSwitch(): void
    {
        // Given
        $controller = new class {
            public function __invoke(): void
            {
            }
        };

        // When
        self::listener(false)($this->controllerEvent($controller));

        // Then
        $this->expectNotToPerformAssertions();
    }

    private static function listener(bool $enabled): DriveSendingEnabledListener
    {
        return new DriveSendingEnabledListener(new DriveSwitchQueryAdapter(new DriveSwitchQueryOHS(new ControlledDriveFeature($enabled))));
    }

    private function controllerEvent(callable $controller): ControllerEvent
    {
        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller,
            Request::create('/api/v1/anything'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
