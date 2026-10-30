<?php

namespace Repas\Repas\Domain\Model;

use Repas\Repas\Domain\Exception\DepartmentException;

/**
 * Les icônes qu'un rayon peut porter (amendement du 2026-10-30 de l'ADR 0028, ING-016 B) : le nom d'une icône que le front sait dessiner. Elle sert
 * à distinguer à l'écran deux ingrédients de même nom dans deux rayons (« Petits pois » surgelés, flocon ; « Petits pois » en conserve, boîte).
 *
 * Une icône de plus s'ajoute par UNE ligne ici (et son dessin dans le front) : rien d'autre ne liste les valeurs permises, ni la validation du
 * corps (`DepartmentPayload`), ni le contrat (`docs/api/openapi.json`, `make openapi`), ni la base (un texte court).
 */
enum DepartmentIcon: string
{
    /** Un flocon : Surgelés. */
    case SNOWFLAKE = 'snowflake';

    /** Une boîte de conserve : Conserves. */
    case CAN = 'can';

    /**
     * L'icône demandée par un client : `null` (« pas d'icône ») ou l'une des valeurs permises, jamais une autre (le cas d'usage ne se fie pas
     * à son appelant, le corps de la requête le contrôle aussi).
     *
     * @throws DepartmentException
     */
    public static function parse(?string $requested): ?self
    {
        return $requested === null ? null : (self::tryFrom($requested) ?? throw DepartmentException::unknownIcon());
    }

    /**
     * La valeur lue en base si elle est une icône connue, sinon `null` : une valeur écrite par une version plus récente du code (une icône que
     * celle-ci ne connaît pas) se lit comme « pas d'icône », sans jamais faire échouer une lecture.
     */
    public static function known(?string $stored): ?string
    {
        return $stored === null ? null : self::tryFrom($stored)?->value;
    }

    /**
     * Les valeurs permises, dans l'ordre de l'énumération.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $icon): string => $icon->value, self::cases());
    }
}
