<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\SelectionMode;

class SelectionModeTest extends TestCase
{
    public function testTheConstantForTheOpenApiAttributesIsTheListOfTheCases(): void
    {
        // Then
        $this->assertSame(array_map(static fn(SelectionMode $mode): string => $mode->value, SelectionMode::cases()), SelectionMode::VALUES);
    }
}
