<?php

namespace Repas\Drive\Domain\Model;


use Repas\Shared\Domain\Model\Dimension;

/**
 * Unité dans laquelle un produit de l'enseigne exprime son contenu (« 1 kg », « 75 cl », « 6 pièces »), normalisée par l'agent.
 * Le facteur ramène l'unité à l'unité de base de sa dimension (gramme, millilitre, pièce), comme `baseFactor` d'une unité de
 * `Repas`.
 */
enum ContentUnit: string
{
    case G = 'g';
    case KG = 'kg';
    case ML = 'ml';
    case CL = 'cl';
    case L = 'l';
    case PIECE = 'piece';

    public function dimension(): Dimension
    {
        return match ($this) {
            self::G, self::KG => Dimension::WEIGHT,
            self::ML, self::CL, self::L => Dimension::VOLUME,
            self::PIECE => Dimension::COUNT,
        };
    }

    public function factor(): float
    {
        return match ($this) {
            self::G, self::ML, self::PIECE => 1.0,
            self::CL => 10.0,
            self::KG, self::L => 1000.0,
        };
    }
}
