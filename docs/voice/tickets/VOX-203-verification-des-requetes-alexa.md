# VOX-203 — Vérificateur de requêtes Alexa

- **Dépôt** : back
- **Jalon** : M2 Back `Voice`
- **Taille** : L
- **Dépend de** : VOX-201
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 7 ; page Amazon « Host a custom skill as a web service » (procédure de vérification, relire) ; `src/User/Infrastructure/Security` (modèle d'adaptateur avec horloge injectée). Effort conseillé : **xhigh** (sécurité).

## Contexte

Le webhook est public : sans cette vérification, n'importe qui écrirait dans une liste en se faisant passer pour Alexa.

## Portée

1. Port `Voice\Domain\Interface\AlexaRequestVerifier::verify(string $body, string $certChainUrl, string $signature256): void` (lève `VoiceException::invalidRequest()`), plus contrôle du corps : `request.timestamp` à ±150 s de l'horloge injectée (`ClockInterface`), `session.application.applicationId` (ou `context.System.application.applicationId`) = `ALEXA_SKILL_ID`.
2. Adaptateur `Infrastructure/Security/OpenSslAlexaRequestVerifier` : URL de chaîne normalisée (`https`, hôte `s3.amazonaws.com`, chemin commençant par `/echo.api/`, port 443 ou absent) ; chaîne PEM obtenue par un port `CertificateChainFetcher` (adaptateur HTTP Symfony, **mise en cache 24 h** par URL, expiration respectée) ; certificat non expiré, SAN `echo-api.amazon.com`, chaîne jusqu'à une racine de confiance (magasin du système) ; signature `Signature-256` vérifiée par `openssl_verify` SHA-256 sur le **corps brut exact**.
3. Le contrôleur lit le corps brut une seule fois (avant tout décodage JSON).

## Critères d'acceptation

- Avec une autorité de test et un certificat feuille de test (SAN `echo-api.amazon.com`) générés **dans le test**, une requête signée est acceptée ; corps modifié d'un octet, signature fausse, certificat expiré, SAN absent, URL de chaîne hors `s3.amazonaws.com/echo.api/`, horodatage à 151 s, `applicationId` autre : chaque cas est refusé (`400` au niveau HTTP).
- Le téléchargement de la chaîne n'est tenté qu'une fois par URL dans la fenêtre de cache (test avec faux client HTTP).

## Tests exigés

Adaptateur en unitaire (faux `CertificateChainFetcher`, `MockClock`, magasin de racines de test injectable) ; **aucun test n'appelle Amazon**.

## Pièges connus

- Le magasin de racines doit être injectable : le test pose sa propre autorité.
- Ne jamais journaliser le corps de la requête (il contient la voix transcrite).
- Délai de réponse total d'Alexa serré (~8 s) : la mise en cache du certificat est obligatoire.
