<?php

namespace Repas\Tests\Shared\Infrastructure\Mailer;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailContent;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLayout;
use Repas\Shared\Infrastructure\Mailer\BrandedEmailLink;
use Repas\Tests\Helper\VisibleEmailText;
use Symfony\Component\Mime\Address;

class BrandedEmailLayoutTest extends TestCase
{
    private const string LINK = 'https://repas.example.com/mot-de-passe-oublie/confirmation?token=abc123&bienvenue=1';
    private const string ICON = 'https://repas.example.com/icons/icon-192.png';

    public function testTheButtonLinkIsAlsoWrittenInClearInTheHtmlAndInTheText(): void
    {
        // When
        $html = $this->layout()->html($this->content(), 'Objet');
        $text = $this->layout()->text($this->content());

        // Then : le lien du bouton, puis le même lien en clair à copier
        $escaped = htmlspecialchars(self::LINK, ENT_QUOTES);
        $this->assertSame(2, substr_count($html, 'href="' . $escaped . '"'));
        $this->assertSame(1, substr_count($html, '>' . $escaped . '</a>'));
        $this->assertSame(1, preg_match('#^' . preg_quote(self::LINK, '#') . '$#m', $text), 'Le lien est seul sur sa ligne dans le texte.');
    }

    public function testTheTextPartSaysTheSameThingAsTheHtml(): void
    {
        // When
        $content = $this->content(secondary: new BrandedEmailLink('Mot de passe oublié ?', 'Choisis-en un nouveau', 'https://repas.example.com/mot-de-passe-oublie'));
        $html = VisibleEmailText::of($this->layout()->html($content, 'Objet'));
        $text = $this->layout()->text($content);

        // Then
        foreach (['Titre du message', 'Première phrase.', 'Seconde phrase.', 'Mon bouton', 'Ce lien est valable 24 heures.', 'Mot de passe oublié ?', 'Choisis-en un nouveau', "Si tu n'as pas demandé ça, ignore ce message.", 'Tu reçois ce message parce que tu as demandé ça.'] as $sentence) {
            $this->assertStringContainsString($sentence, $html, 'HTML : ' . $sentence);
            $this->assertStringContainsString($sentence, $text, 'Texte : ' . $sentence);
        }
        $this->assertStringContainsString('https://repas.example.com/mot-de-passe-oublie', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function testEveryValueIsEscapedEvenWhenItContainsMarkup(): void
    {
        // Given
        $evil = '<script>alert("x")</script> \' & <b>gras</b>';
        $content = new BrandedEmailContent(
            title: $evil,
            preheader: $evil,
            paragraphs: [$evil],
            buttonLabel: $evil,
            buttonUrl: "https://repas.example.com/a?b=1&c='2'",
            validity: $evil,
            secondary: new BrandedEmailLink($evil, $evil, "https://repas.example.com/b?x=1&y='2'"),
            note: $evil,
            reason: $evil,
        );

        // When
        $html = $this->layout()->html($content, $evil);

        // Then : rien de ce que le contenu porte n'est du HTML
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('alert("x")', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('href="https://repas.example.com/a?b=1&amp;c=&#039;2&#039;"', $html);
        $this->assertStringContainsString('href="https://repas.example.com/b?x=1&amp;y=&#039;2&#039;"', $html);
        $this->assertStringNotContainsString("c='2'", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notWebAddresses(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html;base64,AAAA'];
        yield 'relative' => ['/connexion'];
        yield 'quote' => ['https://repas.example.com/a"onclick="x'];
        yield 'space' => ['https://repas.example.com/a b'];
        yield 'angle' => ['https://repas.example.com/<a>'];
        yield 'empty' => [''];
    }

    #[DataProvider('notWebAddresses')]
    public function testALinkThatIsNotAWebAddressIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->content(url: $url);
    }

    public function testTheOnlyExternalResourceIsTheIconOfTheSiteAndItsAltIsPresent(): void
    {
        // When
        $html = $this->layout()->html($this->content(), 'Objet');

        // Then : une image, celle du site, jamais un pixel, une police, une feuille de style ou un script distants
        preg_match_all('#<img\b[^>]*>#i', $html, $images);
        $this->assertCount(1, $images[0]);
        $this->assertStringContainsString('src="' . self::ICON . '"', $images[0][0]);
        $this->assertMatchesRegularExpression('/\balt="[^"]*"/', $images[0][0], 'alt présent (vide : le nom « Repas » est du texte juste à côté)');
        $this->assertStringContainsString('>Repas</td>', $html);
        foreach (['<link', '<script', '<iframe', '<object', '<embed', '@import', 'url(', 'background="', 'srcset', 'googleapis', 'gstatic', '<video', '<audio'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, $forbidden);
        }
        preg_match_all('#\b(?:src|href)="([^"]*)"#', $html, $addresses);
        foreach ($addresses[1] as $address) {
            $this->assertStringStartsWith('https://repas.example.com/', $address);
        }
    }

    public function testTheIconComesFromTheSiteEvenWhenTheFrontAddressEndsWithASlash(): void
    {
        // When
        $html = new BrandedEmailLayout('https://repas.example.com/')->html($this->content(), 'Objet');

        // Then
        $this->assertStringContainsString('src="' . self::ICON . '"', $html);
    }

    public function testThePreheaderIsHiddenAndComesFirstInTheBody(): void
    {
        // When
        $html = $this->layout()->html($this->content(), 'Objet');

        // Then
        $this->assertMatchesRegularExpression('#<body[^>]*>\s*<div style="display:none;[^"]*">Une accroche cachée\b#', $html);
    }

    public function testTheHtmlIsSafeForMessagingClientsAndHasALightAndADarkTheme(): void
    {
        // When
        $html = $this->layout()->html($this->content(), 'Objet');

        // Then
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html lang="fr"', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('<title>Objet</title>', $html);
        $this->assertStringContainsString('max-width:600px', $html);
        $this->assertStringContainsString('role="presentation"', $html);
        // Le bouton a son repli de couleur (bgcolor) et ne dépend que de styles en ligne
        $this->assertStringContainsString('bgcolor="#1E6B4C"', $html);
        $this->assertSame(0, preg_match('#<table(?![^>]*role="presentation")#', $html), 'Toute table est de mise en page.');
        $this->assertStringContainsString("'Figtree', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif", $html);
    }

    public function testTheVisibleTextOfTheLayoutHasNoExclamationMarkAndShortSentences(): void
    {
        // When
        $html = VisibleEmailText::of($this->layout()->html($this->content(), 'Objet'));

        // Then
        $this->assertStringNotContainsString('!', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(vous|votre|vos)\b|\b\p{L}+ez\b/iu', $html);
        foreach (preg_split('/[.!?:\n]+/u', preg_replace('#https?://\S+#', '', $html)) as $sentence) {
            $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY)), $sentence);
        }
    }

    public function testComposeBuildsAMessageWithBothPartsAndAnObject(): void
    {
        // When
        $email = $this->layout()->compose(Address::create('Repas <no-reply@example.com>'), 'alexiane@example.com', 'Un objet', $this->content());

        // Then
        $this->assertSame('Un objet', $email->getSubject());
        $this->assertSame('alexiane@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.com', $email->getFrom()[0]->getAddress());
        $this->assertNotSame('', trim($email->getTextBody()));
        $this->assertStringContainsString('<html', $email->getHtmlBody());
        $this->assertSame([], $email->getAttachments());
    }

    private function layout(): BrandedEmailLayout
    {
        return new BrandedEmailLayout('https://repas.example.com');
    }

    private function content(string $url = self::LINK, ?BrandedEmailLink $secondary = null): BrandedEmailContent
    {
        return new BrandedEmailContent(
            title: 'Titre du message',
            preheader: 'Une accroche cachée que les messageries montrent après l\'objet, pour donner envie d\'ouvrir.',
            paragraphs: ['Première phrase.', 'Seconde phrase.'],
            buttonLabel: 'Mon bouton',
            buttonUrl: $url,
            validity: 'Ce lien est valable 24 heures.',
            secondary: $secondary,
            note: "Si tu n'as pas demandé ça, ignore ce message.",
            reason: 'Tu reçois ce message parce que tu as demandé ça.',
        );
    }
}
