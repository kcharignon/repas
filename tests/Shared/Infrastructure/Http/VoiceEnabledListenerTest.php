<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Exception\VoiceFeatureException;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Repas\Shared\Infrastructure\Http\VoiceEnabledListener;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class VoiceEnabledListenerTest extends TestCase
{
    public function testAMarkedMethodIsRefusedWhenTheSwitchIsOff(): void
    {
        // Given
        $controller = new class {
            #[RequiresVoice]
            public function __invoke(): void
            {
            }
        };
        $listener = new VoiceEnabledListener(new ControlledVoiceFeature(false));

        // Then
        $this->expectException(VoiceFeatureException::class);
        $this->expectExceptionMessage('VOICE_DISABLED');
        $this->expectExceptionCode(404);

        // When
        $listener($this->controllerEvent($controller));
    }

    public function testAMarkedClassIsRefusedWhenTheSwitchIsOff(): void
    {
        // Given
        $controller = new #[RequiresVoice] class {
            public function list(): void
            {
            }
        };
        $listener = new VoiceEnabledListener(new ControlledVoiceFeature(false));

        // Then
        $this->expectException(VoiceFeatureException::class);

        // When
        $listener($this->controllerEvent([$controller, 'list']));
    }

    public function testAMarkedControllerPassesWhenTheSwitchIsOn(): void
    {
        // Given
        $controller = new #[RequiresVoice] class {
            public function __invoke(): void
            {
            }
        };
        $listener = new VoiceEnabledListener(new ControlledVoiceFeature(true));

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
        $listener = new VoiceEnabledListener(new ControlledVoiceFeature(false));

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
            Request::create('/api/voice/anything'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
