<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Infrastructure\Http\ApiExceptionListener;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

class ApiExceptionListenerTest extends TestCase
{
    public static function domainExceptionDataProvider(): array
    {
        return [
            'not found' => [404, 404],
            'business refusal' => [403, 403],
            'no http code' => [0, 422],
            'not an http code' => [12, 422],
        ];
    }

    #[DataProvider('domainExceptionDataProvider')]
    public function testDomainExceptionKeepsItsHttpCode(int $code, int $expectedStatus): void
    {
        // Given
        $exception = new class('Ingredient not found', $code) extends DomainException {
        };
        $event = $this->anApiEvent($exception);

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertSame($expectedStatus, $event->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Ingredient not found']], $this->body($event));
    }

    public function testHttpExceptionIsMappedToItsStatusWithGenericMessage(): void
    {
        // Given
        $event = $this->anApiEvent(new NotFoundHttpException('No route found for "GET https://host/api/v1/x"'));

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertSame(404, $event->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Not Found']], $this->body($event));
    }

    public function testAccessDeniedIsMappedTo403(): void
    {
        // Given
        $event = $this->anApiEvent(new AccessDeniedHttpException('secret detail'));

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertSame(403, $event->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Forbidden']], $this->body($event));
    }

    public function testValidationFailureIsMappedTo422WithViolations(): void
    {
        // Given
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should be positive.', null, [], null, 'page', 0),
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', ''),
        ]);
        $exception = new HttpException(422, 'Unprocessable Entity', new ValidationFailedException(null, $violations));
        $event = $this->anApiEvent($exception);

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertSame(422, $event->getResponse()->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'page: This value should be positive. name: This value should not be blank.']],
            $this->body($event)
        );
    }

    public function testUnexpectedErrorIsMappedTo500WithoutLeakingTheMessage(): void
    {
        // Given
        $event = $this->anApiEvent(new RuntimeException('SQLSTATE[08006] password=hunter2'));

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertSame(500, $event->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'An unexpected error occurred.']], $this->body($event));
    }

    public function testNonApiRequestIsLeftUntouched(): void
    {
        // Given
        $event = $this->anEvent(new NotFoundHttpException(), '/recipes/unknown');

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertNull($event->getResponse());
    }

    public function testPathStartingWithApiButNotUnderApiIsLeftUntouched(): void
    {
        // Given
        $event = $this->anEvent(new NotFoundHttpException(), '/apiary');

        // When
        (new ApiExceptionListener())($event);

        // Then
        $this->assertNull($event->getResponse());
    }

    private function anApiEvent(Throwable $exception): ExceptionEvent
    {
        return $this->anEvent($exception, '/api/v1/anything');
    }

    private function anEvent(Throwable $exception, string $path): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function body(ExceptionEvent $event): array
    {
        return json_decode($event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
