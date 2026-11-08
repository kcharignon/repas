<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveIngredientParent\RemoveIngredientParentCommand;
use Repas\Repas\Application\Command\RemoveIngredientParent\RemoveIngredientParentHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

/**
 * Recoller une partie (ADR 0038, SUB-001) : l'ingrédient redevient ordinaire, sans parent ni ratio. Idempotent, toujours permis, jamais pour
 * l'ingrédient d'un autre (administrateur compris).
 */
class RemoveIngredientParentHandlerTest extends TestCase
{
    private IngredientInMemoryRepository $ingredientRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private RemoveIngredientParentHandler $handler;
    private int $saves = 0;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('user-1')->build();
        $other = new UserBuilder()->withId('user-2')->build();
        $this->saves = 0;
        $test = $this;
        $this->ingredientRepository = new class([
            new IngredientBuilder()->isEgg()->withName('Œuf')->withSlug('oeuf')->withCreator($owner)->build(),
            new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('jaune')->withCreator($owner)->withParent('oeuf', 2.0)->build(),
            new IngredientBuilder()->isEgg()->withName("Blanc d'œuf")->withSlug('blanc')->withCreator($owner)->withParent('oeuf', 1.0)->build(),
            new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('autre-jaune')->withCreator($other)->withParent('autre-oeuf', 1.0)->build(),
        ], $test) extends IngredientInMemoryRepository {
            public function __construct(array $models, private readonly RemoveIngredientParentHandlerTest $test)
            {
                parent::__construct($models);
            }

            public function save(ModelInterface $model): void
            {
                $this->test->countSave();
                parent::save($model);
            }
        };
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->handler = new RemoveIngredientParentHandler($this->ingredientRepository, new IngredientSlugResolver($this->aliasRepository));
    }

    public function countSave(): void
    {
        ++$this->saves;
    }

    public function testAPartBecomesAnOrdinaryIngredientAgainWithNeitherParentNorRatio(): void
    {
        // When
        ($this->handler)(new RemoveIngredientParentCommand('jaune', 'user-1'));

        // Then : les deux colonnes ensemble ; les autres parties du parent ne bougent pas
        $yolk = $this->ingredientRepository->findOneBySlug('jaune');
        $this->assertFalse($yolk->isPart());
        $this->assertSame([null, null], [$yolk->getParentSlug(), $yolk->getParentRatio()]);
        $this->assertSame(['oeuf', 1.0], [$this->ingredientRepository->findOneBySlug('blanc')->getParentSlug(), $this->ingredientRepository->findOneBySlug('blanc')->getParentRatio()]);
        $this->assertSame(1, $this->saves);
    }

    public function testAnIngredientThatIsNotAPartIsAnIdempotentNoOp(): void
    {
        // When : un ingrédient ordinaire, puis un déjà recollé
        ($this->handler)(new RemoveIngredientParentCommand('oeuf', 'user-1'));
        ($this->handler)(new RemoveIngredientParentCommand('jaune', 'user-1'));
        ($this->handler)(new RemoveIngredientParentCommand('jaune', 'user-1'));

        // Then : une seule écriture, celle du premier recollage
        $this->assertSame(1, $this->saves);
        $this->assertFalse($this->ingredientRepository->findOneBySlug('oeuf')->isPart());
    }

    public function testTheIngredientOfAnotherUserIsNotFoundWhoeverAsksAndItKeepsItsParent(): void
    {
        // Given
        $this->expectExceptionObject(IngredientException::notFound('autre-jaune'));

        try {
            // When
            ($this->handler)(new RemoveIngredientParentCommand('autre-jaune', 'user-1'));
        } finally {
            // Then
            $this->assertSame('autre-oeuf', $this->ingredientRepository->findOneBySlug('autre-jaune')->getParentSlug());
            $this->assertSame(0, $this->saves);
        }
    }

    public function testAnUnknownIngredientIsNotFound(): void
    {
        // Then
        $this->expectException(IngredientException::class);

        // When
        ($this->handler)(new RemoveIngredientParentCommand('inconnu', 'user-1'));
    }

    public function testTheSlugIsTranslatedByTheAliasesOfTheUser(): void
    {
        // Given
        $this->aliasRepository->save(IngredientAlias::create('user-1', 'ancien-jaune', 'jaune', AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-11-08 10:00:00')));

        // When
        ($this->handler)(new RemoveIngredientParentCommand('ancien-jaune', 'user-1'));

        // Then
        $this->assertFalse($this->ingredientRepository->findOneBySlug('jaune')->isPart());
    }

    public function testGluingBackIsAlwaysAllowedEvenWhenAListHoldsTheIngredient(): void
    {
        // Given : le recollage ne regarde aucune liste (ADR 0038 décision 9 : ce que la liste a gardé ne se perd jamais) ; le handler n'a même pas
        // de dépôt de listes. Une liste envoyée au drive qui porte une ligne de la partie ne l'en empêche donc pas.
        $lists = new ShoppingListInMemoryRepository();
        $lists->save(new ShoppingListBuilder()->withOwner(new UserBuilder()->withId('user-1')->build())->addIngredient($this->ingredientRepository->findOneBySlug('jaune'))->withStatus(ShoppingListStatus::SENT)->build());

        // When
        ($this->handler)(new RemoveIngredientParentCommand('jaune', 'user-1'));

        // Then
        $this->assertFalse($this->ingredientRepository->findOneBySlug('jaune')->isPart());
    }
}
