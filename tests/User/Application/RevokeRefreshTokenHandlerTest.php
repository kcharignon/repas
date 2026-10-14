<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\User\Application\RevokeRefreshToken\RevokeRefreshTokenCommand;
use Repas\User\Application\RevokeRefreshToken\RevokeRefreshTokenHandler;
use Repas\User\Domain\Model\RefreshToken;

class RevokeRefreshTokenHandlerTest extends TestCase
{
    public function testItRemovesTheWholeFamilyOfThePresentedToken(): void
    {
        // Given : une famille de deux jetons (l'un utilisé) et une autre connexion
        $now = new DateTimeImmutable('2026-10-14 12:00:00');
        $first = RefreshToken::startFamily('id-1', 'family', 'user-id', 'hash-1', 0, $now);
        $second = $first->rotate('id-2', 'hash-2', $now);
        $repository = new RefreshTokenInMemoryRepository([$first, $second, RefreshToken::startFamily('id-3', 'other', 'user-id', 'hash-3', 0, $now)]);

        // When
        new RevokeRefreshTokenHandler($repository)(new RevokeRefreshTokenCommand('hash-2'));

        // Then
        $this->assertCount(1, $repository->all());
        $this->assertNotNull($repository->findOneByTokenHash('hash-3'));
    }

    public function testAnUnknownTokenIsNotAnError(): void
    {
        // Given
        $repository = new RefreshTokenInMemoryRepository();

        // When
        new RevokeRefreshTokenHandler($repository)(new RevokeRefreshTokenCommand('unknown'));

        // Then
        $this->assertSame([], $repository->all());
    }
}
