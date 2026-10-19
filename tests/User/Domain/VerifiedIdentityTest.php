<?php

namespace Repas\Tests\User\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\VerifiedIdentity;

class VerifiedIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function emails(): iterable
    {
        yield 'Gmail' => ['a@gmail.com', true];
        yield 'Googlemail' => ['a@googlemail.com', true];
        yield 'Gmail en majuscules, normalisé' => ['a@GMAIL.COM', true];
        yield 'Gmail entouré d\'espaces, normalisé' => ['  a@gmail.com ', true];
        yield 'sous-domaine piège' => ['a@gmail.com.evil.com', false];
        yield 'Gmail avant la dernière @' => ['gmail.com@evil.com', false];
        yield 'Gmail dans la partie locale' => ['a.gmail.com@example.com', false];
        yield 'sous-domaine de Gmail' => ['a@mail.gmail.com', false];
        yield 'point final' => ['a@gmail.com.', false];
        yield 'domaine Workspace' => ['a@example.com', false];
        yield 'sans partie locale' => ['@gmail.com', false];
        yield 'sans @' => ['gmail.com', false];
        yield 'deux @, dernière sur Gmail' => ['a@b@gmail.com', true];
        yield 'lettre cyrillique sosie' => ["a@gm\u{0430}il.com", false];
    }

    #[DataProvider('emails')]
    public function testAnEmailIsAuthoritativeOnlyForAVerifiedGmailAddress(string $email, bool $authoritative): void
    {
        // When
        $verified = $this->identity($email, emailVerified: true);
        $unverified = $this->identity($email, emailVerified: false);

        // Then
        $this->assertSame($authoritative, $verified->emailIsAuthoritative());
        $this->assertFalse($unverified->emailIsAuthoritative(), 'une adresse non vérifiée ne fait jamais foi');
    }

    public function testTheEmailIsNormalizedLikeTheLoginIdentifier(): void
    {
        // When
        $identity = $this->identity('  Prenom.Nom@GMail.com ', emailVerified: true);

        // Then
        $this->assertSame('prenom.nom@gmail.com', $identity->email);
    }

    public function testItCarriesWhatTheTokenSays(): void
    {
        // When
        $identity = new VerifiedIdentity(IdentityProvider::GOOGLE, '100000000000000000001', 'a@example.com', true, 'nonce-de-test', 'example.com');

        // Then
        $this->assertSame('100000000000000000001', $identity->subject);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('nonce-de-test', $identity->nonce);
        $this->assertSame('example.com', $identity->hostedDomain);
    }

    public function testAHostedDomainNeverMakesANonGmailAddressAuthoritative(): void
    {
        // When : décision D2, Gmail seulement, `hd` n'est lu par aucune règle
        $identity = new VerifiedIdentity(IdentityProvider::GOOGLE, 'sub', 'a@example.com', true, null, 'example.com');

        // Then
        $this->assertFalse($identity->emailIsAuthoritative());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyIdentities(): iterable
    {
        yield 'sujet vide' => ['', 'a@gmail.com'];
        yield 'sujet fait d\'espaces' => ['   ', 'a@gmail.com'];
        yield 'adresse vide' => ['sub', ''];
        yield 'adresse faite d\'espaces' => ['sub', '   '];
    }

    #[DataProvider('emptyIdentities')]
    public function testAnEmptySubjectOrEmailDesignatesNobody(string $subject, string $email): void
    {
        // Then
        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('Invalid Google credential.');
        $this->expectExceptionCode(401);

        // When
        new VerifiedIdentity(IdentityProvider::GOOGLE, $subject, $email, true, null, null);
    }

    public function testTheProviderIsStoredUnderAStableValue(): void
    {
        $this->assertSame('GOOGLE', IdentityProvider::GOOGLE->value);
    }

    private function identity(string $email, bool $emailVerified): VerifiedIdentity
    {
        return new VerifiedIdentity(IdentityProvider::GOOGLE, '100000000000000000001', $email, $emailVerified, null, null);
    }
}
