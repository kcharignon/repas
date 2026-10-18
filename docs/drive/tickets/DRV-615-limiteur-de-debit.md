# DRV-615 — Limiteur de débit par utilisateur sur les routes du drive

- **Dépôt** : back
- **Jalon** : M2c Durcissement (revue de sécurité du back, constat C2)
- **Taille** : S
- **Dépend de** : DRV-204, DRV-613 (faits)
- **Statut** : fait (PR #176, 2026-10-18)
- **Lire d'abord** : [`../revue-securite-back.md`](../revue-securite-back.md) (C2), [`DRV-613`](DRV-613-durcissement-fiches-produits.md) (plafonds par utilisateur), `architecture.md` § 10, ADR 0008 § 12 (limiteurs existants), skills `repas-dev` / `repas-drive-back`, `docs/drive/consignes-agents.md`.

## Contexte

La revue de sécurité du back (constat C2) a relevé que l'API authentifiée n'a aucun plafond de débit : les seuls limiteurs couvrent l'inscription, la connexion, le renouvellement du jeton et la confirmation d'adresse (tous par adresse IP). Un compte inscrit peut envoyer des `PUT /drive/choices` en boucle (jusqu'à 200 intentions chacun) dans des tables communes, ou relire sans fin un plan ou une estimation (4 requêtes SQL chacun). DRV-613 a posé des plafonds de **quantité** (1 000 préférences, 5 000 écritures de produits par jour) mais pas de plafond de **débit**.

## Portée

1. Un limiteur de débit **par utilisateur** (identifiant du jeton, jamais l'adresse IP seule : la famille partage une adresse) en fenêtre glissante, sur les écritures du drive (`PUT /drive/settings`, `PUT /drive/choices`, `PUT` et `DELETE /drive/choices/{slug}` : un compteur commun, **60 par minute**) et sur les lectures coûteuses (`GET /drive/plans/{id}` et `GET /drive/estimates/{id}` : un compteur commun, **120 par minute**).
2. Dépassement : `429`, corps `{error:{message:"DRIVE_RATE_LIMITED"}}`, en-tête `Retry-After`. Rien n'est exécuté.
3. Aucune requête SQL ajoutée : l'état est dans le cache, pas dans la base (budgets `WriteBudget` inchangés). Testable sans dormir.
4. Interrupteur coupé : `404 DRIVE_DISABLED` avant le limiteur, aucun jeton consommé.
5. Tests, `docs/api/openapi.json` (réponse `429` sur les six routes), documentation (architecture § 7 et § 10, production, runbook, skill, ADR 0008, `CLAUDE.md`), `make test` et `make quality` verts.

## Hors portée

Plafonner `GET /drive/settings` et `GET /drive/choices` (une ligne et deux requêtes bornées par les données de l'utilisateur), `send` et `reopen` (contexte `Repas`), les routes hors du drive ; un stockage partagé entre plusieurs machines (Redis) ; l'affichage du message côté front.

## Critères d'acceptation

- La soixante et unième écriture du drive d'un utilisateur dans la minute répond `429 DRIVE_RATE_LIMITED` avec `Retry-After`, et n'enregistre rien ; un autre utilisateur de la même adresse n'est pas gêné ; après la fenêtre, tout repasse.
- Plan et estimation partagent un compteur de lectures, indépendant de celui des écritures.
- Interrupteur coupé : `404 DRIVE_DISABLED`, jamais `429`.
- Le limiteur n'ajoute aucune requête SQL.

## Definition of Done

Celle du skill `repas-drive-back` ; PR concise ; fusion seulement si tous les contrôles de la CI sont verts.

## Réalisation (back, 2026-10-18, PR repas#176)

- **Mécanisme** : `#[RateLimitedPerUser(DriveRateLimit::WRITE|READ)]` (`src/Drive/Infrastructure/Http/`) sur les six controllers et `DriveRateLimitListener`, écouteur `kernel.controller` de priorité **-10** : après la sécurité (un appel anonyme reste `401`, rien n'est compté) et après `DriveEnabledListener` (priorité 0 : interrupteur coupé, `404 DRIVE_DISABLED` et aucun jeton consommé), avant la résolution des arguments (une requête au corps invalide compte aussi). Clé = `user-<id du jeton>` ; sans utilisateur (route mal protégée), repli sur `ip-<adresse>` plutôt que sur aucun plafond. Composant Symfony RateLimiter déjà installé (`symfony/rate-limiter` 8.1) ; l'attribut natif `#[RateLimit]` n'a pas servi : sa clé par utilisateur demande une expression (`symfony/expression-language` absent) ou une closure (impossible dans un attribut en PHP 8.4) et son message ne se règle pas.
- **Valeurs** (`config/packages/rate_limiter.yaml`) : `drive_write` **60 par minute** (une écriture par seconde en continu : un passage de l'agent envoie un seul lot `PUT /drive/choices`, un clic sur « Mes produits » envoie une écriture, les réglages une fois ; 30 à 60 fois l'usage normal) ; `drive_read` **120 par minute** (le plan et l'estimation sont relus à l'ouverture d'un écran ; 4 requêtes SQL chacun, donc au plus 480 par minute et par utilisateur). Fenêtre glissante : les requêtes refusées ne sont pas comptées, on n'allonge pas son propre blocage en insistant. Ces plafonds de débit complètent ceux de quantité de DRV-613.
- **Stockage = cache, pas base** : pool `cache.rate_limiter` du framework (fichiers sous `var/cache`, partagés par tous les workers FrankenPHP d'une machine unique), pool en mémoire en test. Aucune table, aucune migration, aucune requête SQL (test : mêmes requêtes avec et sans limiteur ; budgets `WriteBudget` inchangés). Un vidage du cache ou un nouveau conteneur remet les compteurs à zéro, sans conséquence. Pas de verrou (`symfony/lock` absent) : deux requêtes simultanées d'un même utilisateur peuvent sous-compter de quelques unités, acceptable pour une protection contre les flots. Avec plusieurs machines, chacune compterait à part (plafond multiplié) : il faudrait alors un pool Redis (`cache_pool`).
- **Réponse** : `TooManyRequestsHttpException` (en-tête `Retry-After`) enveloppant `DriveException::rateLimited()` (`DRIVE_RATE_LIMITED`, code 429) ; `ApiExceptionListener` affiche le code de l'erreur métier enveloppée quand son code est celui du statut HTTP (règle générale, testée). Écart assumé : `Retry-After` est celui du composant, **optimiste après une rafale** (souvent 1 s alors que le compteur se libère en environ une minute) ; la documentation OpenAPI et le front doivent le traiter comme un minimum.
- **Contrat** : `docs/api/openapi.json` gagne deux réponses réutilisables (`#/components/responses/DriveWriteRateLimited` et `DriveReadRateLimited`, avec l'en-tête `Retry-After`) référencées en `429` par les six routes, uniquement des ajouts.
- **Tests** : `DriveRateLimitListenerTest` (vrai limiteur, pool injecté, `RateLimiterTimeTravel` fait passer le temps sans dormir, horloge injectée pour `Retry-After` : plafond, utilisateurs séparés, compteurs lecture/écriture séparés, repli IP, rien pour un controller sans l'attribut, refus non comptés, fenêtre écoulée) ; `DriveRateLimitApiFlowTest` (de bout en bout avec les vraies routes et les vrais plafonds : 61e écriture refusée sans rien enregistrer, les six routes refusées une fois leur compteur plein même avec un corps invalide, autre utilisateur libre, tout repasse après la fenêtre, interrupteur coupé = `404` sans jeton consommé, anonyme = `401`, aucune requête SQL en plus) ; `DriveRoutesAreRateLimitedTest` (toute route du drive est plafonnée avec le bon compteur ou listée en exception, et documente son `429`) ; `ApiExceptionListenerTest` (deux cas). Mutations vérifiées : priorité de l'écouteur inversée et attribut retiré d'un controller font échouer les tests.
- **Exceptions voulues** (listées dans `DriveRoutesAreRateLimitedTest`) : `GET /drive/settings` et `GET /drive/choices`.
- **Pour le front** : traiter `429` avec le message `DRIVE_RATE_LIMITED` comme une erreur affichable et passagère (texte dans `src/i18n/fr.ts` : « Trop de demandes en peu de temps, réessaie dans une minute »), distincte de `DRIVE_LIMIT_REACHED` (plafond de quantité, `422`) ; ne pas réessayer en boucle ; l'agent, après un `429` sur `PUT /drive/choices`, garde son lot et le renvoie plus tard au lieu de le perdre (un passage normal n'en envoie qu'un). En développement entre deux origines, l'en-tête `Retry-After` n'est pas exposé par le CORS (`expose_headers`) ; en production le front et l'API ont la même origine.
