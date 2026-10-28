<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledSecretGenerator;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\PasswordResetTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\SpyEmailVerificationNotifier;
use Repas\Tests\Helper\SpyRegistrationNotifier;
use Repas\User\Application\SendEmailVerification\SendEmailVerificationCommand;
use Repas\User\Application\SendEmailVerification\SendEmailVerificationHandler;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Service\EmailVerificationIssuer;
use Repas\User\Domain\Service\WelcomeLinkIssuer;

class SendEmailVerificationHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private EmailVerificationTokenInMemoryRepository $tokenRepository;
    private SpyEmailVerificationNotifier $notifier;
    private PasswordResetTokenInMemoryRepository $welcomeTokenRepository;
    private SpyRegistrationNotifier $welcomeNotifier;
    private DateTimeImmutable $now;
    private SendEmailVerificationHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->notifier = new SpyEmailVerificationNotifier();
        $this->welcomeTokenRepository = new PasswordResetTokenInMemoryRepository();
        $this->welcomeNotifier = new SpyRegistrationNotifier();
        $clock = new FrozenClock($this->now);
        $this->handler = new SendEmailVerificationHandler(
            $this->userRepository,
            $this->tokenRepository,
            new EmailVerificationIssuer($this->tokenRepository, $this->notifier, new ControlledSecretGenerator(['new-secret']), new ControlledUuidGenerator(['new-token-id']), $clock),
            $this->welcomeTokenRepository,
            new WelcomeLinkIssuer($this->welcomeTokenRepository, $this->welcomeNotifier, new ControlledSecretGenerator(['welcome-secret']), new ControlledUuidGenerator(['welcome-token-id']), $clock),
            $clock,
        );
    }

    public function testAPendingAccountWithoutAPasswordGetsTheWelcomeLinkNotAnAddressConfirmation(): void
    {
        // Given : inscrit avec sa seule adresse (ADR 0020 amendé) ; confirmer son adresse l'activerait sans qu'il puisse se connecter
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->withoutPassword()->isPending()->build());
        $this->welcomeTokenRepository->add(PasswordResetToken::issueWelcome('old-id', 'user-id', PasswordResetToken::hash('old-welcome'), $this->now->modify('-1 hour')));

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then : le lien de choix du mot de passe, qui remplace l'ancien ; aucun lien de confirmation d'adresse
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'welcome-secret']], $this->welcomeNotifier->welcomes);
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([], $this->tokenRepository->all());
        $this->assertNull($this->welcomeTokenRepository->findOneByTokenHash(PasswordResetToken::hash('old-welcome')));
        $this->assertCount(1, $this->welcomeTokenRepository->all());
        $this->assertSame('2026-10-17 12:00:00', $this->welcomeTokenRepository->all()[0]->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testTheWelcomeLinkIsNotSentAgainWithinTheCooldown(): void
    {
        // Given : un lien de bienvenue parti il y a dix secondes
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->withoutPassword()->isPending()->build());
        $this->welcomeTokenRepository->add(PasswordResetToken::issueWelcome('old-id', 'user-id', PasswordResetToken::hash('old-welcome'), $this->now->modify('-10 seconds')));

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then : rien ne part, le lien précédent reste valable
        $this->assertSame(0, $this->welcomeNotifier->count());
        $this->assertNotNull($this->welcomeTokenRepository->findOneByTokenHash(PasswordResetToken::hash('old-welcome')));
    }

    public function testAPendingAccountReceivesANewLinkThatReplacesTheOldOne(): void
    {
        // Given : un lien envoyé il y a une heure
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('old-token-id', 'user-id', EmailVerificationToken::hash('old-secret'), $this->now->modify('-1 hour')));

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then : un seul jeton valable, celui du nouvel envoi
        $this->assertSame([['email' => 'john.doe@example.com', 'secret' => 'new-secret']], $this->notifier->sent);
        $this->assertNull($this->tokenRepository->findOneByTokenHash(EmailVerificationToken::hash('old-secret')));
        $this->assertNotNull($this->tokenRepository->findOneByTokenHash(EmailVerificationToken::hash('new-secret')));
        $this->assertCount(1, $this->tokenRepository->all());
    }

    public function testTheEmailIsMatchedWithoutCaseNorSurroundingSpaces(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->isPending()->build());

        // When
        ($this->handler)(new SendEmailVerificationCommand('  John.Doe@Example.COM '));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }

    public function testAnUnknownAddressSendsNothingAndRaisesNothing(): void
    {
        // When
        ($this->handler)(new SendEmailVerificationCommand('nobody@example.com'));

        // Then : la réponse ne doit pas révéler quelles adresses sont inscrites
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testAnAlreadyConfirmedAccountSendsNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->build());

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testADisabledAccountSendsNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withEmail('john.doe@example.com')->isDisabled()->build());

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then
        $this->assertSame([], $this->notifier->sent);
    }

    public function testASecondRequestWithinTheCooldownIsIgnored(): void
    {
        // Given : un lien envoyé il y a 30 secondes
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->isPending()->build());
        $recent = EmailVerificationToken::issue('old-token-id', 'user-id', EmailVerificationToken::hash('old-secret'), $this->now->modify('-30 seconds'));
        $this->tokenRepository->add($recent);

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then : ni nouvel email, ni nouveau jeton, l'ancien reste valable
        $this->assertSame([], $this->notifier->sent);
        $this->assertSame([$recent], $this->tokenRepository->all());
    }

    public function testARequestJustAfterTheCooldownSendsAgain(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail('john.doe@example.com')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('old-token-id', 'user-id', EmailVerificationToken::hash('old-secret'), $this->now->modify('-' . EmailVerificationToken::RESEND_COOLDOWN_SECONDS . ' seconds')));

        // When
        ($this->handler)(new SendEmailVerificationCommand('john.doe@example.com'));

        // Then
        $this->assertCount(1, $this->notifier->sent);
    }
}
