<?php

namespace Repas\Command;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'repas:update-user-statistics',
    description: 'Initialize les statistiques pour tous les utilisateurs',
)]
class UpdateUserStatisticsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly RecipeRepository $recipeRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->userRepository->findAll();
        foreach ($users as $user) {
            // Recuperation des Ingredient de l'utilisateur
            $ingredients = $this->ingredientRepository->findByOwner($user)->filter(fn(Ingredient $ingredient) => $user->isEqual($ingredient->getCreator()))->count();
            $recipes = $this->recipeRepository->findByAuthor($user)->count();

            $statistics = $user->getStatistics();
            $statistics['ingredients'] = $ingredients;
            $statistics['recipes'] = $recipes;

            $user->setStatistics($statistics);

            $this->userRepository->save($user);
        }
        $io->success('Les statistiques utilisateurs ont été initialisées avec succès.');
        return Command::SUCCESS;
    }
}
