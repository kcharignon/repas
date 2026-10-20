<?php

namespace Repas\Tests\User\Application;


use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'amorçage des ingrédients (ING-008, ADR 0028 amendement du 2026-10-20, `docs/ingredients/sans-commun.md` § 12 S4) : un
 * compte qui devient ACTIF reçoit sa copie des ingrédients « de base » dans la transaction de l'activation, par l'écouteur de
 * `UserActivatedEvent`. Un chemin d'activation qui oublie l'événement laisse un compte actif sans ingrédient, invisible pour lui : ce test
 * échoue dès qu'un gestionnaire de `src/User/Application` active ou crée un compte sans citer l'événement.
 *
 * « Active ou crée » se reconnaît à l'appel d'une des méthodes ci-dessous. Ajouter un chemin d'activation, c'est l'annoncer ici : soit le
 * gestionnaire dispatche `UserActivatedEvent`, soit il figure dans `EXEMPTIONS` avec sa raison, relue en revue.
 *
 * Pour la connexion avec Google (AUTH-006) : son gestionnaire passe par le résolveur de domaine `ExternalIdentityResolver` (qui crée un compte
 * `ACTIF` ou active un compte `PENDING`, sans pouvoir dispatcher : le domaine n'a pas de répartiteur) ; le nom de ce résolveur figure donc dans
 * la liste, et le gestionnaire devra dispatcher `UserActivatedEvent` après avoir enregistré le compte.
 */
class UserActivationDispatchesEventTest extends TestCase
{
    /** Ce qui, dans un gestionnaire, active ou crée un compte actif. */
    private const array ACTIVATING_CALLS = ['verifyEmail(', 'changeStatus(', 'setStatus(', 'User::create(', 'registerFromIdentity(', 'ExternalIdentityResolver'];

    /**
     * Les gestionnaires dispensés, avec la raison.
     *
     * @var array<string, string>
     */
    private const array EXEMPTIONS = [];

    public function testEveryHandlerThatActivatesAnAccountAnnouncesIt(): void
    {
        // When
        $handlers = self::handlers(dirname(__DIR__, 3) . '/src/User/Application');
        $offenders = self::offendingHandlers($handlers, self::EXEMPTIONS);

        // Then : le test ne doit pas être vide, les gestionnaires qui activent existent bien
        $activating = array_filter($handlers, static fn(string $source): bool => self::activates($source));
        $this->assertGreaterThanOrEqual(3, count($activating), 'Moins de trois gestionnaires qui activent un compte : le garde-fou ne vérifie plus rien.');
        $this->assertSame([], $offenders, "Ces gestionnaires activent un compte sans dispatcher UserActivatedEvent : le compte resterait actif sans ses ingrédients (ING-008).");
    }

    public function testTheThreeCurrentActivationPathsAreTheOnesTheGuardKnows(): void
    {
        // When
        $handlers = self::handlers(dirname(__DIR__, 3) . '/src/User/Application');
        $activating = array_keys(array_filter($handlers, static fn(string $source): bool => self::activates($source)));
        sort($activating);

        // Then : la confirmation d'adresse, le mot de passe oublié, le statut fixé par l'administrateur
        $this->assertSame(['ChangeUserStatus/ChangeUserStatusHandler.php', 'ResetPassword/ResetPasswordHandler.php', 'VerifyEmail/VerifyEmailHandler.php'], $activating);
    }

    public function testRegistrationIsNotAnActivationPath(): void
    {
        // When : l'inscription publique crée le compte en attente, elle ne l'active pas (aucun amorçage avant la confirmation : abus)
        $handlers = self::handlers(dirname(__DIR__, 3) . '/src/User/Application');

        // Then
        $this->assertArrayHasKey('RegisterNewUser/RegisterNewUserHandler.php', $handlers);
        $this->assertFalse(self::activates($handlers['RegisterNewUser/RegisterNewUserHandler.php']));
        $this->assertStringNotContainsString('new UserActivatedEvent(', $handlers['RegisterNewUser/RegisterNewUserHandler.php']);
    }

    public function testTheGuardCatchesAFakeHandlerThatActivatesWithoutTheEvent(): void
    {
        // Given : un répertoire d'essai avec un gestionnaire qui active sans annoncer, un qui annonce, un qui n'active pas
        $directory = sys_get_temp_dir() . '/ing008-guard-' . bin2hex(random_bytes(4));
        mkdir($directory . '/Forgetful', 0700, true);
        mkdir($directory . '/Careful', 0700, true);
        mkdir($directory . '/Innocent', 0700, true);
        file_put_contents($directory . '/Forgetful/ForgetfulHandler.php', '<?php $user->verifyEmail(); $this->users->save($user);');
        file_put_contents($directory . '/Careful/CarefulHandler.php', '<?php $user->changeStatus($status); $this->events->dispatch(new UserActivatedEvent($id));');
        file_put_contents($directory . '/Innocent/InnocentHandler.php', '<?php $user->changePassword($hash);');

        try {
            // When
            $handlers = self::handlers($directory);

            // Then
            $this->assertSame(['Forgetful/ForgetfulHandler.php'], self::offendingHandlers($handlers, []));
            // Une exemption motivée dispense le gestionnaire
            $this->assertSame([], self::offendingHandlers($handlers, ['Forgetful/ForgetfulHandler.php' => 'compte jetable']));
        } finally {
            foreach (glob($directory . '/*/*.php') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $subdirectory) {
                rmdir($subdirectory);
            }
            rmdir($directory);
        }
    }

    public function testAHandlerThatRelaysToTheGoogleResolverMustAnnounceToo(): void
    {
        // Then : AUTH-006 — le résolveur de domaine crée ou active, le gestionnaire doit annoncer
        $this->assertTrue(self::activates('<?php public function __construct(private ExternalIdentityResolver $resolver) {}'));
        $this->assertSame(['SignInWithGoogle/SignInWithGoogleHandler.php'], self::offendingHandlers(['SignInWithGoogle/SignInWithGoogleHandler.php' => '<?php $this->resolver = new ExternalIdentityResolver();'], []));
    }

    /**
     * @return array<string, string> le code source de chaque gestionnaire (`*Handler.php`), par chemin relatif au répertoire
     */
    private static function handlers(string $directory): array
    {
        $handlers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Handler.php')) {
                $handlers[substr($file->getPathname(), strlen($directory) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($handlers);

        return $handlers;
    }

    private static function activates(string $source): bool
    {
        foreach (self::ACTIVATING_CALLS as $call) {
            if (str_contains($source, $call)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $handlers
     * @param array<string, string> $exemptions
     *
     * @return list<string>
     */
    private static function offendingHandlers(array $handlers, array $exemptions): array
    {
        return array_keys(array_filter(
            $handlers,
            static fn(string $source, string $path): bool => self::activates($source) && !str_contains($source, 'new UserActivatedEvent(') && !array_key_exists($path, $exemptions),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
