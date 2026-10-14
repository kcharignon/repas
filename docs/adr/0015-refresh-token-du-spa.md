# 0015 — Authentification du SPA : refresh token en cookie `HttpOnly` à rotation

- **Statut** : Accepted
- **Date** : 2026-10-14
- **Liens** : [0008](0008-auth-session-et-jwt.md) §5 et §13, [0007](0007-api-rest-v1.md), [0003](0003-cqrs-lecture-ecriture.md), [0006](0006-buses-messenger.md), `docs/front-vuejs-plan.md` (section « Authentification »)

## Contexte

Le JWT d'accès dure 1 h (0008 §5) et n'a pas de refresh token : au bout d'une heure, l'utilisateur doit ressaisir son mot de passe. Un SPA ne doit pas garder un jeton longue durée lisible par script (`localStorage`) : une faille XSS le volerait. Le front Vue.js (projet séparé, même domaine que l'API) garde donc le JWT **en mémoire** et obtient un nouveau JWT auprès du back au rechargement de la page et à l'expiration.

Faits : les compteurs de connexion (0008 §12) et la révocation à la modification du mot de passe (0008 §13, `User::getCredentialsVersion()`) existent ; le bus de commandes est transactionnel (`doctrine_transaction`, 0006) ; une commande peut renvoyer le résultat de son handler (`CommandBus::dispatch()` extrait le `HandledStamp`).

## Décision

1. **Le refresh token est un secret aléatoire de 256 bits** (`bin2hex(random_bytes(32))`), transporté **uniquement** dans un cookie `repas_refresh` : `HttpOnly`, `Secure`, `SameSite=Strict`, `Path=/api/auth`, sans `Domain` (hôte seul), `Max-Age` de 30 jours. Il n'apparaît jamais dans un corps de réponse, une commande, un journal ni une URL.
2. **Il est stocké haché** (SHA-256 : le secret est déjà aléatoire et long, un hachage lent n'apporterait rien) dans la table `refresh_token`. La commande et le modèle ne manipulent que le hachage (`RefreshToken::hash()`).
3. **Une connexion ouvre une famille** : `POST /api/login_check` pose le cookie en plus du JWT (`IssueRefreshTokenOnLogin`, évènement `lexik_jwt_authentication.on_authentication_success`). Une famille est la chaîne des jetons issus d'une même connexion ; sa durée absolue est de 90 jours, la durée glissante d'un jeton de 30 jours. Au plus 10 familles par utilisateur : la plus ancienne disparaît à la onzième connexion.
4. **`POST /api/auth/refresh`** (public : le JWT est justement expiré) échange le cookie contre un nouveau JWT `{token}` et un **nouveau** cookie : **rotation à chaque usage**. L'ancien jeton est marqué utilisé, il n'est pas supprimé.
5. **Détection de rejeu** : présenter un jeton déjà utilisé **supprime toute sa famille** (le voleur et la victime sont déconnectés) et répond `401`. Exception : dans les 10 secondes qui suivent l'usage, la requête est refusée en `401` **sans toucher à la famille** — deux onglets qui rafraîchissent en même temps envoient le même cookie, le second perd la course alors que le navigateur détient déjà le cookie suivant. Le client réessaie une fois sur un `401` de refresh avant de renvoyer l'utilisateur à la connexion.
6. **Révocation** : un jeton est refusé (et sa famille supprimée) s'il est expiré, si le compte est désactivé ou si la **version des identifiants** de l'utilisateur a changé depuis son émission (`User::changePassword`, 0008 §13) : changer de mot de passe déconnecte tous les appareils, JWT comme refresh tokens.
7. **`POST /api/auth/logout`** supprime la famille du cookie présenté et efface le cookie ; `204` même sans cookie ou avec un cookie inconnu (idempotent). Les JWT déjà émis restent valables jusqu'à leur expiration (1 h) : le front les oublie, le back ne tient pas de liste noire.
8. **Un échec de refresh répond toujours** `401 {"error":{"message":"Invalid refresh token."}}` et **efface le cookie**, sans dire pourquoi (absent, inconnu, expiré, rejoué, compte désactivé, mot de passe changé). La raison est journalisée côté serveur (niveau `warning` pour un rejeu).
9. **Plafond de débit** : `POST /api/auth/refresh` est limité à 60 requêtes par 10 minutes et par adresse IP (limiteur `refresh_token`, même mécanique que l'inscription, 0008 §12), `429` + `Retry-After` au-delà.
10. **Le refresh ne passe pas par le firewall `api`** : les routes `^/api/auth` sont hors sécurité (`security: false`), sinon un `Authorization: Bearer` expiré envoyé par un client trop zélé ferait échouer l'échange qu'il est censé réparer. L'identité vient du seul cookie.
11. **Architecture** : modèle `User\Domain\Model\RefreshToken` et port `RefreshTokenRepository` (Domain) ; `IssueRefreshToken`, `RotateRefreshToken`, `RevokeRefreshToken` et `PurgeExpiredRefreshTokens` (Application, un dossier chacun) ; entité et repository Doctrine, cookie, controllers `RefreshTokenApiController` / `LogoutApiController` (Infrastructure). Les controllers n'appellent que `CommandBusInterface`, le `UserProvider` de sécurité (pour construire le JWT) et le gestionnaire de JWT.
12. **Exception à « une commande ne renvoie rien »** : `RotateRefreshToken` **renvoie l'identifiant de connexion** (email) du propriétaire, ou `null` s'il refuse. Deux raisons : vérifier et consommer le jeton doit être atomique, et un refus (rejeu, version périmée) doit **persister** la suppression de la famille, donc ne peut pas lever une exception (le bus transactionnel annulerait la transaction).
13. **Nettoyage** : `bin/console repas:purge-refresh-tokens` supprime les jetons dont la famille ou le jeton a expiré. À planifier une fois par jour (cron de l'hébergement).

## Conséquences

- Positives : le SPA ne garde aucun secret durable lisible par script ; un jeton volé est inutilisable dès que la victime ou le voleur se sert du sien ; changer de mot de passe ou fermer un compte coupe toutes les sessions ; aucune dépendance ajoutée (le besoin tient en ~300 lignes et le bundle `gesdinet/jwt-refresh-token-bundle` n'apporte ni le rejeu par famille ni la version des identifiants).
- Négatives / coûts : une table de plus et une écriture à chaque rafraîchissement ; le front et l'API doivent partager le même site (`SameSite=Strict` : un front sur un autre domaine enregistrable ne recevrait pas le cookie) ; la course entre onglets exige le réessai côté client ; un JWT volé reste valable 1 h après la déconnexion.
- Garde-fou : tests des handlers (en mémoire), du repository (base), du flux complet (connexion → cookie → refresh → rejeu → déconnexion → changement de mot de passe) et du plafond de débit ; `make api-doc-coverage` impose la documentation OpenAPI des deux routes.

## Alternatives écartées

- Refresh token dans le corps de la réponse et `localStorage` : lisible par n'importe quel script de la page (XSS), c'est ce que le plan du front interdit.
- `gesdinet/jwt-refresh-token-bundle` : ni famille de rejeu, ni lien avec la version des identifiants ; une couche d'adaptation aurait coûté plus que l'implémentation.
- JWT d'accès de longue durée (jours) : une compromission dure aussi longtemps, et rien ne le révoque (0008 §13 ne couvre que le mot de passe).
- Session PHP (cookie de session) pour le SPA : ramène CSRF et état serveur que l'API stateless voulait éviter (0008).
- Jeton non rotatif : un cookie volé servirait 30 jours sans que personne ne le sache.
