# DRV-629 — « Finaliser » vers le panier et entrée par le bouton « Commencer mes courses »

- **Dépôt** : front (`drive-agent/src/leclerc/entry.ts`, `drive-agent/src/flow/`, `src/features/drive/leclerc.ts`, `android/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-625
- **Statut** : fait (2026-10-19, PR repas-front#151, agent 0.24.0)
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

## Réalisation (2026-10-19, PR repas-front#151, agent 0.24.0)

Fusionné dans le front `main` (`35c3532`) ; publié dans le site `v0.5.5` et l'application `android-v0.1.8`. Empreinte de l'agent 0.24.0 : `sha384-LBRdgL7b2Svs38ohvFmAL0WGvLQfoOol2ZdJURaib9mdB/RJHczFHw3YltR4sgW/`. Le protocole ne change pas (aucune clé nouvelle).

- **Entrer par le bouton** : l'agent trouve « Commencer mes courses » dans la page (`findStartButton`, `drive-agent/src/leclerc/entry.ts`, lecture `BUTTON`) et le clique au rythme du `pacer`, une fois par envoi et par onglet ; un vrai lien reste suivi avec les mêmes contrôles. Bouton absent, clic ignoré (le site refuse le clic d'un programme) ou défi anti-robot : bandeau « Touche « Commencer mes courses ». », sans boucle ni écriture. Si le bouton mène à un autre magasin que celui du plan, l'agent clique quand même (l'adresse est inconnue avant le clic) et affiche « Ce n'est pas le bon magasin » à l'arrivée.
- **Finaliser vers le panier** : côté Android, `StorePathMemory` retient le chemin `https://<hôte>/magasin-N-N-Ville` de la dernière page de magasin chargée sans erreur (préférence privée, effacée par « Se déconnecter de Leclerc ») ; `DriveEntry` ouvre `<chemin>/detail-panier.aspx` dans le drive de l'application, seulement si l'hôte et les deux numéros sont ceux du lien du front, sinon l'accueil ; repli sur l'accueil pour les erreurs 4xx et 5xx sauf 403 et 429 (souvent le défi anti-robot). `BASKET_PATH` du front vaut `/detail-panier.aspx` ; dans un navigateur, « Finaliser » garde l'accueil.
- **Sondes du diagnostic** : « Commencer mes courses : bouton cliquable » et « Page du panier (detail-panier.aspx sous le chemin du magasin) » (oui / non / sans objet), formes seulement.
- **Touches du deuxième envoi, tout connu** : avant (sur le vrai site) Envoyer au drive, Confirmer, Ouvrir le drive, « Commencer mes courses » à la main, Commencer, Finaliser, puis le panier à chercher ; **après** Envoyer au drive, Confirmer, Ouvrir le drive, Commencer, Finaliser, et la page du panier s'ouvre directement (une touche de plus si le clic est ignoré).
- **Vérifié** : `make check` (2800 tests), `make quality`, `make agent-verify`, `make agent-e2e` (69), `make e2e` (235), `make android-test`, `make android-e2e` (200 contrôles, aucun échec ; scénarios 16 à 18 nouveaux : page du panier 404, chemin effacé, clic ignoré).

### À savoir

- **Ordre de publication : l'application avant le site.** L'application ancienne qui recevrait le nouveau lien du site ouvrirait `…/magasin-N-N/detail-panier.aspx`, sans ville, introuvable sur le vrai site. Le protocole ne change pas, donc la règle « le site avant l'application » ne s'applique pas à ce ticket.
- Le clic programmé du bouton n'est **pas encore vérifié sur le vrai site** : le diagnostic et la recette réelle diront s'il est accepté.
