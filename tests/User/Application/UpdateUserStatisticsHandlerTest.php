<?php

namespace Repas\Tests\User\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\UpdateUserStatistics\UpdateUserStatisticsCommand;
use Repas\User\Application\UpdateUserStatistics\UpdateUserStatisticsHandler;
use Repas\User\Domain\Interface\UserRepository;

class UpdateUserStatisticsHandlerTest extends TestCase
{
    private readonly UpdateUserStatisticsHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly ClockInterface $frozenClock;

    protected function setUp(): void
    {
        $this->frozenClock = new FrozenClock();

        $this->userRepository = new UserInMemoryRepository([
            new UserBuilder()->withId('no-stats-id')->withCreatedAt($this->frozenClock->now())->build(),
            new UserBuilder()->withId('with-stats-id')->withRecipeAndIngredientStats(8, 20)->withCreatedAt($this->frozenClock->now())->build(),
        ]);

        $this->handler = new UpdateUserStatisticsHandler(
            $this->userRepository,
        );
    }

    public static function updateUserStatisticsDataProvider(): array
    {
        return [
            "no-stats with ingredient only" => ['no-stats-id', [10, 0], [10, 0]],
            "with-stats with ingredient only" => ['with-stats-id', [10, 0], [30, 8]],
            "no-stats with recipe only" => ['no-stats-id', [0, 10], [0, 10]],
            "with-stats with recipe only" => ['with-stats-id', [0, 10], [20, 18]],
            "no-stats" => ['no-stats-id', [5, 1], [5, 1]],
            "with-stats" => ['with-stats-id', [2, 11], [22, 19]],
        ];
    }

    #[DataProvider('updateUserStatisticsDataProvider')]
    public function testSuccessfullyHandleUpdateUserStatistics(string $userId, $add, $expected): void
    {
        // Arrange
        $command = new UpdateUserStatisticsCommand($userId, ...$add);

        // Act
        ($this->handler)($command);

        // Assert
        $expected = [
            'createdAt' => $this->frozenClock->now(),
            'ingredients' => $expected[0],
            'recipes' => $expected[1],
        ];
        $actual = $this->userRepository->findOneById($userId)->getStatistics();
        $this->assertEquals($expected, $actual);
    }
}
