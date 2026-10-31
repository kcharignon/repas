<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\VoiceLinkOwnershipInMemoryLoader;
use Repas\Tests\Helper\SecurityUserDouble;
use Repas\Voice\Infrastructure\Security\VoiceLinkOwnerVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Le droit sur une liaison avec Alexa (ADR 0029, ADR 0035 : le voter est du contexte `Voice`, qui ne connaît de l'utilisateur du jeton que
 * `AuthenticatedUser`). Aucun passe-droit administrateur.
 */
class VoiceLinkOwnerVoterTest extends TestCase
{
    private SecurityUserDouble $owner;
    private SecurityUserDouble $other;

    protected function setUp(): void
    {
        $this->owner = new SecurityUserDouble('owner-id');
        $this->other = new SecurityUserDouble('other-id');
    }

    public function testOnlyTheOwnerOwnsAVoiceLink(): void
    {
        // Given : la liaison avec Alexa est à owner-id
        $links = new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']);

        // Then
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, 'link-1', $links));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, 'link-1', $links));
    }

    public function testAnAdministratorHasNoPassThrough(): void
    {
        // Given : retirer la liaison d'un autre couperait sa commande vocale
        $links = new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']);

        // Then : un administrateur, jeton d'un autre compte, est refusé ; sa propre liaison reste la sienne
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, 'link-1', $links, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, 'link-1', $links, admin: true));
    }

    public function testAnUnknownLinkIsNotFoundEvenForAnAdministrator(): void
    {
        // Then : sans passe-droit, la liaison est cherchée : un identifiant inconnu donne 404
        $this->expectException(NotFoundHttpException::class);

        $this->vote($this->owner, 'unknown-link', admin: true);
    }

    public function testTheNotFoundKeepsTheMessageOfTheDomain(): void
    {
        try {
            $this->vote($this->owner, 'unknown-link');
            $this->fail('Un 404 est attendu.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testADisabledUserOwnsNothing(): void
    {
        $links = new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new SecurityUserDouble('owner-id', disabled: true), 'link-1', $links));
    }

    public function testAUserOfAnotherKindOwnsNothing(): void
    {
        // Given : un utilisateur de jeton qui n'est pas un AuthenticatedUser
        $links = new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']);
        $token = new UsernamePasswordToken(new InMemoryUser('owner-id', null, ['ROLE_USER']), 'main', ['ROLE_USER']);

        // Then
        $this->assertSame(VoterInterface::ACCESS_DENIED, new VoiceLinkOwnerVoter($links)->vote($token, 'link-1', [VoiceLinkOwnerVoter::VOICE_LINK_OWNER]));
    }

    public function testItAbstainsOnTheAttributesOfTheOtherContexts(): void
    {
        $token = new UsernamePasswordToken($this->owner, 'main', ['ROLE_USER']);
        $voter = new VoiceLinkOwnerVoter(new VoiceLinkOwnershipInMemoryLoader(['owner-id' => 'owner-id']));

        foreach (['HIMSELF', 'INGREDIENT_OWNER', 'SHOPPING_LIST_OWNER', 'RECIPE_OWNER', 'MEAL_OWNER', 'SHOPPING_LIST_ROW_OWNER', 'OTHER'] as $attribute) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, 'owner-id', [$attribute]), $attribute);
        }
    }

    public function testItAbstainsWhenTheSubjectIsNotAString(): void
    {
        $token = new UsernamePasswordToken($this->owner, 'main', ['ROLE_USER']);
        $voter = new VoiceLinkOwnerVoter(new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']));

        foreach ([null, 12, ['link-1'], new \stdClass()] as $subject) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $subject, [VoiceLinkOwnerVoter::VOICE_LINK_OWNER]));
        }
    }

    private function vote(SecurityUserDouble $user, string $subject, ?VoiceLinkOwnershipInMemoryLoader $links = null, bool $admin = false): int
    {
        // Le jeton d'un administrateur porte ROLE_ADMIN ; le voter n'a même pas de gestionnaire de décision pour le lui demander
        $roles = $admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];

        return new VoiceLinkOwnerVoter($links ?? new VoiceLinkOwnershipInMemoryLoader())
            ->vote(new UsernamePasswordToken($user, 'main', $roles), $subject, [VoiceLinkOwnerVoter::VOICE_LINK_OWNER]);
    }
}
