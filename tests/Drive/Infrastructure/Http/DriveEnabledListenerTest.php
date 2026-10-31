<?php

namespace Repas\Tests\Drive\Infrastructure\Http;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveFeatureException;
use Repas\Drive\Infrastructure\Http\DriveEnabledListener;
use Repas\Drive\Infrastructure\Http\RequiresDrive;
use Repas\Tests\Helper\ControlledDriveFeature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class DriveEnabledListenerTest extends TestCase
{
    public function testAMarkedMethodIsRefusedWhenTheSwitchIsOff(): void
    {
        // Given
        $controller = new class {
            #[RequiresDrive]
            public function __invoke(): void
            {
            }
        };
        $listener = new DriveEnabledListener(new ControlledDriveFeature(false));

        // Then
        $this->expectException(DriveFeatureException::class);
        $this->expectExceptionMessage('DRIVE_DISABLED');
        $this->expectExceptionCode(404);

        // When
        $listener($this->controllerEvent($controller));
    }

    public function testAMarkedClassIsRefusedWhenTheSwitchIsOff(): void
    {
        // Given
        $controller = new #[RequiresDrive] class {
            public function list(): void
            {
            }
        };
        $listener = new DriveEnabledListener(new ControlledDriveFeature(false));

        // Then
        $this->expectException(DriveFeatureException::class);

        // When
        $listener($this->controllerEvent([$controller, 'list']));
    }

    public function testAMarkedControllerPassesWhenTheSwitchIsOn(): void
    {
        // Given
        $controller = new #[RequiresDrive] class {
            public function __invoke(): void
            {
            }
        };
        $listener = new DriveEnabledListener(new ControlledDriveFeature(true));

        // When
        $listener($this->controllerEvent($controller));

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
        $listener = new DriveEnabledListener(new ControlledDriveFeature(false));

        // When
        $listener($this->controllerEvent($controller));

        // Then
        $this->expectNotToPerformAssertions();
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
