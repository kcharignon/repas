<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Infrastructure\Http\WebDomainExceptionListener;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

class WebDomainExceptionListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function httpCodes(): iterable
    {
        yield 'introuvable' => [404];
        yield 'conflit' => [409];
        yield 'refus métier' => [403];
    }

    #[DataProvider('httpCodes')]
    public function testADomainExceptionBecomesTheHttpErrorOfItsCode(int $code): void
    {
        // Given
        $domainException = new class('Recipe not found', $code) extends DomainException {
        };
        $event = $this->aWebEvent($domainException);

        // When
        (new WebDomainExceptionListener())($event);

        // Then
        $throwable = $event->getThrowable();
        $this->assertInstanceOf(HttpException::class, $throwable);
        $this->assertSame($code, $throwable->getStatusCode());
        $this->assertSame('Recipe not found', $throwable->getMessage());
        $this->assertSame($domainException, $throwable->getPrevious());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function untouched(): iterable
    {
        yield 'code qui n\'est pas une erreur HTTP' => [new class('x', 12) extends DomainException {
        }];
        yield 'code 500' => [new class('x', 500) extends DomainException {
        }];
        yield 'exception inattendue' => [new RuntimeException('boom')];
    }

    #[DataProvider('untouched')]
    public function testOtherThrowablesAreLeftAlone(Throwable $throwable): void
    {
        // Given
        $event = $this->aWebEvent($throwable);

        // When
        (new WebDomainExceptionListener())($event);

        // Then
        $this->assertSame($throwable, $event->getThrowable());
    }

    public function testApiRequestsAreLeftToTheApiListener(): void
    {
        // Given
        $domainException = new class('Recipe not found', 404) extends DomainException {
        };
        $event = $this->aWebEvent($domainException, '/api/v1/recipes/unknown');

        // When
        (new WebDomainExceptionListener())($event);

        // Then
        $this->assertSame($domainException, $event->getThrowable());
    }

    private function aWebEvent(Throwable $throwable, string $path = '/recipe/unknown'): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
