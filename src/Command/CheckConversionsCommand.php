<?php

namespace Repas\Command;


use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthAccountDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;
use Repas\Repas\Application\Query\GetConversionHealth\GetConversionHealthQuery;
use Repas\Repas\Application\Query\GetConversionHealth\ReferenceConversionDTO;
use Repas\Repas\Application\Query\GetConversionHealth\RepeatedUniversalConversionDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La santé des conversions, en LECTURE SEULE (`docs/ingredients/sans-commun.md`, conversions universelles) : les conversions universelles de
 * référence (« kilo > gramme »…) sont-elles toutes en base, des conversions propres répètent-elles une paire universelle (une ancienne version de
 * `PUT /api/v1/ingredients/{slug}` rattachait la ligne universelle à l'ingrédient qu'elle enregistrait), et, pour chaque compte, combien d'ingrédients et de
 * lignes de recettes ne se convertissent pas en leur unité d'achat.
 *
 * Un rapport, pas un contrôle : le code de sortie est 0 que le verdict soit VERT ou À REGARDER. Rien n'est écrit, jamais, et la sortie n'a ni adresse
 * e-mail, ni identifiant, ni nom d'ingrédient ou de recette : les comptes sont numérotés et désignés par une référence courte (SHA-256), comme dans le
 * rapport de l'amorçage. Elle s'exécute sur le serveur par la demande `conversions` de l'accès en lecture seule (`repas-ops`).
 */
#[AsCommand(
    name: 'repas:conversions:check',
    description: "Santé des conversions (lecture seule) : universelles de référence, conversions détournées, ingrédients et lignes de recettes non convertibles",
)]
final class CheckConversionsCommand extends Command
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->queryBus->ask(new GetConversionHealthQuery());
        \assert($report instanceof ConversionHealthDTO);

        $io->title('Conversions : santé (lecture seule)');
        $this->renderReference($io, $report);
        $this->renderRepeated($io, $report);
        $this->renderAccounts($io, $report);

        $io->newLine();
        $io->text(sprintf(
            'Totaux : %d ingrédient(s) dont l\'unité des recettes ne se convertit pas en l\'unité d\'achat, %d ligne(s) de recettes qui ne se convertissent pas.',
            $report->ingredientsNotConvertible(),
            $report->recipeRowsNotConvertible(),
        ));
        $io->text('    verdict : ' . ($report->isHealthy() ? 'VERT' : 'À REGARDER'));
        $io->newLine();
        $io->text('Rapport seulement : rien n\'a été modifié.');

        return Command::SUCCESS;
    }

    private function renderReference(SymfonyStyle $io, ConversionHealthDTO $report): void
    {
        $io->text(sprintf(
            'Conversions universelles de référence : %d présente(s) sur %d.',
            $report->presentReference(),
            count($report->reference),
        ));
        foreach ($report->reference as $line) {
            match ($line->state) {
                ReferenceConversionDTO::MISSING => $io->text(sprintf('    manque : %s', $line->label)),
                ReferenceConversionDTO::OTHER_COEFFICIENT => $io->text(sprintf('    autre coefficient : %s attendu, %s en base', $line->label, $line->found)),
                default => null,
            };
        }
        $io->text($report->extraUniversal === []
            ? 'Conversions universelles en plus de la référence : aucune.'
            : 'Conversions universelles en plus de la référence : ' . implode(', ', $report->extraUniversal) . '.');
    }

    private function renderRepeated(SymfonyStyle $io, ConversionHealthDTO $report): void
    {
        $io->newLine();
        if ($report->repeated === []) {
            $io->text('Conversions propres qui répètent une paire universelle : aucune.');

            return;
        }
        $io->text(sprintf(
            'Conversions propres qui répètent une paire universelle : %d dont %d portent l\'identifiant d\'une ligne de référence (détournées).',
            $report->repeatedCount(),
            $report->hijackedCount(),
        ));
        foreach ($report->repeated as $line) {
            \assert($line instanceof RepeatedUniversalConversionDTO);
            $io->text(sprintf('    %s : %d ligne(s)%s', $line->label, $line->count, $line->hijacked > 0 ? sprintf(', dont %d détournée(s)', $line->hijacked) : ''));
        }
    }

    private function renderAccounts(SymfonyStyle $io, ConversionHealthDTO $report): void
    {
        $io->newLine();
        $io->text(sprintf('Comptes : %d.', count($report->accounts)));
        foreach ($report->accounts as $account) {
            \assert($account instanceof ConversionHealthAccountDTO);
            $status = match ($account->status) {
                'ACTIVE' => 'actif',
                'PENDING' => 'en attente',
                'DISABLED' => 'désactivé',
                default => strtolower($account->status),
            };
            $io->text(sprintf(
                'Compte n°%d [%s] (%s) : %d ingrédient(s) dont %d non convertible(s) ; %d recette(s), %d ligne(s) dont %d non convertible(s)',
                $account->rank,
                $account->reference,
                $status,
                $account->ingredients,
                $account->ingredientsNotConvertible,
                $account->recipes,
                $account->recipeRows,
                $account->recipeRowsNotConvertible,
            ));
        }
        if ($report->unattachedIngredientsNotConvertible > 0 || $report->unattachedRecipeRowsNotConvertible > 0) {
            $io->text(sprintf(
                'Sans compte existant : %d ingrédient(s) et %d ligne(s) de recettes non convertible(s).',
                $report->unattachedIngredientsNotConvertible,
                $report->unattachedRecipeRowsNotConvertible,
            ));
        }
    }
}
