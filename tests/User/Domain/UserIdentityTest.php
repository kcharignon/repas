<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity;

/**
 * Sujets factices uniquement : jamais le `sub` d'un vrai compte Google.
 */
class UserIdentityTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-20 09:00:00');
    }

    public function testALinkKeepsTheAccountTheProviderAndTheSubjectAsGiven(): void
    {
        // When
        $identity = UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, '000000000000000000001', $this->now);

        // Then
        $this->assertSame('identity-id', $identity->getId());
        $this->assertSame('user-id', $identity->getUserId());
        $this->assertSame('000000000000000000001', $identity->getSubject());
        $this->assertEquals($this->now, $identity->getCreatedAt());
    }

    public function testTheFormatOfTheSubjectIsNotAssumedAndItIsKeptUntouched(): void
    {
        // When : ni chiffres, ni casse, ni espaces autour ne sont imposés ou retirés
        $identity = UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, ' Sujet-Test|42 ', $this->now);

        // Then
        $this->assertSame(' Sujet-Test|42 ', $identity->getSubject());
    }

    public function testASubjectOfTheMaximumLengthIsAccepted(): void
    {
        // When
        $identity = UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, str_repeat('9', UserIdentity::SUBJECT_MAX_LENGTH), $this->now);

        // Then
        $this->assertSame(255, strlen($identity->getSubject()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subjectsThatDesignateNobody(): iterable
    {
        yield 'vide' => [''];
        yield 'fait d\'espaces' => ["  \t "];
        yield 'trop long' => [str_repeat('9', 256)];
        yield 'trop long en octets (128 « é » = 256 octets)' => [str_repeat('é', 128)];
    }

    #[DataProvider('subjectsThatDesignateNobody')]
    public function testASubjectThatDesignatesNobodyIsRefusedLikeAnInvalidToken(string $subject): void
    {
        // Then
        $this->expectExceptionObject(IdentityException::invalidCredential());
        $this->expectExceptionCode(401);

        // When
        UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, $subject, $this->now);
    }

    public function testALoadedLinkIsTheOneThatWasCreated(): void
    {
        // Given
        $created = UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, 'sujet-test-1', $this->now);

        // When
        $loaded = UserIdentity::load([
            'id' => 'identity-id',
            'user_id' => 'user-id',
            'provider' => IdentityProvider::GOOGLE,
            'subject' => 'sujet-test-1',
            'created_at' => $this->now,
        ]);

        // Then
        $this->assertEquals($created, $loaded);
        $this->assertTrue($loaded->isEqual($created));
    }

    public function testAnIdentityAlreadyLinkedIsAConflict(): void
    {
        // When
        $exception = IdentityException::alreadyLinked();

        // Then
        $this->assertSame(IdentityException::ALREADY_LINKED, $exception->getMessage());
        $this->assertSame('ALREADY_LINKED', $exception->getMessage());
        $this->assertSame(409, $exception->getCode());
    }
}
