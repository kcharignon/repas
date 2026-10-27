<?php

namespace Repas\Tests\Shared\Infrastructure\RateLimiter;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\RateLimiter\BoundedLockFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * La fabrique de verrous des compteurs de débit : une ressource a toujours le même verrou, le nombre de verrous (donc de fichiers avec le
 * magasin `flock`) est borné quel que soit le nombre de clés, et un verrou tenu exclut toute autre demande du même verrou.
 */
class BoundedLockFactoryTest extends TestCase
{
    private string $directory;
    private BoundedLockFactory $locks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/repas-locks-' . bin2hex(random_bytes(6));
        $this->locks = new BoundedLockFactory(new FlockStore($this->directory));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testAResourceAlwaysHasTheSameLock(): void
    {
        // When / Then
        $this->assertSame(BoundedLockFactory::slotOf('login_email-abc'), BoundedLockFactory::slotOf('login_email-abc'));
        $this->assertMatchesRegularExpression('/^limiter-slot-\d{3}$/', BoundedLockFactory::slotOf('login_email-abc'));
    }

    public function testThousandsOfKeysUseABoundedNumberOfLockFiles(): void
    {
        // When : cinq mille clés de compteur demandent leur verrou
        $slots = [];
        for ($i = 0; $i < 5000; $i++) {
            $resource = 'login_address_email-' . hash('sha256', (string) $i);
            $slots[BoundedLockFactory::slotOf($resource)] = true;
            $lock = $this->locks->createLock($resource);
            $lock->acquire(true);
            $lock->release();
        }

        // Then : au plus 256 verrous, donc au plus 256 fichiers, et la répartition les utilise presque tous
        $files = glob($this->directory . '/*.lock') ?: [];
        $this->assertLessThanOrEqual(BoundedLockFactory::SLOTS, count($files));
        $this->assertCount(count($slots), $files);
        $this->assertGreaterThan(BoundedLockFactory::SLOTS * 0.9, count($slots));
    }

    public function testAHeldLockExcludesTheSameResourceUntilItIsReleased(): void
    {
        // Given : un verrou tenu, et une autre fabrique sur le même répertoire (un autre processus, ici un autre descripteur de fichier)
        $held = $this->locks->createLock('registration-203.0.113.5');
        $this->assertTrue($held->acquire());
        $other = new BoundedLockFactory(new FlockStore($this->directory));

        // When / Then
        $this->assertFalse($other->createLock('registration-203.0.113.5')->acquire());
        $held->release();
        $second = $other->createLock('registration-203.0.113.5');
        $this->assertTrue($second->acquire());
        $second->release();
    }

    public function testTwoResourcesOfTheSameSlotExcludeEachOther(): void
    {
        // Given : deux ressources qui tombent dans le même verrou
        $first = 'repas_write-user-1';
        $second = null;
        for ($i = 0; $second === null; $i++) {
            $candidate = 'repas_write-user-' . $i . '-bis';
            if (BoundedLockFactory::slotOf($candidate) === BoundedLockFactory::slotOf($first)) {
                $second = $candidate;
            }
        }
        $held = $this->locks->createLock($first);
        $held->acquire();

        // When / Then : c'est la règle de la classe, on ne prend pas un verrou de cette fabrique dans un autre
        $this->assertFalse(new BoundedLockFactory(new FlockStore($this->directory))->createLock($second)->acquire());
        $held->release();
    }
}
