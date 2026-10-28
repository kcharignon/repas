<?php

namespace Repas\Shared\Infrastructure\Mailer;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Mise en page commune à tous les e-mails que le back envoie (ADR 0031), en PHP pur (pas de Twig depuis l'ADR 0018) :
 * un HTML d'e-mail sûr (tables, styles en ligne, 600 px au plus, clair et sombre) ET le même contenu en texte brut.
 *
 * Règles : tout texte est échappé ; le lien du bouton est aussi écrit en clair, dans le HTML et dans le texte ;
 * l'icône du site (`{FRONT_URL}/icons/icon-192.png`) est la seule ressource externe, et rien ne dépend d'elle
 * (le bandeau reste vert, le nom « Repas » est du texte) ; ni pixel, ni suivi, ni police distante.
 * Un nouvel e-mail passe par `compose()` : jamais de HTML écrit à la main dans un notifier.
 */
final readonly class BrandedEmailLayout
{
    /** Chemin de l'icône que le front sert publiquement (`public/icons/icon-192.png` du dépôt du front). */
    public const string ICON_PATH = '/icons/icon-192.png';

    private const string FONT = "'Figtree', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    // Charte « Basilic & safran » (clair), contrastes AA vérifiés : texte blanc sur le vert, encre et gris sur blanc
    private const string GREEN = '#1E6B4C';
    private const string SAFFRON = '#F4B73F';
    private const string PAGE = '#F4F6F3';
    private const string CARD = '#FFFFFF';
    private const string INK = '#16221C';
    private const string MUTED = '#55645C';
    private const string RULE = '#C9D3CC';

    public function __construct(
        #[Autowire('%env(FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    public function compose(Address $from, string $to, string $subject, BrandedEmailContent $content): Email
    {
        return new Email()
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->text($this->text($content))
            ->html($this->html($content, $subject));
    }

    public function text(BrandedEmailContent $content): string
    {
        $parts = ['Repas', $content->title];
        foreach ($content->paragraphs as $paragraph) {
            $parts[] = $paragraph;
        }
        $parts[] = $content->buttonLabel . " :\n" . $content->buttonUrl;
        if (null !== $content->validity) {
            $parts[] = $content->validity;
        }
        if (null !== $content->secondary) {
            $parts[] = $content->secondary->intro . ' ' . $content->secondary->label . " :\n" . $content->secondary->url;
        }
        $parts[] = '--';
        foreach ([$content->note, $content->reason] as $line) {
            if ('' !== $line) {
                $parts[] = $line;
            }
        }
        $parts[] = 'Repas';

        return implode("\n\n", $parts) . "\n";
    }

    public function html(BrandedEmailContent $content, string $subject): string
    {
        $font = self::FONT;
        $icon = $this->h(rtrim($this->frontUrl, '/') . self::ICON_PATH);

        $paragraphs = '';
        foreach ($content->paragraphs as $paragraph) {
            $paragraphs .= '<p class="ink" style="margin:0 0 14px 0;font-size:16px;line-height:1.55;color:' . self::INK . '">' . $this->h($paragraph) . '</p>';
        }

        $url = $this->h($content->buttonUrl);
        $button = <<<HTML
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:340px;margin:10px 0 6px 0">
              <tr>
                <td align="center" bgcolor="#1E6B4C" class="btn" style="background:#1E6B4C;border-radius:12px;mso-padding-alt:15px 16px">
                  <a href="{$url}" class="btna" style="display:block;padding:15px 16px;font-family:{$font};font-size:16px;font-weight:700;line-height:20px;color:#FFFFFF;text-decoration:none;border-radius:12px">{$this->h($content->buttonLabel)}</a>
                </td>
              </tr>
            </table>
            HTML;

        $validity = null === $content->validity ? '' : '<p class="muted" style="margin:6px 0 0 0;font-size:14px;line-height:1.5;color:' . self::MUTED . '">' . $this->h($content->validity) . '</p>';

        $copy = $this->copyBox('Si le bouton ne marche pas, copie cette adresse dans ton navigateur.', $content->buttonUrl);

        $secondary = '';
        if (null !== $content->secondary) {
            $secondary = '<p class="ink" style="margin:22px 0 0 0;font-size:15px;line-height:1.5;color:' . self::INK . '">'
                . $this->h($content->secondary->intro) . ' <a href="' . $this->h($content->secondary->url) . '" class="link" style="color:' . self::GREEN . ';font-weight:700;text-decoration:underline">'
                . $this->h($content->secondary->label) . '</a></p>';
        }

        $note = '' === $content->note ? '' : $this->noteRow($content->note);

        $reason = '' === $content->reason ? '' : '<p class="muted" style="margin:0 0 6px 0;font-size:13px;line-height:1.5;color:' . self::MUTED . '">' . $this->h($content->reason) . '</p>';

        $lang = 'fr';
        $title = $this->h($content->title);
        $preheader = $this->h($content->preheader);
        $subjectEscaped = $this->h($subject);
        $green = self::GREEN;
        $saffron = self::SAFFRON;
        $page = self::PAGE;
        $card = self::CARD;
        $ink = self::INK;
        $muted = self::MUTED;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$lang}" xmlns="http://www.w3.org/1999/xhtml">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <meta name="x-apple-disable-message-reformatting">
            <meta name="color-scheme" content="light dark">
            <meta name="supported-color-schemes" content="light dark">
            <title>{$subjectEscaped}</title>
            <style>
            :root { color-scheme: light dark; supported-color-schemes: light dark; }
            body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            img { -ms-interpolation-mode: bicubic; }
            a { color: {$green}; }
            @media only screen and (max-width: 620px) {
              .gutter { padding-left: 12px !important; padding-right: 12px !important; }
              .px { padding-left: 20px !important; padding-right: 20px !important; }
              .h1 { font-size: 24px !important; }
            }
            @media (prefers-color-scheme: dark) {
              body, .bg { background-color: #0F1713 !important; }
              .card { background-color: #17221C !important; }
              .ink { color: #EEF3EF !important; }
              .muted { color: #A9B8AF !important; }
              .rule { border-color: #2E3F35 !important; }
              .link, a.link { color: #8FD9B2 !important; }
              .box { background-color: #0F1713 !important; border-color: #2E3F35 !important; }
              .btn { background-color: #2B7F5D !important; }
              .btna { color: #FFFFFF !important; }
            }
            </style>
            </head>
            <body class="bg" style="margin:0;padding:0;background-color:{$page}">
            <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:{$page}">{$preheader}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg" bgcolor="{$page}" style="width:100%;background-color:{$page}">
              <tr>
                <td align="center" class="gutter" style="padding:24px 16px">
                  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px">
                    <tr>
                      <td bgcolor="{$green}" class="px" style="background-color:{$green};border-radius:16px 16px 0 0;padding:20px 28px">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                          <tr>
                            <td width="64" valign="middle" style="width:64px"><img src="{$icon}" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border:0;border-radius:14px"></td>
                            <td valign="middle" style="padding-left:12px;font-family:{$font};font-size:28px;font-weight:800;line-height:32px;color:#FFFFFF;letter-spacing:0.5px">Repas</td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                    <tr><td bgcolor="{$saffron}" height="5" style="background-color:{$saffron};height:5px;font-size:0;line-height:0;mso-line-height-rule:exactly">&nbsp;</td></tr>
                    <tr>
                      <td bgcolor="{$card}" class="card px" style="background-color:{$card};border-radius:0 0 16px 16px;padding:32px 28px 30px 28px;font-family:{$font}">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                          <tr><td style="font-family:{$font}">
                            <h1 class="ink h1" style="margin:0 0 16px 0;font-family:{$font};font-size:28px;font-weight:800;line-height:1.25;color:{$ink}">{$title}</h1>
                            {$paragraphs}
                            {$button}
                            {$validity}
                            {$copy}
                            {$secondary}
                          </td></tr>
                          {$note}
                        </table>
                      </td>
                    </tr>
                    <tr>
                      <td class="px" style="padding:18px 28px 4px 28px;font-family:{$font};text-align:center">
                        {$reason}
                        <p class="muted" style="margin:0;font-size:13px;line-height:1.5;font-weight:700;color:{$muted}">Repas</p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
            </body>
            </html>
            HTML;
    }

    private function copyBox(string $intro, string $url): string
    {
        $escaped = $this->h($url);

        return '<p class="muted" style="margin:22px 0 8px 0;font-size:14px;line-height:1.5;color:' . self::MUTED . '">' . $this->h($intro) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="box" bgcolor="' . self::PAGE . '" style="background-color:' . self::PAGE . ';border:1px solid ' . self::RULE . ';border-radius:10px;padding:12px 14px;font-family:' . self::FONT . ';font-size:14px;line-height:1.45;word-break:break-all;overflow-wrap:anywhere">'
            . '<a href="' . $escaped . '" class="link" style="color:' . self::GREEN . ';text-decoration:underline">' . $escaped . '</a>'
            . '</td></tr></table>';
    }

    private function noteRow(string $note): string
    {
        return '<tr><td style="padding:22px 0 0 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="rule" style="border-top:1px solid ' . self::RULE . ';padding:18px 0 0 0;font-family:' . self::FONT . ';font-size:14px;line-height:1.5;color:' . self::MUTED . '">'
            . '<span class="muted">' . $this->h($note) . '</span></td></tr></table></td></tr>';
    }

    private function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
