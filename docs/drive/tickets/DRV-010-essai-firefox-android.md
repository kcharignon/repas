# DRV-010 — Essai du favori de démarrage dans Firefox Android

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : DRV-009 (fait) ; accord de l'utilisateur pour commander dans Firefox
- **Statut** : à faire (en attente de l'accord de l'utilisateur)
- **Lire d'abord** : [`../spikes/DRV-006-installation-favori.md`](../spikes/DRV-006-installation-favori.md) (suite), [`../analyse.md`](../analyse.md) (D20, D20b)

## Contexte

Chrome Android ne lance pas les favoris JavaScript sur le téléphone de l'utilisateur. Firefox Android est la piste suivante : des témoignages indiquent que ses favoris JavaScript se lancent depuis le menu des marque-pages (le lancement par le nom tapé dans la barre d'adresse y est moins sûr), et que les gestionnaires de scripts Violentmonkey et Tampermonkey y sont disponibles.

## Portée

1. Installer Firefox sur le téléphone, y créer à la main un marque-page nommé `lab2` dont l'adresse est le contenu de `drive-agent/lab/dist/lab-loader.txt` du dépôt front (500 caractères, envoyé par e-mail), ouvrir le drive Leclerc dans Firefox (connexion faite par l'utilisateur), page de recherche « farine », puis lancer `lab2` depuis le menu des marque-pages.
2. Noter dans `docs/drive/spikes/DRV-002-rapport.md` les lignes propres au téléphone (1, 2, 6, 9 à 13) et, dans le rapport DRV-006, si le menu du banc s'est affiché, comment le lancer (menu des marque-pages ou nom tapé) et ce qui gêne.
3. Si le favori ne se lance pas, noter le message exact ; la piste suivante est un userscript (Violentmonkey) lancé automatiquement sur `leclercdrive.fr`.

## Hors portée

Toute modification de code.

## Critères d'acceptation

- Le rapport dit si le banc d'essai s'affiche dans Firefox Android et comment le lancer.
- Les essais 1, 2 et 3 ont été faits sur le téléphone, ou la raison de leur échec est notée.
