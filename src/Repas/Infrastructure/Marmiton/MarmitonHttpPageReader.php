<?php

namespace Repas\Repas\Infrastructure\Marmiton;


use Psr\Log\LoggerInterface;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lit une page de recette Marmiton par HTTP (ADR 0037 décisions 12 et 13, ticket MAR-001). Ce que cette classe garantit :
 *
 * - **Personne ne choisit l'adresse appelée.** Elle n'appelle que `MarmitonUrl::fetchUrl()` : `https://www.marmiton.org/recettes/recette_{nom}_{id}.aspx`,
 *   reconstruite par le domaine. Aucun en-tête, cookie ni donnée de la personne n'est transmis.
 * - **Jamais vers une adresse privée.** Le nom `www.marmiton.org` est résolu ICI (IPv4), chaque adresse doit être publique
 *   (`FILTER_FLAG_GLOBAL_RANGE` : ni privée, ni loopback, ni lien local, ni partagée, ni réservée, ni de documentation), sinon `unavailable` sans
 *   aucune connexion ; le client HTTP est ensuite obligé de se connecter à cette adresse (option `resolve`, TLS vérifié sur le nom
 *   `www.marmiton.org`). Il ne refait donc pas sa propre résolution entre la vérification et la connexion : une réponse DNS changeante (rebinding) ne
 *   peut pas détourner l'appel. Au moindre doute (aucune adresse, une seule adresse non publique parmi plusieurs) on refuse.
 * - **Une seule redirection, vers la même recette.** `max_redirects: 0` ; la `Location` d'un `3xx` est suivie à la main, une fois, seulement si
 *   elle passe `MarmitonUrl::parse` ET désigne le même identifiant (un faux nom de recette est redirigé vers le bon, `recette_x_22082` vers
 *   `recette_the-tarte-au-citron_22082`) ; sinon, ou à la deuxième redirection, `unavailable`. L'adresse suivie est reconstruite, pas copiée.
 * - **Borné** : 8 s en tout (résolution, redirection et lecture comprises), corps lu en flux et abandonné à 2 Mio, `Content-Type`
 *   `text/html` exigé ; seul le bloc `ld+json` de la recette est lu (`MarmitonRecipeExtractor`), le HTML n'est jamais interprété.
 * - **Honnête et sobre** : l'agent est `Repas/1.0 (copie de recette demandée par un utilisateur)`, plus `Accept-Language: fr` ; aucun
 *   autre en-tête de notre part, aucun cookie.
 *
 * Statuts : `404` et `410` donnent `recipeNotFound` ; toute autre réponse qui n'est pas `200`, un délai dépassé, une erreur réseau ou TLS,
 * un corps trop gros ou d'un autre type donnent `unavailable` (on ne distingue pas un blocage d'une panne) ; une page sans recette
 * lisible donne `recipeUnreadable`. Le journal reçoit la raison technique d'un échec, jamais le contenu de la page.
 */
final readonly class MarmitonHttpPageReader implements MarmitonPageReader
{
    public const string USER_AGENT = 'Repas/1.0 (copie de recette demandée par un utilisateur)';
    public const float MAX_DURATION = 8.0;
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** Les statuts de redirection qu'on suit (une fois) ; tout autre `3xx` est une panne. */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    /** Il reste toujours au moins ce temps pour la deuxième requête, jamais un délai nul. */
    private const float MIN_REMAINING = 0.5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private HostAddressResolver $resolver,
        private MarmitonRecipeExtractor $extractor,
        private LoggerInterface $logger,
    ) {
    }

    public function read(MarmitonUrl $url): MarmitonRecipePage
    {
        $startedAt = hrtime(true);
        $address = $this->publicAddress();

        $first = $this->get($url, $address, $startedAt);
        if ($first['body'] !== null) {
            return $this->extractor->extract($first['body'], $url->canonical());
        }

        // Un `3xx` : une seule redirection, vers la même recette.
        $target = $this->redirectTarget($first['location'], $url);
        $second = $this->get($target, $address, $startedAt);
        if ($second['body'] === null) {
            throw $this->unavailable('deuxième redirection');
        }

        return $this->extractor->extract($second['body'], $target->canonical());
    }

    /**
     * Résout `www.marmiton.org` et rend l'adresse à laquelle se connecter, après avoir vérifié qu'elles sont toutes publiques.
     *
     * @throws MarmitonImportException
     */
    private function publicAddress(): string
    {
        $addresses = $this->resolver->resolve(MarmitonUrl::HOST);
        if ($addresses === []) {
            throw $this->unavailable('le nom ne se résout pas en IPv4');
        }

        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) {
                throw $this->unavailable('le nom se résout vers une adresse non publique');
            }
        }

        return $addresses[0];
    }

    /**
     * Une adresse IPv4 routable sur l'Internet public : ni privée, ni loopback, ni lien local, ni partagée (100.64/10), ni réservée, ni de
     * documentation (`FILTER_FLAG_GLOBAL_RANGE`), ni multicast (224/4, que ce filtre laisse passer).
     */
    private function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $long = ip2long($address);

        return $long !== false && ($long & 0xF0000000) !== 0xE0000000;
    }

    /**
     * Un `GET` vers `$url` (adresse reconstruite par le domaine), connecté à `$address`.
     *
     * @return array{body: string|null, location: string} `body` : la page (statut `200`, `text/html`, au plus 2 Mio), ou `null` pour une
     *                                                    redirection, dont `location` est l'en-tête `Location`
     *
     * @throws MarmitonImportException
     */
    private function get(MarmitonUrl $url, string $address, int $startedAt): array
    {
        $remaining = self::MAX_DURATION - (hrtime(true) - $startedAt) / 1e9;
        if ($remaining <= 0) {
            throw $this->unavailable('délai dépassé');
        }
        $remaining = max($remaining, self::MIN_REMAINING);

        try {
            $response = $this->httpClient->request('GET', $url->fetchUrl(), [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'fr'],
                'resolve' => [MarmitonUrl::HOST => $address],
                'max_redirects' => 0,
                'timeout' => $remaining,
                'max_duration' => $remaining,
                'verify_peer' => true,
                'verify_host' => true,
                'buffer' => false,
                // Le client abandonne le transfert dès que la taille annoncée ou reçue dépasse 2 Mio ; la boucle de lecture compte aussi les octets décodés.
                'on_progress' => static function (int $downloaded, int $total): void {
                    if ($downloaded > self::MAX_BYTES || $total > self::MAX_BYTES) {
                        throw new TransportException('réponse trop grosse');
                    }
                },
            ]);

            $status = $response->getStatusCode();

            if ($status === 404 || $status === 410) {
                $response->cancel();

                throw MarmitonImportException::recipeNotFound();
            }

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $location = $response->getHeaders(false)['location'][0] ?? '';
                $response->cancel();

                return ['body' => null, 'location' => $location];
            }

            if ($status !== 200) {
                $response->cancel();

                throw $this->unavailable(sprintf('réponse HTTP %d', $status));
            }

            $headers = $response->getHeaders(false);
            if (!$this->isHtml($headers['content-type'][0] ?? '')) {
                $response->cancel();

                throw $this->unavailable('la réponse n\'est pas du HTML');
            }

            return ['body' => $this->readBody($response), 'location' => ''];
        } catch (HttpClientException $exception) {
            throw $this->unavailable($exception->getMessage());
        }
    }

    /**
     * Lit le corps en flux et l'abandonne dès qu'il dépasse 2 Mio.
     *
     * @throws HttpClientException
     * @throws MarmitonImportException
     */
    private function readBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();

                throw $this->unavailable('délai dépassé');
            }

            $body .= $chunk->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                $response->cancel();

                throw $this->unavailable('réponse trop grosse');
            }

            if ($chunk->isLast()) {
                break;
            }
        }

        return $body;
    }

    private function isHtml(string $contentType): bool
    {
        return strtolower(trim(explode(';', $contentType)[0])) === 'text/html';
    }

    /**
     * La recette vers laquelle Marmiton redirige : elle doit passer `MarmitonUrl::parse` (donc rester sur Marmiton, dans la même forme
     * d'adresse) et porter le même identifiant. Un chemin relatif (`/recettes/…`) est lu sur `www.marmiton.org`.
     *
     * @throws MarmitonImportException
     */
    private function redirectTarget(string $location, MarmitonUrl $from): MarmitonUrl
    {
        $location = trim($location);
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $location = 'https://' . MarmitonUrl::HOST . $location;
        }

        try {
            $target = MarmitonUrl::parse($location);
        } catch (MarmitonImportException) {
            throw $this->unavailable('redirection hors de la forme d\'une recette Marmiton');
        }

        if ($target->id !== $from->id) {
            throw $this->unavailable('redirection vers une autre recette');
        }

        return $target;
    }

    private function unavailable(string $reason): MarmitonImportException
    {
        $this->logger->warning('Page de recette Marmiton indisponible.', ['reason' => $reason]);

        return MarmitonImportException::unavailable();
    }
}
