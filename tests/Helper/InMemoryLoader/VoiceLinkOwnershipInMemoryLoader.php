<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\VoiceLinkOwnershipLoader;
use Repas\Voice\Domain\Exception\VoiceException;

/**
 * Double idiot : propriétaires programmés par liaison, introuvable sinon.
 */
class VoiceLinkOwnershipInMemoryLoader implements VoiceLinkOwnershipLoader
{
    /**
     * @param array<string, string> $owners identifiant de liaison => identifiant du propriétaire
     */
    public function __construct(private readonly array $owners = [])
    {
    }

    public function voiceLinkOwnerId(string $linkId): string
    {
        return $this->owners[$linkId] ?? throw VoiceException::linkNotFound();
    }
}
