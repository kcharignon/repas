# STORE-019 — Publication en production (France) et signature

- **Dépôt** : aucun (étape humaine, guidée par l'assistant) ; l'assistant met à jour `docs/store/recette.md`, `docs/production.md` et `CLAUDE.md` après la signature
- **Jalon** : M4 Essais et publication
- **Taille** : M
- **Dépend de** : STORE-018 (accès accordé) ; avant : STORE-011 et STORE-012 si D5 = C (à faire avant, jamais après)
- **Faisable sans téléphone ni compte Play** : **non**
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 9 phase F, § 10 (risques) ; `docs/drive/runbook.md` (couper le drive) ; skill `repas-ops`.

## Contexte

La production rend l'application installable par n'importe qui en France. Elle charge le serveur de 1 Go, l'envoi des e-mails de confirmation et la boîte d'assistance. Elle est examinée par Google (jusqu'à 7 jours, plus pour certains comptes). Le déploiement d'une version à tout le monde est la seule étape d'un trajet qui se fait **avec la même version déjà éprouvée en test**.

## Portée

Guider, une action par message, selon F.1 à F.6 de l'analyse (§ 9, phase F) :

1. Contrôles avant : le site est à jour (`https://kantin.eu/`), le back et le front sont stables, `DRIVE_ENABLED` est dans l'état voulu, l'inscription ouverte est acceptée comme telle (D1), l'alias d'assistance est lu, les plafonds de débit sont en place, la dernière sauvegarde est fraîche (`docs/production.md`).
2. *Production > Créer une version* avec le **même bundle** que le test fermé ; pays : France ; envoyer pour examen.
3. Attendre la décision. Si la décision est un **refus**, lire la raison à l'écran, la consigner (sans donnée personnelle) et **ne pas contourner** : ouvrir un ticket ; si la raison est le drive, appliquer STORE-022 ou couper `DRIVE_ENABLED` (runbook) selon la décision du propriétaire.
4. Une fois « Production » : ouvrir la fiche publique depuis un téléphone qui n'est pas testeur, installer, vérifier la version et un parcours court.
5. Surveiller une semaine : journaux du back (aucune rafale de `401`, aucune erreur `5xx`), boîte d'assistance, notes et commentaires de la fiche ; répondre.
6. Signer la recette (`docs/store/recette.md`) ; mettre à jour `CLAUDE.md` (la ligne du chantier passe de « en cours » à l'état réel) et `docs/production.md` (section « Google Play »).

## Hors portée

Toute extension à d'autres pays ; la promotion de l'application ; un déploiement progressif (indisponible à la première version).

## Critères d'acceptation

- Étant donné la fiche publique, quand un téléphone non testeur l'ouvre, alors l'application s'installe et se lance.
- Étant donné la semaine qui suit, alors aucune alerte de santé du serveur n'est liée à la charge de l'application et la boîte d'assistance est lue.
- Étant donné la recette, alors elle est signée par le propriétaire avec la date.

## Tests exigés

Aucun automatique : la recette **est** le test.

## Definition of Done

Application publiée ; recette signée ; `CLAUDE.md` et `docs/production.md` à jour ; roadmap cochée.

## Pièges connus

- Pas de pourcentage de déploiement à la première version : tout le monde la voit au même moment.
- Une mise à jour ultérieure suit les mêmes règles de contenu : toute nouvelle donnée impose de revoir la fiche « sécurité des données ».
- Le compte de relecture reste valable (Google réexamine les mises à jour).
- L'ouverture au public peut amener des inscriptions inconnues : l'administrateur peut désactiver ou supprimer un compte (ADR 0021).
- Une suspension de l'application par Google est possible : le plan B (STORE-022 ou couper `DRIVE_ENABLED`) doit être prêt avant.
