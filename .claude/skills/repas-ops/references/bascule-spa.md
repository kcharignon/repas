# Donner la racine du domaine au SPA

À ne faire que sur l'**accord explicite** de l'utilisateur, après la recette de parité et la période de « dogfooding » (`docs/front-vuejs-plan.md` §10). Twig n'est pas supprimé par cette étape : il reste servi sur un autre nom.

Avant : `LEGACY_HOST=kantin.eu` (Twig), `APP_HOST=app.kantin.eu` (SPA).
Après : `APP_HOST=kantin.eu`, `LEGACY_HOST=twig.kantin.eu`.

1. Créer l'enregistrement DNS `A` `twig.kantin.eu` vers `64.227.124.100` (chez le gestionnaire DNS du domaine) ; attendre qu'il réponde (`dig +short twig.kantin.eu`).
2. Sauvegarde : `ssh deploy@… 'cd /opt/repas && ./backup.sh avant-bascule'`.
3. Éditer `/opt/repas/.env` (les deux lignes), puis `docker compose up -d edge`. Caddy obtient le certificat du nouveau nom ; celui de `kantin.eu` est déjà en cache (`CADDY_DATA_VOLUME`).
4. Vérifier : `https://kantin.eu/` sert le SPA, `https://twig.kantin.eu/login` sert Twig, `/api/v1/health` répond sur les deux.
5. **Retour arrière** : remettre les deux valeurs d'origine et `docker compose up -d edge` (quelques secondes).

Les cookies de session Twig sont rattachés au nom de domaine : les utilisateurs connectés sur `kantin.eu` devront se reconnecter sur `twig.kantin.eu`. Les anciennes URLs Twig (`/recipes`, `/shopping-list/active`…) sont redirigées côté front vers leurs équivalents.

Délai de grâce proposé : deux semaines sans retour arrière, puis extinction de Twig (PR dédiées du plan front §10), toujours sur accord explicite.
