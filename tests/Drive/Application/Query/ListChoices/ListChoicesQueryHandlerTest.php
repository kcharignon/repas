<?php

namespace Repas\Tests\Drive\Application\Query\ListChoices;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Query\ListChoices\AcceptedProductDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceProductDTO;
use Repas\Drive\Application\Query\ListChoices\ListChoicesQuery;
use Repas\Drive\Application\Query\ListChoices\ListChoicesQueryHandler;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\InMemoryLoader\ChoiceInMemoryLoader;

class ListChoicesQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUserAndRetailer(): void
    {
        // Given
        $choices = [new ChoiceDTO('farine', 'Farine', false, [new AcceptedProductDTO(1, new ChoiceProductDTO('p-1', '555111', null, 'Farine T55 1 kg', null))], [], '2026-10-18T10:00:00+02:00')];
        $loader = new ChoiceInMemoryLoader($choices);

        // When
        $result = new ListChoicesQueryHandler($loader)(new ListChoicesQuery('user-42', Retailer::LECLERC));

        // Then
        $this->assertSame($choices, $result);
        $this->assertSame([['user-42', Retailer::LECLERC]], $loader->listCalls);
    }
}
