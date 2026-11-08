<?php

namespace Repas\Repas\Infrastructure\Image;


use Psr\Log\LoggerInterface;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageFetcher;
use Repas\Repas\Domain\Model\ImageUrl;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Repas\Infrastructure\Network\HostAddressResolver;
use Repas\Repas\Infrastructure\Network\PublicAddress;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Télécharge l'image dont un administrateur a collé le lien direct (ADR 0039). L'hôte est quelconque (c'est le choix de l'administrateur), donc tout
 * ce qui limite l'appel est ICI, sur le modèle du lecteur de page Marmiton (ADR 0037) :
 *
 * - **Jamais vers une adresse non publique** (SSRF). Le nom est résolu ICI (`HostAddressResolver::resolveAll`, IPv4 et IPv6), et TOUTES ses adresses
 *   doivent être publiques (`PublicAddress` : ni privée, ni loopback, ni lien local ni métadonnées des nuages `169.254.169.254`, ni partagée, ni
 *   réservée, ni multicast, et leurs équivalents IPv6) ; sinon `urlRefused`, sans aucune connexion. Le client HTTP est ensuite OBLIGÉ de se connecter à
 *   l'adresse IPv4 vérifiée (option `resolve`, TLS vérifié sur le nom) : il ne refait pas sa propre résolution, une réponse DNS changeante (rebinding)
 *   ne peut pas détourner l'appel. Un nom sans IPv4 est une panne (`downloadFailed`), pas un détour par une IPv6 que rien ne relie.
 * - **Les redirections sont suivies À LA MAIN**, trois au plus (`max_redirects: 0`) ; chaque `Location` repasse par `ImageUrl` (https, nom public,
 *   port 443) PUIS par la résolution et le contrôle des adresses ci-dessus : un saut vers une adresse locale est refusé comme le premier appel.
 * - **Borné** : 10 s en tout (résolutions, redirections et lecture comprises), corps lu en flux et abandonné à 3 Mio (`tooLarge`, la taille annoncée
 *   par `Content-Length` est refusée avant de lire quoi que ce soit), `Content-Type` `image/*` exigé (`notAnImage` sinon : une page web collée par
 *   erreur). Le contenu n'est ni décodé ni interprété ici : l'appelant le fait passer par `ImageNormalizer`, comme un fichier envoyé.
 * - **Honnête et sobre** : l'agent est `Repas/1.0 (image demandée par un administrateur)` ; aucun cookie, aucun en-tête de l'utilisateur transmis.
 *
 * Journal : la RAISON technique d'un refus ou d'un échec (`reason`, une courte phrase de ce fichier), JAMAIS le lien, le nom d'hôte, l'adresse IP, le
 * message de l'exception du client (il cite le lien) ni le contenu reçu. Le message renvoyé au client est un code (`ImageException`).
 */
final readonly class HttpImageFetcher implements ImageFetcher
{
    public const string USER_AGENT = 'Repas/1.0 (image demandée par un administrateur)';
    public const float MAX_DURATION = 10.0;
    public const int MAX_REDIRECTS = 3;

    /** Les statuts de redirection qu'on suit ; tout autre `3xx` est une panne. */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    /** Il reste toujours au moins ce temps pour une requête de plus, jamais un délai nul. */
    private const float MIN_REMAINING = 0.5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private HostAddressResolver $resolver,
        private LoggerInterface $logger,
    ) {
    }

    public function fetch(ImageUrl $url): string
    {
        $startedAt = hrtime(true);
        $current = $url;

        for ($redirects = 0; ; ++$redirects) {
            $address = $this->connectionAddress($current);
            $response = $this->get($current, $address, $startedAt);
            if ($response['body'] !== null) {
                return $response['body'];
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw $this->refused('trop de redirections');
            }
            $current = $this->redirectTarget($current, $response['location']);
        }
    }

    /**
     * Résout le nom, vérifie que toutes ses adresses sont publiques et rend l'adresse IPv4 à laquelle se connecter.
     *
     * @throws ImageException
     */
    private function connectionAddress(ImageUrl $url): string
    {
        $addresses = $this->resolver->resolveAll($url->host);
        if ($addresses === []) {
            throw $this->failed('le nom ne se résout pas');
        }

        foreach ($addresses as $address) {
            if (!PublicAddress::isPublic($address)) {
                throw $this->refused('le nom se résout vers une adresse non publique');
            }
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        throw $this->failed('le nom n\'a pas d\'adresse IPv4');
    }

    /**
     * Un `GET` vers `$url`, connecté à `$address`.
     *
     * @return array{body: string|null, location: string} `body` : l'image (statut `200`, `image/*`, au plus 3 Mio), ou `null` pour une redirection,
     *                                                    dont `location` est l'en-tête `Location`
     *
     * @throws ImageException
     */
    private function get(ImageUrl $url, string $address, int $startedAt): array
    {
        $remaining = self::MAX_DURATION - (hrtime(true) - $startedAt) / 1e9;
        if ($remaining <= 0) {
            throw $this->failed('délai dépassé');
        }
        $remaining = max($remaining, self::MIN_REMAINING);

        $tooLarge = false;
        try {
            $response = $this->httpClient->request('GET', $url->toString(), [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'image/png,image/webp,image/svg+xml,image/*;q=0.5'],
                'resolve' => [$url->host => $address],
                'max_redirects' => 0,
                'timeout' => $remaining,
                'max_duration' => $remaining,
                'verify_peer' => true,
                'verify_host' => true,
                'buffer' => false,
                // Le client abandonne le transfert dès que la taille annoncée ou reçue dépasse 3 Mio ; la boucle de lecture compte aussi les octets décodés.
                'on_progress' => static function (int $downloaded, int $total) use (&$tooLarge): void {
                    if ($downloaded > ImageNormalizer::MAX_BYTES || $total > ImageNormalizer::MAX_BYTES) {
                        $tooLarge = true;

                        throw new TransportException('réponse trop grosse');
                    }
                },
            ]);

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $response->cancel();

                return ['body' => null, 'location' => $headers['location'][0] ?? ''];
            }

            if ($status !== 200) {
                $response->cancel();

                throw $this->failed(sprintf('réponse HTTP %d', $status));
            }

            if (!$this->isImage($headers['content-type'][0] ?? '')) {
                $response->cancel();

                throw $this->notAnImage('la réponse n\'est pas d\'un type image');
            }

            return ['body' => $this->readBody($response), 'location' => ''];
        } catch (HttpClientException $exception) {
            if ($tooLarge) {
                throw $this->tooLarge();
            }

            // La classe seule : le message d'une exception du client cite le lien, parfois l'adresse
            throw $this->failed('erreur du client HTTP (' . $exception::class . ')');
        }
    }

    /**
     * Lit le corps en flux et l'abandonne dès qu'il dépasse 3 Mio.
     *
     * @throws HttpClientException
     * @throws ImageException
     */
    private function readBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();

                throw $this->failed('délai dépassé');
            }

            $body .= $chunk->getContent();
            if (strlen($body) > ImageNormalizer::MAX_BYTES) {
                $response->cancel();

                throw $this->tooLarge();
            }

            if ($chunk->isLast()) {
                break;
            }
        }

        return $body;
    }

    private function isImage(string $contentType): bool
    {
        return str_starts_with(strtolower(trim(explode(';', $contentType)[0])), 'image/');
    }

    /**
     * Où mène la redirection : un lien que `ImageUrl` accepte (https, nom public, port 443), jugé comme le premier.
     *
     * @throws ImageException
     */
    private function redirectTarget(ImageUrl $from, string $location): ImageUrl
    {
        try {
            return $from->redirectedTo($location);
        } catch (ImageException) {
            throw $this->refused('redirection vers une adresse refusée');
        }
    }

    private function refused(string $reason): ImageException
    {
        $this->logger->warning('Téléchargement d\'image refusé.', ['reason' => $reason]);

        return ImageException::urlRefused();
    }

    private function failed(string $reason): ImageException
    {
        $this->logger->warning('Téléchargement d\'image impossible.', ['reason' => $reason]);

        return ImageException::downloadFailed();
    }

    private function notAnImage(string $reason): ImageException
    {
        $this->logger->warning('Téléchargement d\'image refusé.', ['reason' => $reason]);

        return ImageException::notAnImage();
    }

    private function tooLarge(): ImageException
    {
        $this->logger->warning('Téléchargement d\'image refusé.', ['reason' => 'réponse trop grosse']);

        return ImageException::tooLarge();
    }
}
