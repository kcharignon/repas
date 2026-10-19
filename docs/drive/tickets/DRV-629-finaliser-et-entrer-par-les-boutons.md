# DRV-629 — « Finaliser » vers le panier et entrée par le bouton « Commencer mes courses »

- **Dépôt** : front (`drive-agent/src/leclerc/entry.ts`, `drive-agent/src/flow/`, `src/features/drive/leclerc.ts`, `android/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-625
- **Statut** : à faire (2026-10-19, issu des constats réels de [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 10)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md), [`DRV-625`](DRV-625-entrer-dans-le-magasin-sans-geste.md) (§ Réalisation), `hypotheses-site-reel.md` § 10, skills `repas-po`, `repas-agent-dev`, `repas-android-dev`.

## Contexte

DRV-625 a livré l'entrée sans geste et « Finaliser » dans l'application, sur deux hypothèses que le vrai site a démenties (2026-10-19, voir § 10) : « Commencer mes courses » est un **bouton sans adresse** (l'agent ne le suit donc jamais, il retombe toujours sur le geste manuel) ; la page du panier est **`/magasin-N-N-V/detail-panier.aspx`**, avec la **ville** dans le chemin, et le panier n'a pas de lien dans les pages (`BASKET_PATH` vide : « Finaliser » ouvre l'accueil du drive, une touche de trop).

## Portée

1. **Entrer par le bouton** : quand un plan est ouvert et que la page est l'accueil du magasin, l'agent repère le bouton « Commencer mes courses » (texte exact, bouton ou élément cliquable, dans la page) et le **clique** au rythme du `pacer`, une seule fois par envoi et par onglet. Il constate ensuite qu'une page du magasin du plan s'est ouverte (même hôte, chemin `/magasin-N-N-V`). Sinon (bouton absent, clic sans effet, défi anti-robot) : repli actuel sur le bandeau court « Touche « Commencer mes courses ». ». Aucune boucle, aucune écriture.
2. **Finaliser vers le panier** : « Finaliser sur Leclerc » ouvre dans l'application la page du panier : le chemin du magasin **avec la ville**, tel que la dernière page de magasin vue par l'application l'a donné (mémorisé côté natif, validé par le motif `^/magasin-\d+-\d+-[^/]+$` et l'hôte du magasin du plan), suivi du segment fixe `detail-panier.aspx`. Pas de chemin avec ville connu, ou page refusée (404) : repli sur l'accueil du drive (comportement actuel). Jamais une adresse tirée du message du plan.
3. **Sondes du diagnostic** : la ligne « Lien du panier » devient « Page du panier : `detail-panier.aspx` sous le chemin du magasin » (oui / non, d'après le chemin mémorisé) ; « Commencer mes courses » dit « bouton cliquable : oui / non ». Formes seulement.
4. Les textes suivent le glossaire et le **tutoiement** (DRV-627, fusionné le 2026-10-19), sans avertissement (règle 10).

## Hors portée

Le protocole (aucun champ nouveau), la commande et le paiement, toute écriture hors du panier.

## Critères d'acceptation

- Faux site fidèle : accueil avec **bouton** sans adresse → l'agent le clique et arrive sur la page du magasin ; bouton absent / clic sans effet / défi simulé → bandeau court, sans boucle.
- « Finaliser sur Leclerc » ouvre la page `…/detail-panier.aspx` sous le chemin avec ville quand il est connu, l'accueil sinon ; jamais le navigateur du téléphone.
- Deuxième envoi, tout connu : **4 touches** (Envoyer au drive, Confirmer, Ouvrir le drive, Finaliser) quand le clic du bouton marche, 5 sinon.

## Tests exigés

Faux site (`drive-agent/fake-leclerc/`) : bouton « Commencer mes courses » sans `href`, page `detail-panier.aspx`, 404 simulé ; tests unitaires du motif de chemin et du repli ; `make agent-e2e`, `make android-test`, `make android-e2e` (scénarios 2 à 15 adaptés) ; recette réelle à rejouer par l'utilisateur.

## Definition of Done

Celle du dépôt front ; agent incrémenté, empreinte recalculée ; touches avant / après dans « À relire ». À publier : le site avant l'application seulement si le protocole change (il ne change pas).

## Pièges connus

- Le site distingue une touche humaine d'un clic programmé : le clic de l'agent peut être ignoré ou déclencher le défi anti-robot. C'est un repli normal, pas une erreur ; la recette réelle le dira.
- La recherche se fait **sans** ville (`/magasin-N-N/recherche.aspx`) et la page du panier **avec** : ne pas confondre les deux chemins (hypothèses § 8 et § 10).
