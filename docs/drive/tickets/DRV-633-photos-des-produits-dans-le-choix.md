# DRV-633 — Photos des produits dans le choix de l'agent

- **Dépôt** : front (`drive-agent/src/leclerc/`, `drive-agent/src/ui/`, `drive-agent/fake-leclerc/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : — (le protocole ne change pas)
- **Statut** : lot 1 fait (PR front #156, fusionnée le 2026-10-19) ; origine : (demande de l'utilisateur du 2026-10-19 : « ma compagne a besoin de photo pour choisir le bon produit, car on a une mémoire visuelle des paquets »)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (règles 1, 2, 8 et 10), [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) (§ 2 et § 8 : le JSON de la recherche porte `sUrlVignetteProduit`), skills `repas-po`, `repas-agent-dev`.

## Contexte

Pour choisir un produit parmi les résultats de Leclerc, on reconnaît le paquet, pas son libellé. L'écran de choix de l'agent n'affiche aujourd'hui que du texte (libellé, taille, prix). La recherche de Leclerc renvoie déjà l'adresse de la photo de chaque produit (`sUrlVignetteProduit`, vue dans le diagnostic : clé « connue présente »). Les photos s'affichent **dans la page de Leclerc**, donc depuis les serveurs de Leclerc, comme quand on parcourt le site : aucun tiers, aucun serveur de Repas, aucune donnée enregistrée.

## Portée (premier lot : le choix de l'agent seulement)

1. **Lire l'adresse de la photo** de chaque produit dans les résultats (adaptateur de recherche) : champ optionnel de la fiche lue (`imageUrl`), jamais envoyé à Repas (aucun changement du protocole ni du résultat de l'agent).
2. **Valider l'adresse** avant tout affichage : `https` seulement, hôte `leclercdrive.fr` ou un sous-domaine, ou l'hôte d'images que le site de Leclerc utilise lui-même (le relever dans le faux site et dans le diagnostic, le documenter), pas d'identifiant ni de port inattendu, résolue par rapport à la page (adresse relative acceptée), 2 000 caractères au plus ; sinon pas de photo (jamais une erreur).
3. **Afficher la photo** sur chaque fiche de l'écran de choix, à gauche du libellé, environ 80 px de côté (`object-fit: contain`, fond clair car les photos sont sur fond blanc, aussi en thème sombre), chargement différé (`loading="lazy"`, `decoding="async"`), texte alternatif = libellé du produit, **les 12 premiers résultats seulement** (pas de rafale d'images). Photo absente, adresse refusée ou image qui ne charge pas : la fiche reste en texte, **sans icône d'image cassée** (masquer l'élément à l'erreur). `referrerpolicy` et en-têtes comme une image de la page elle-même (aucun réglage spécial qui ferait différer ces requêtes de celles de la page).
4. **Question « Toujours prendre ce produit ? »** : la photo du produit choisi est montrée à côté de son libellé.
5. **Mise en page à 360 × 640** : photo et texte côte à côte, bouton « Choisir » pleine largeur sous la fiche (≥ 44 px), pas de défilement horizontal, thème clair et sombre ; un écran = une décision, la liste ne s'allonge pas démesurément (fiche compacte).
6. **Faux site** : des fiches avec une photo (petite image synthétique servie par le faux site, jamais une vraie image de Leclerc), une fiche sans photo, une fiche avec une adresse refusée (autre hôte) et une image qui répond 404.
7. **Aucun texte visible nouveau** hors le texte alternatif ; voix : tutoiement ; règle 10.

## Hors portée

Les photos dans Repas (« Mes produits », plan d'envoi, récapitulatif) : elles demandent de **retenir l'adresse de la photo** dans le back (colonne du produit), un champ de plus dans le résultat de l'agent (le site doit alors être publié avant l'application), et l'ouverture de la CSP du site à l'hôte d'images de Leclerc : **lot 2, DRV-634** (à écrire après la recette de ce lot). La photo de l'ancien produit habituel devenu indisponible (non retenue). Toute récupération de la photo par Repas (jamais).

## Critères d'acceptation

- Étant donné des résultats avec photos, quand l'écran de choix s'affiche, alors chaque fiche (12 au plus) montre sa photo à côté du libellé et le bouton « Choisir » reste visible et large.
- Étant donné une photo absente, refusée ou en 404, quand la fiche s'affiche, alors elle est en texte, sans icône cassée, et rien ne bloque le choix.
- Étant donné la question « Toujours prendre ce produit ? », quand elle s'affiche, alors elle montre la photo du produit choisi.
- Aucune requête d'image vers un hôte autre que celui des images de Leclerc ; le résultat renvoyé à Repas est inchangé octet pour octet (test).

## Tests exigés

Tests jsdom de l'agent (validation d'adresse : tableau de cas hostiles, fiches avec / sans photo, 404), faux site (photo servie, 404), e2e Playwright `agent-ui` (clair et sombre, 360 × 640, cibles de 44 px, axe), `make agent-verify` (construction reproductible), `make android-e2e` (scénario du choix), captures lues dans Chromium et dans l'émulateur.

## Definition of Done

Celle du dépôt front ; agent incrémenté, empreinte recalculée ; photos **non vérifiées sur le vrai site** : à confirmer à la recette réelle (l'hôte d'images, le temps de chargement, DataDome). Rien à publier côté site (protocole inchangé).

## Pièges connus

- L'hôte des images de Leclerc n'est peut-être pas `leclercdrive.fr` (CDN) : le relever dans le diagnostic avant de figer la liste blanche ; ne jamais accepter un hôte arbitraire.
- Les images chargent depuis la page de l'enseigne : une rafale peut ressembler à un robot ; 12 images au plus, différées.
- Les photos ont un fond blanc : en thème sombre, leur donner un petit cadre clair plutôt que de les inverser.

## Réalisation du lot 1 (PR front #156, fusionnée le 2026-10-19)

- L'écran de choix de l'agent montre la photo de chaque produit (80 px, à gauche du libellé, chargement différé) ; « Choisir » passe sous la fiche ; la question « Toujours prendre ce produit ? » montre la photo du produit choisi.
- L'adresse `sUrlVignetteProduit` est validée deux fois (`photo-url.ts`, adaptateur puis interface) : `https`, hôte `leclercdrive.fr` ou sous-domaine, ni identifiant ni port, 2 000 caractères au plus. Sinon la fiche reste en texte, sans icône cassée.
- Protocole inchangé : aucune adresse de photo dans le résultat, l'état de reprise ni vers Repas. Agent 0.27.0.
- Écart : le plafond de 12 photos était lié aux fiches affichées alors (8 au plus) ; il est levé ensuite par DRV-643 (PR front #163, photos à la demande).
- Points à confirmer sur le vrai site : l'hôte réel des images (supposé `fdN-photos.leclercdrive.fr`), adresse absolue ou relative, une éventuelle CSP de l'enseigne, DataDome. Lot 2 (photos dans Repas) : DRV-634, hors portée.
