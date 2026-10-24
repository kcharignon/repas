<?php

namespace Repas\Repas\Application\Query\GetCatalogHandoverReport;

use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Le rapport « à blanc » de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6) : le propriétaire, le catalogue face au
 * fichier de classement, les comptes à amorcer, les préconditions, le verdict. Lecture seule, sans paramètre : la passation concerne tout le
 * catalogue.
 */
final readonly class GetCatalogHandoverReportQuery implements QueryInterface
{
}
