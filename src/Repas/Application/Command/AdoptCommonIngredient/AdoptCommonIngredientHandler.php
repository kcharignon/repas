<?php

namespace Repas\Repas\Application\Command\AdoptCommonIngredient;


use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientAliasException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adopte un commun pour un utilisateur (ADR 0028 décisions 1 et 7). Dans l'ordre :
 *
 * 1. l'utilisateur a déjà un alias valide pour ce slug (l'ingrédient qu'il désigne existe et est à lui) : on renvoie cet ingrédient,
 *    rien n'est écrit (rejouable) ; un alias ORPHELIN (la copie a disparu, ING-002) est effacé et l'adoption recommence ;
 * 2. le slug n'est pas celui d'un commun : `IngredientException` ;
 * 3. sinon on choisit un slug libre pour la copie (`IngredientSlugPolicy`, jamais d'écrasement : `IngredientRepository::save()`
 *    remplace un ingrédient de même slug), on écrit l'alias, puis la copie et ses conversions propres.
 *
 * L'alias est écrit AVANT la copie, et c'est lui qui arbitre l'adoption simultanée du même commun : l'`INSERT … ON CONFLICT DO
 * NOTHING` de la deuxième transaction attend la fin de la première, puis ne trouve plus rien à écrire (`save()` renvoie `false`) ;
 * elle rend alors l'ingrédient du gagnant au lieu de créer une deuxième copie. Si la transaction échoue plus loin, l'alias s'annule
 * avec le reste (`command.bus` est transactionnel).
 *
 * Une adoption n'est pas une création : aucun `IngredientCreatedEvent`, aucun `RecipesOrIngredientsCreatedEvent`, les statistiques
 * de l'utilisateur ne bougent pas. Les conversions COMMUNES ne sont pas copiées : elles restent partagées.
 */
#[AsMessageHandler]
readonly class AdoptCommonIngredientHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private IngredientAliasRepository $aliasRepository,
        private IngredientSlugPolicy $slugPolicy,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string le slug de l'ingrédient de l'utilisateur
     * @throws IngredientException le slug est inconnu (404) ou n'est pas celui d'un commun (422)
     * @throws IngredientAliasException la source de l'adoption est vide ou trop longue
     * @throws DepartmentException
     * @throws UnitException
     */
    public function __invoke(AdoptCommonIngredientCommand $command): string
    {
        if ($this->aliasRepository->find($command->userId, $command->commonSlug) !== null) {
            // Un alias valide : l'ingrédient qu'il désigne existe et est à l'utilisateur
            $adopted = $this->aliasRepository->resolve($command->userId, [$command->commonSlug])[$command->commonSlug] ?? null;
            if ($adopted !== null) {
                return $adopted;
            }

            // Un alias orphelin (la copie a disparu : le code d'avant ING-001 supprime une copie sans effacer son alias) : on l'efface
            // et on adopte de nouveau, sinon le commun resterait sans copie ni alias valide pour toujours
            $this->aliasRepository->deleteByAliasSlug($command->userId, $command->commonSlug);
        }

        // Le commun est chargé une seule fois (ADR 0013 règle 7) : la copie et celle de ses conversions partent de cette instance
        $common = $this->ingredientRepository->findOneBySlug($command->commonSlug);
        if ($common->getCreatorId() !== null) {
            throw IngredientException::notCommon($command->commonSlug);
        }

        $slug = $this->slugPolicy->forAdoption(
            $common->getName(),
            $command->userId,
            fn(string $candidate): bool => $this->ingredientRepository->exists($candidate),
        );

        $alias = IngredientAlias::create(
            $command->userId,
            $command->commonSlug,
            $slug,
            AliasKind::ADOPTION,
            $command->source,
            $this->clock->now(),
        );
        if (!$this->aliasRepository->save($alias)) {
            // Une adoption simultanée du même commun l'a emporté : son ingrédient est celui de l'utilisateur
            return $this->aliasRepository->find($command->userId, $command->commonSlug)?->getIngredientSlug()
                ?? throw new \LogicException(sprintf("L'alias de '%s' était en conflit puis a disparu pendant l'adoption, l'appelant peut recommencer.", $command->commonSlug));
        }

        $copy = Ingredient::adopt($common, $command->userId, $slug);
        $this->ingredientRepository->save($copy);

        foreach ($this->conversionRepository->findByIngredient($common) as $conversion) {
            $this->conversionRepository->save(
                Conversion::copyFromOriginal($this->uuidGenerator->generate(), $conversion, $copy),
            );
        }

        return $slug;
    }
}
