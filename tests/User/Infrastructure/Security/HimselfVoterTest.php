<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledAccessDecisionManager;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\HimselfVoter;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Le droit d'un utilisateur sur son propre compte (`HIMSELF`). Les droits sur les objets de `Repas` et de `Voice` sont jugés par leurs contextes
 * (ADR 0035) : ce voter s'abstient pour leurs attributs.
 */
class HimselfVoterTest extends TestCase
{
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        $this->owner = new UserBuilder()->withId('owner-id')->build();
        $this->other = new UserBuilder()->withId('other-id')->build();
    }

    public function testAUserIsHimself(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, HimselfVoter::HIMSELF, 'owner-id'));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, HimselfVoter::HIMSELF, 'owner-id'));
    }

    public function testAnAdministratorIsAnyone(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, HimselfVoter::HIMSELF, 'owner-id', admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, HimselfVoter::HIMSELF, 'owner-id', admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, HimselfVoter::HIMSELF, 'unknown-id', admin: true));
    }

    public function testADisabledUserIsNobody(): void
    {
        $disabled = new UserBuilder()->withId('owner-id')->isDisabled()->build();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, HimselfVoter::HIMSELF, 'owner-id'));
    }

    public function testAUserOfAnotherKindIsNobody(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('owner-id', null, ['ROLE_USER']), 'main', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, new HimselfVoter(new ControlledAccessDecisionManager(false))->vote($token, 'owner-id', [HimselfVoter::HIMSELF]));
    }

    public function testItAbstainsOnTheAttributesOfTheOtherContexts(): void
    {
        foreach (['INGREDIENT_OWNER', 'SHOPPING_LIST_OWNER', 'RECIPE_OWNER', 'MEAL_OWNER', 'SHOPPING_LIST_ROW_OWNER', 'VOICE_LINK_OWNER', 'OTHER'] as $attribute) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, $attribute, 'owner-id', admin: true), $attribute);
        }
    }

    public function testItAbstainsWhenTheSubjectIsNotAString(): void
    {
        $token = new UsernamePasswordToken($this->owner, 'main', ['ROLE_USER']);
        $voter = new HimselfVoter(new ControlledAccessDecisionManager(true));

        foreach ([null, 12, ['owner-id'], new \stdClass()] as $subject) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $subject, [HimselfVoter::HIMSELF]));
        }
    }

    private function vote(User $user, string $attribute, string $subject, bool $admin = false): int
    {
        return new HimselfVoter(new ControlledAccessDecisionManager($admin))
            ->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }
}
