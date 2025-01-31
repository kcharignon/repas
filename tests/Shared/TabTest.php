<?php

namespace Repas\Tests\Shared;


use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Builder\UserBuilder;
use Repas\User\Domain\Model\User;
use stdClass;

class TabTest extends TestCase
{
    public function goodArrayDataProvider(): array
    {
        return [
            "integer" => [[1, 2, 3], 'integer'],
            "string" => [['a', 'b', 'c'], 'string'],
            "integer with string key" => [['a' => 1, 'b' => 2, 'c' => 3], 'integer'],
            "Object (User)" => [[
                new UserBuilder()->withEmail('un@fake.com')->build(),
                new UserBuilder()->withEmail('deux@fake.com')->build(),
                new UserBuilder()->withEmail('trois@fake.com')->build(),
            ], User::class],
            "StdClass" => [[new StdClass()], 'stdClass'],
        ];
    }

    /**
     * @dataProvider goodArrayDataProvider
     */
    public function testCreateWithArrayThenSuccess(array $datas, string $expectedType): void
    {
        //Act
        $tab = Tab::fromArray($datas);

        //Assert
        $this->assertEquals($expectedType, $tab->getType());
        foreach ($tab as $key => $value) {
            $this->assertEquals($value, $datas[$key]);
        }
    }

    public function wrongArrayDataProvider(): array
    {
        return [
            'integer with string' => [['1', 2]],
            'differents class objects' => [[new UserBuilder()->build(), new StdClass()]],
            'boolean' => [[true, 1]],
            'null is forbidden' => [[2, null]],
        ];
    }

    /**
     * @dataProvider wrongArrayDataProvider
     */
    public function testCreateWithArrayThenFailed(array $datas): void
    {
        //Assert
        $this->expectException(InvalidArgumentException::class);

        //Act
        Tab::fromArray($datas);
    }

    public function testCreateWithElements(): void
    {
        //Act
        $tab = Tab::fromArray(1, 2, 3);

        //Assert
        $this->assertEquals([1, 2, 3], $tab->toArray());
    }

    public function testNewEmpty(): void
    {
        //Arrange
        $tab = Tab::newEmptyTyped('integer');

        //Act
        $tab[] = 2;

        //Assert
        $this->assertCount(1, $tab);
        $this->expectException(InvalidArgumentException::class);
        $tab->add('string');
    }

    public function mapDataProvider(): array
    {
        return [
            'add prefix' => [
                ['test', 'with', 'map'],
                fn(string $item) => "prefix-{$item}",
                ['prefix-test', 'prefix-with', 'prefix-map'],
                'string',
            ],
            'convert to int' => [
                ['0', '45', '12'],
                fn(string $item) => (int) $item,
                [0, 45, 12],
                'integer'
            ],
            'empty' => [
                [],
                fn(int $item) => $item + 10,
                [],
                'mixed',
            ]
        ];
    }

    /**
     * @dataProvider mapDataProvider
     */
    public function testMap(array $initial, Closure $callback, array $expectedValues, string $expectedType): void
    {
        //Arrange
        $tab = Tab::fromArray($initial);

        //Act
        $actual = $tab->map($callback);

        //Assert
        $this->assertEquals($expectedValues, $actual->toArray());
        $this->assertEquals($expectedType, $actual->getType());
    }

    public function testFilter(): void
    {
        // Arrange
        $array = [1, 2, 3, 4, 5];
        $tab = Tab::fromArray(1, 2, 3, 4, 5);
        $closure = fn(int $item) => $item % 2 === 0;

        // Act
        $filteredTab = $tab->filter($closure);
        $filteredArray = array_filter($array, $closure);

        // Assert
        $this->assertEquals($filteredArray, $filteredTab->toArray());
    }

    public function testFind(): void
    {
        // Arrange
        $closure = fn(int $item) => $item === 2;
        $array = ['a' => 1, 'b' => 2, 'c' => 3];
        $tab = Tab::fromArray($array);

        // Act
        $itemTab = $tab->find($closure);
        $itemArray = array_find($array, $closure);

        // Assert
        $this->assertEquals($itemArray, $itemTab);
    }

    public function testFindKey(): void
    {
        // Arrange
        $closure = fn(int $item) => $item === 2;
        $array = ['a' => 1, 'b' => 2, 'c' => 3];
        $tab = Tab::fromArray($array);

        // Act
        $keyTab = $tab->findKey(fn(int $item) => $item === 2);
        $keyArray = array_find_key($array, $closure);

        // Assert
        $this->assertEquals($keyArray, $keyTab);
    }

    public function arraySliceDataProvider(): array
    {
        $array = [1, 2, 3, 4, 5];
        return [
            "standard" => [$array , 1, 3, false],
            "no length" => [$array , 1, null, true],
            "big length" => [$array , 1, 10, true],
            "big offset" => [$array , 10, 1, true],
            "preserve key" => [$array , 1, 3, false],
        ];
    }

    /**
     * @dataProvider arraySliceDataProvider
     */
    public function testSlice(array $array, int $offset, ?int $length, bool $preserveKey): void
    {
        // Arrange
        $tab = Tab::fromArray($array);

        // Act
        $slicedTab = $tab->slice($offset, $length, $preserveKey);
        $slicedArray  = array_slice($array, $offset, $length, $preserveKey);

        // Assert
        $this->assertEquals($slicedArray, $slicedTab->toArray());
    }

    public function testImplode(): void
    {
        // Arrange
        $array = ['une', 'petite', 'phrase.'];
        $tab = Tab::fromArray($array);

        // Act
        $implodedTab = $tab->implode('-');
        $implodedArray = implode('-', $array);

        // Assert
        $this->assertEquals($implodedArray, $implodedTab);
    }

    public function testExplode(): void
    {
        // Act
        $tab = Tab::explode(', ', 'a, b, c');

        // Assert
        $this->assertEquals(['a', 'b', 'c'], $tab->toArray());
    }

    public function testAddThrowsExceptionForInvalidType(): void
    {
        // Arrange
        $tab = Tab::fromArray(1, 2, 3);

        // Expect Exception
        $this->expectException(InvalidArgumentException::class);

        // Act
        $tab->add('string');
    }

    public function testOffsetAccess(): void
    {
        // Arrange
        $array = [1, 2, 3];
        $tab = Tab::fromArray(1, 2, 3);

        // Act
        $array[1] = 4;
        $tab[1] = 4;

        // Assert
        $this->assertEquals($array[1], $tab[1]);

        // Act
        unset($array[1]);
        unset($tab[1]);

        // Assert
        $this->assertEquals($array, $tab->toArray());
    }

    public function testValues(): void
    {
        //Arrange
        $array = ['test' => 1, 2, 5 => 3, 4, '0' => 5, 6];
        $tab = Tab::fromArray($array);

        //Act
        $tabKeys = $tab->values();
        $arrayKeys = array_values($array);

        //Assert
        $this->assertEquals($arrayKeys, $tabKeys);
    }

    public function testKeys(): void
    {
        //Arrange
        $array = ['test' => 1, 2, 5 => 3, 4, '0' => 5, 6];
        $tab = Tab::fromArray($array);

        //Act
        $tabKeys = $tab->keys();
        $arrayKeys = array_keys($array);

        //Assert
        $this->assertEquals($arrayKeys, $tabKeys);
    }

    public function goodArrayMergeDataProvider(): array
    {
        return [
            'integer no key' => [
                [1, 2, 3, 4, 5, 6, 6, 7],
                [1, 2, 3], [4, 5, 6], [6, 7]
            ],
            'integer with key' => [
                ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4],
                ['one' => 1, 'two' => 2, 'three' => 333], ['three' => 3, 'four' => 4]
            ],
        ];
    }

    /**
     * @dataProvider goodArrayMergeDataProvider
     */
    public function testMergeTabThenSuccess(array $expected, array ...$arrays): void
    {
        //Arrange
        $tab = Tab::fromArray(array_shift($arrays));
        $tabs = array_map(fn(array $array) => Tab::fromArray($array), $arrays);

        //Act
        $res = $tab->merge(...$tabs);

        //Assert
        $this->assertEquals($expected, $res->toArray());
    }


    public function testMergeTabThenFail(): void
    {
        //Arrange
        $tab1 = Tab::fromArray(1, 2, 3);
        $tab2 = Tab::fromArray(['4', '5', '6']);

        //Assert
        $this->expectExceptionObject(new InvalidArgumentException('Cannot merge Tab<integer>, with Tab<string>.'));

        //Act
        $tab1->merge($tab2);
    }

    public function testUsort(): void
    {
        // Arrange
        $tab = Tab::fromArray([5, '6' => 2, 1, 3]);

        // Act
        $tab->usort(fn(int $a, int $b) => $a <=> $b);

        // Assert
        $this->assertEquals([1, 2, 3, 5], $tab->toArray());
    }

    public function testCurrent(): void
    {
        // Arrange
        $array = [5, '6' => 2, 1, 3];
        $tab = Tab::fromArray([5, '6' => 2, 1, 3]);

        // Act
        $expectedElement = array_shift($array);
        $actualElement = $tab->shift();

        // Assert
        $this->assertEquals($expectedElement, $actualElement);
        $this->assertEquals($array, $tab->toArray());
    }

    public function uniqueDataProvider(): array
    {
        return [
            "int SORT_NUMERIC" => [[1, 5, 4, 2, 1, 5, 5], SORT_NUMERIC],
            "int SORT_STRING" => [[1, 5, 2, 3, 4, 5], SORT_STRING],
            "string SORT_STRING" => [["a", "b", "b", "a", "c", "b"], SORT_STRING],
        ];
    }

    /**
     * @dataProvider uniqueDataProvider
     */
    public function testUnique(array $in, int $flags): void
    {
        // Arrange
        $tab = Tab::fromArray($in);

        // Act
        $expected = array_unique($in, $flags);
        $actual = $tab->unique($flags);

        // Assert
        $this->assertEquals($expected, $actual->toArray());
    }


    public function testReduce(): void
    {
        // Arrange
        $tab = Tab::fromArray(5, 8, 6);

        // Act
        $actual = $tab->reduce(fn(int $carry, int $b) => $carry + $b, 10);

        // Assert
        $this->assertEquals(29, $actual);
    }
}
