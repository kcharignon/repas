# Retour d'urgence vers Twig

Twig a été supprimé du back le 2026-10-16 ([ADR 0018](../../../../docs/adr/0018-extinction-de-twig.md)). À n'utiliser que si le front est inutilisable et qu'il faut rendre l'ancien site le temps de réparer. Dernière version du back qui servait Twig : **`v0.1.0-rc1`** (le schéma de la base n'a pas changé depuis ; si une release ultérieure a ajouté une migration, vérifier d'abord qu'elle est compatible avec ce code, ADR 0017 règle 6).

À décider avec l'utilisateur, une étape à la fois.

1. **Redéployer l'ancienne version** : sur GitHub, Actions du dépôt `kcharignon/repas`, le run « Release » de `v0.1.0-rc1`, bouton **Re-run all jobs**. Il recopie le `deploy/` de cette version (dont l'ancien `Caddyfile`, qui relaie `LEGACY_HOST` vers le back) puis lance `deploy.sh back v0.1.0-rc1` après l'approbation de l'utilisateur. Une sauvegarde est faite avant.
2. **Donner la racine à Twig** : dans `/opt/repas/.env`, `LEGACY_HOST=kantin.eu` et `APP_HOST=app.kantin.eu`, puis `docker compose up -d edge` (script écrit dans le scratchpad, lancé par l'utilisateur avec `!`).
3. **Vérifier** : `https://kantin.eu/login` sert l'ancien site, `https://app.kantin.eu/` le nouveau front, `/api/v1/health` répond sur les deux. Les utilisateurs devront se reconnecter.

Pour repartir sur le front seul : redéployer la version courante (`gh run rerun` du dernier run « Release » ou nouvelle release) puis remettre `APP_HOST=kantin.eu` et `LEGACY_HOST=app.kantin.eu`.

L'ancienne installation arrêtée (`/root/repas`, base datant de la bascule des données du 2026-10-16) n'est **pas** un retour possible : ses données sont périmées.
