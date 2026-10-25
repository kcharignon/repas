<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\RedirectUrl;

class RedirectUrlTest extends TestCase
{
    public function testTheCodeAndTheStateAreAddedToTheAuthorizedAddress(): void
    {
        $this->assertSame(
            'https://alexa.example.test/api/skill/link/TESTVENDOR?state=xyz123&code=abc-DEF_123',
            RedirectUrl::build('https://alexa.example.test/api/skill/link/TESTVENDOR', 'abc-DEF_123', 'xyz123'),
        );
    }

    public function testTheStateIsEncodedSoItCannotInjectAnotherParameter(): void
    {
        // Given : un state qui tente d'ajouter un paramètre et de changer d'adresse
        $url = RedirectUrl::build('https://alexa.example.test/link', 'code', 'a&code=evil#frag /é');

        // Then
        $this->assertSame('https://alexa.example.test/link?state=a%26code%3Devil%23frag%20%2F%C3%A9&code=code', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['state' => 'a&code=evil#frag /é', 'code' => 'code'], $query);
    }

    public function testAnAddressThatHasAQueryAlreadyGetsAnAmpersand(): void
    {
        $this->assertSame(
            'https://alexa.example.test/link?vendor=1&state=s&code=c',
            RedirectUrl::build('https://alexa.example.test/link?vendor=1', 'c', 's'),
        );
    }
}
