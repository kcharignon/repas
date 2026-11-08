<?php

namespace Repas\Tests\Helper\Image;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageStorage;
use Repas\Repas\Domain\Model\ProcessedImage;

/**
 * Le stockage des images envoyées, en mémoire (ADR 0039) pour les tests de handlers : aucun fichier. Garde ce qui a été écrit, par chemin, et compte les
 * écritures RÉELLES (une image déjà là n'en compte pas, comme sur le disque).
 */
final class ImageInMemoryStorage implements ImageStorage
{
    /** @var array<string, string> les octets de chaque image, par chemin */
    public array $files = [];
    public int $writes = 0;
    private bool $failing = false;

    public function failing(): self
    {
        $this->failing = true;

        return $this;
    }

    public function store(ProcessedImage $image): string
    {
        if ($this->failing) {
            throw ImageException::storageFailed();
        }

        $path = self::DIRECTORY . '/' . $image->fileName();
        if (!isset($this->files[$path])) {
            $this->files[$path] = $image->bytes;
            ++$this->writes;
        }

        return $path;
    }
}
