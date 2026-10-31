# Architecture Decision Records

Décisions d'architecture du projet Repas. **À relire avant de développer ou de migrer une fonctionnalité** (le skill `repas-dev` le demande). Format : voir [0000-template.md](0000-template.md). Une décision remplacée n'est jamais supprimée : on passe son statut à `Superseded by …`.

| N° | Titre | Statut |
|---|---|---|
| [0001](0001-architecture-hexagonale.md) | Architecture hexagonale, règle de dépendance, bounded contexts | Accepted |
| [0002](0002-modele-domaine-riche.md) | Modèle de domaine riche : `create()` vs `load()`, Clock/UuidGenerator injectés | Accepted |
| [0003](0003-cqrs-lecture-ecriture.md) | CQRS : écriture par CommandBus, lecture par QueryBus | Accepted |
| [0004](0004-repository-vs-loader.md) | Repository (agrégats, écriture) vs Loader (projections, lecture) | Accepted |
| [0005](0005-conventions-dto-lecture.md) | Conventions des DTO de lecture | Accepted |
| [0006](0006-buses-messenger.md) | Deux buses Messenger : `command.bus` et `query.bus` | Accepted |
| [0007](0007-api-rest-v1.md) | API REST `/api/v1` consommée par un front | Accepted |
| [0008](0008-auth-session-et-jwt.md) | Authentification : session pour le web, JWT stateless pour `/api` | Accepted |
| [0009](0009-strategie-de-migration.md) | Stratégie de migration « strangler » par slice de lecture | Accepted |
| [0010](0010-strategie-de-tests.md) | Stratégie de tests de la lecture et de l'API | Accepted |
| [0011](0011-garde-fous-deptrac-phpstan.md) | Garde-fous : Deptrac et PHPStan | Accepted |
| [0012](0012-pieges-compliance.md) | Pièges observés dans Compliance à ne pas reproduire | Accepted |
| [0013](0013-agregats-references-par-identifiant.md) | Un agrégat référence les autres agrégats par identifiant | Accepted |
| [0014](0014-montee-de-versions-et-dependabot.md) | Montée de versions, Dependabot et avis de sécurité | Accepted |
| [0015](0015-refresh-token-du-spa.md) | Authentification du SPA : refresh token en cookie `HttpOnly` à rotation | Accepted |
| [0016](0016-controle-de-version-etag-if-match.md) | Contrôle de modification concurrente : version, `ETag` et `If-Match` | Accepted |
| [0017](0017-deploiement-et-releases.md) | Déploiement : release GitHub, images GHCR, bordure Caddy, retour arrière automatique | Accepted |
| [0018](0018-extinction-de-twig.md) | Extinction de Twig : le back ne sert plus que l'API | Accepted |
| [0019](0019-recherche-d-ingredients-par-pertinence.md) | Recherche d'ingrédients par pertinence (Jaro-Winkler) (amendé le 2026-10-28 : recettes des autres) | Accepted |
| [0020](0020-confirmation-d-adresse-email.md) | Confirmation d'adresse email à l'inscription : statut `PENDING`, lien à usage unique, Symfony Mailer. **Amendement du 2026-10-28 : inscription par la seule adresse**, mot de passe choisi après le clic sur le lien de bienvenue (réponse identique pour toute adresse) | Accepted |
| [0021](0021-gestion-des-comptes-par-l-administrateur.md) | Gestion des comptes par l'administrateur : statut libre, compte protégé, suppression (purge des données, copies conservées) | Accepted |
| [0022](0022-remplissage-du-panier-drive.md) | Remplir le panier d'un drive : agent de page, aucun identifiant d'enseigne | Accepted |
| [0023](0023-liste-envoyee-au-drive.md) | Liste envoyée au drive : statut `SENT` et verrou | Accepted |
| [0024](0024-connexion-avec-google.md) | Connexion avec Google : jeton d'identité, GIS sur le web, Credential Manager dans l'application, compte lié par `sub` | Accepted |
| [0025](0025-publication-sur-google-play.md) | Publication de l'application Android sur Google Play : paliers (test interne, test fermé, production), clé de signature importée, AAB, conformité (suppression de compte, confidentialité, contenus d'utilisateurs) | Proposed |
| [0026](0026-mot-de-passe-oublie.md) | Mot de passe oublié : lien de 30 minutes à usage unique, jeton à part, aucune énumération de comptes | Accepted (livré, PR #206, en production depuis le back v0.6.2) |
| [0027](0027-plusieurs-enseignes-de-drive.md) | Plusieurs enseignes de drive : registre par couche, enseigne choisie d'après les réglages, interrupteur par enseigne | Proposed |
| [0028](0028-ingredients-propres-a-chaque-utilisateur.md) | Ingrédients propres à chaque utilisateur : adoption des communs (copie + alias), masquage, rapprochement à la copie d'une recette (Jaro-Winkler, même rayon), fusion de deux ingrédients, migration pour les seuls utilisateurs concernés. **Amendement du 2026-10-20 : plus d'ingrédient commun** (les anciens communs deviennent ceux de l'administrateur, marqueur « de base », amorçage de tout compte à l'activation, plan : `docs/ingredients/sans-commun.md`) | Accepted |
| [0029](0029-commande-vocale-alexa.md) | Commande vocale Alexa : skill personnalisé hébergé par le back, contexte `Voice`, liaison de compte OAuth (code + PKCE), ingrédient le plus probable d'après les habitudes (la liste d'Amazon n'est plus accessible aux tiers) | Accepted |
| [0030](0030-premiere-lettre-des-noms-en-majuscule.md) | Première lettre des noms de recettes et d'ingrédients en majuscule : `StringTool::capitalizeFirst` appliquée en silence dans les handlers, casse de la suite conservée, migration des données en PHP | Accepted |
| [0031](0031-emails-de-marque.md) | E-mails de marque : mise en page partagée `BrandedEmailLayout` (HTML d'e-mail sûr, clair et sombre, texte brut en alternative, lien en clair sous le bouton, aucune ressource externe sauf l'icône du site), voix du produit vérifiée par les tests | Accepted |
| [0032](0032-valeurs-propres-a-chaque-compte-pour-les-produits-du-drive.md) | Chaque compte garde ses propres valeurs observées d'un produit du drive (libellé, prix et sa date, sur la ligne `drive_choice_product` de son choix) ; la fiche partagée `drive_product` ne sert plus que de repli ; recopie unique par la migration, lectures en `COALESCE` | Accepted |
| [0033](0033-recette-secrete.md) | Recette secrète : drapeau `recipe.secret` posé par l'auteur seul ; une recette secrète n'est ni dans « Recettes des autres » (liste, total, recherche), ni lisible (`404`, jamais `403`), ni copiable par un autre utilisateur, administrateur compris (aucun passe-droit en lecture) ; les copies déjà faites restent à leurs auteurs | Accepted |
| [0034](0034-supprimer-un-type-de-recette-et-recettes-favorites.md) | Supprimer un type de recette (`DELETE /recipe-types/{slug}?transferTo=`, administrateur : toutes les recettes passent dans le type choisi, version +1, une transaction) ; recette favorite : drapeau `recipe.favorite` de l'auteur (`PUT /recipes/{id}/favorite`, `GET /recipes/favorites`), le type « Favoris » est une présentation du front, pas un type en base | Accepted |
| [0035](0035-contextes-etanches-open-host-service.md) | Contextes étanches : le producteur publie un Open Host Service (`CommandOHS` rend un Model, `QueryOHS` rend un DTO, `Application/OpenHostService/`), le consommateur le lit par un port (`CommandPort`/`QueryPort`, `Application/Port/`) et une couche anticorruption (`Infrastructure/Acl/`, seule autorisée à importer l'autre contexte) ; plus de port métier dans `Shared`, plus de SQL sur la table d'un autre contexte ; un OHS se teste par transitivité dans les handlers appelants | Accepted |
| [0036](0036-types-de-liste-de-courses.md) | Types de liste de courses : types propres à chaque compte, rayons et produits à part rattachés à un type, une liste en cours par type (ouverte à la demande), recette éclatée en un repas par liste (groupe), liste principale inchangée ; supprimer un type met sa liste en pause sans rien fusionner | Accepted |

## Sources

- Projet de référence : `/home/kantin/Projet/Compliance` (`CLAUDE.md`, `quality-rules/`, `src/Compliance/Application/{Query,Loader}`).
- Présentation : `/home/kantin/Projet/presentation-archi/presentation.md` et `CQRS-schema.png`.
- Roadmap de migration : [`../migration-roadmap.md`](../migration-roadmap.md).
- Skill de développement : `.claude/skills/repas-dev/SKILL.md`.

## Règles de maintenance

- Une décision d'architecture nouvelle = un nouvel ADR (numéro suivant) dans le même commit que le code qui l'applique.
- Changer une convention = mettre à jour l'ADR concerné, `CLAUDE.md`, le skill `repas-dev` et la roadmap ensemble.
