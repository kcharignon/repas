# DRV-003 — Lecture des CGU et CGV de Leclerc

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../spikes/DRV-003-cgu.md`](../spikes/DRV-003-cgu.md), [`../recherche.md`](../recherche.md) § 4

## Contexte

Le fichier `robots.txt` autorise l'accès, mais il n'est pas une autorisation juridique. La CJUE a admis (*Ryanair c. PR Aviation*, C-30/14) qu'un exploitant limite par contrat l'extraction automatisée de son site. Les conditions de Leclerc ne sont pas lisibles par un outil automatique (blocage). Il faut qu'un humain les lise.

## Portée

L'utilisateur lit, sur le site, les Conditions Générales d'Utilisation et de Vente d'E.Leclerc et du drive (liens depuis `https://www.e.leclerc/e/legal` et le pied de page de `leclercdrive.fr`), cherche les mots listés dans le gabarit, et remplit `docs/drive/spikes/DRV-003-cgu.md` avec l'article, une citation courte et son interprétation. Il ouvre une PR docs avec le rapport.

## Hors portée

Un avis juridique professionnel. Une prise de contact avec Leclerc (à décider après lecture).

## Critères d'acceptation

- Toutes les lignes du tableau du gabarit sont remplies (« rien trouvé » est une réponse).
- La conclusion indique si une clause interdit explicitement les outils tiers, le niveau de risque retenu pour un usage personnel et familial et l'action proposée.
- Aucune citation de plus de deux phrases.
