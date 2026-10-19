<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledSecretGenerator;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\PasswordResetTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\SpyPasswordResetNotifier;
use Repas\User\Application\SendPasswordReset\SendPasswordResetCommand;
use Repas\User\Application\SendPasswordReset\SendPasswordResetHandler;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Service\PasswordResetIssuer;

class SendPasswordResetHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private PasswordResetTokenInMemoryRepository $tokenRepository;
    private SpyPasswordResetNotifier $notifier;
    private DateTimeImmutable $now;
    private SendPasswordResetHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new PasswordResetTokenInMemoryRepository();
        $this->notifier = new SpyPasswordResetNotifier();
        $clock = new FrozenClock($this->now);
        $this->handler = new SendPasswordResetHandler(
            $this->userRepository,
            $this->tokenRepository,
            new PasswordResetIssuer($this->tokenRepository, $this->notifier, new ControlledSecretGenerator(['new-secret']), new ControlledUuidGenerator(['new-token-id']), $clock),
            $clock,
        );
    }

    public function testAnActiveAccountReceivesALinkThatExpiresInThirtyMinutes(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->build());

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then : seul le hachage du secret est conservé
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'new-secret']], $this->notifier->sent);
        $token = $this->tokenRepository->findOneByTokenHash(PasswordResetToken::hash('new-secret'));
        $this->assertNotNull($token);
        $this->assertSame('user-id', $token->getUserId());
        $this->assertSame('2026-10-19 12:30:00', $token->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertCount(1, $this->tokenRepository->all());
    }

    public function testANewLinkReplacesTheOldOne(): void
    {
        // Given : un lien envoyé il y a vingt minutes
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->build());
        $this->tokenRepository->add(PasswordResetToken::issue('old-token-id', 'user-id', PasswordResetToken::hash('old-secret'), $this->now->modify('-20 minutes')));

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then : un seul jeton valable, celui du nouvel envoi
        $this->assertNull($this->tokenRepository->findOneByTokenHash(PasswordResetToken::hash('old-secret')));
        $this->assertNotNull($this->tokenRepository->findOneByTokenHash(PasswordResetToken::hash('new-secret')));
        $this->assertCount(1, $this->tokenRepository->all());
    }

    public function testAPendingAccountReceivesALinkToo(): void
    {
        // Given : le lien prouvera son adresse (ADR 0026)
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->isPending()->build());

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }

    public function testAnAccountWithoutAUsablePasswordReceivesALinkToo(): void
    {
        // Given : un compte dont le mot de passe est verrouillé (créé par une connexion Google)
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->withPassword('!')->build());

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }

    public function testTheProtectedAccountReceivesALinkToo(): void
    {
        // Given : la réinitialisation est une action du propriétaire du compte
        $this->userRepository->save(new UserBuilder()->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());

        // When
        ($this->handler)(new SendPasswordResetCommand(User::PROTECTED_EMAIL));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }

    public function testTheEmailIsMatchedWithoutCaseNorSurroundingSpaces(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->build());

        // When
        ($this->handler)(new SendPasswordResetCommand('  John.Doe@Example.COM '));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }

    public function testAnUnknownAddressSendsNothingWritesNothingAndRaisesNothing(): void
    {
        // When
        ($this->handler)(new SendPasswordResetCommand('nobody@example.com'));

        // Then : la réponse ne doit pas révéler quelles adresses sont inscrites
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testADisabledAccountSendsNothingAndWritesNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->isDisabled()->build());
        $existing = PasswordResetToken::issue('old-token-id', 'user-id', PasswordResetToken::hash('old-secret'), $this->now->modify('-20 minutes'));
        $this->tokenRepository->add($existing);

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then : ni email, ni nouveau jeton, ni suppression de l'ancien
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([$existing], $this->tokenRepository->all());
    }

    public function testASecondRequestWithinTheCooldownIsIgnored(): void
    {
        // Given : un lien envoyé il y a 30 secondes
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->build());
        $recent = PasswordResetToken::issue('old-token-id', 'user-id', PasswordResetToken::hash('old-secret'), $this->now->modify('-30 seconds'));
        $this->tokenRepository->add($recent);

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then : ni nouvel email, ni nouveau jeton, l'ancien reste valable
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([$recent], $this->tokenRepository->all());
    }

    public function testARequestJustAfterTheCooldownSendsAgain(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->build());
        $this->tokenRepository->add(PasswordResetToken::issue('old-token-id', 'user-id', PasswordResetToken::hash('old-secret'), $this->now->modify('-' . PasswordResetToken::RESEND_COOLDOWN_SECONDS . ' seconds')));

        // When
        ($this->handler)(new SendPasswordResetCommand('john.doe@example.com'));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }
}
