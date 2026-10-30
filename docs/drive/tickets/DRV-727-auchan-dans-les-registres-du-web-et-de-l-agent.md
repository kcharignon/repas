# DRV-727 — Auchan dans le registre du web et celui de l'agent, éteint

- **Dépôt** : front (`src/features/drive/retailers.ts`, `src/i18n/`, `drive-agent/src/retailers/`, `drive-agent/protocol/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-704, DRV-708, DRV-716 (le contrat à jour), DRV-722
- **Statut** : fait (PR front #253, 2026-10-30, agent 0.48.0). Le point 4 (« Mets à jour l'application pour envoyer ta liste à {retailer} », et le garde-fou `app.info.retailers`) est **reporté sur DRV-710** ; `appName` d'Auchan (« Auchan ») est une hypothèse à confirmer ; le protocole de Leclerc borne l'identifiant de magasin à 12 chiffres alors que le contrat en admet 20 (écart ancien, à trancher) ; `storeTail` (3 derniers caractères) s'appliquera à un UUID. Effort recommandé : `high`.
- **Lire d'abord** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) ; [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) ; [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4, 8 ; skills `repas-front-dev`, `repas-agent-dev`, `repas-po` (textes des conditions d'utilisation).

## Portée

1. **Web** : entrée `auchan` de `retailers.ts` (nom, `nameDe`, accueil `https://www.auchan.fr/courses`, règle d'adresse constante, source de l'identifiant de magasin explicite) d'après le contrat ; `parseStoreAddress` sait qu'un magasin d'Auchan ne se lit pas dans l'adresse ; section « Auchan » des conditions d'utilisation (**ne décrire que ce que Repas fait** : l'agent remplit le panier dans la page de la personne, avec son compte, sans jamais valider ni payer ; texte validé par le propriétaire dans la PR ; le sujet des CGU de l'enseigne est clos). Aucun écran nouveau : Auchan n'apparaît que si le back la sert dans `retailers` et que l'application l'annonce.
2. **Agent** : entrée `auchan` du registre (`id`, `matchesHost('www.auchan.fr')`, pas de `compte.auchan.fr`), `siteMode: AS_IS`, `antiBot: DATADOME`, cadence de départ **non mesurée** (pause 2 000 ms, gigue 1 000 ms, plafond 4 s : DRV-715 § 2 point 7), adaptateur **vide** (chaque méthode lève `CONTRACT_CHANGED` avec la cause « adaptateur non écrit » : DRV-717 le remplit) ; protocole : `retailer` ∈ `{leclerc, auchan}`, l'hôte d'un `store` se valide **par enseigne** (le magasin d'Auchan est l'adresse constante), exemples valides et invalides.
3. **Contrat** : le contrat du back (deux entrées) est recopié (`make samples-update`) ; les tests de concordance du web et de l'agent le comparent.
4. Le texte « Mets à jour l'application pour envoyer ta liste à {retailer}. » existe déjà (DRV-708/710) : le tester avec Auchan.

## Hors portée

L'adaptateur réel (DRV-717, 724, 725), l'application Android (DRV-728), allumer Auchan.

## Critères d'acceptation

- Étant donné `retailers = [leclerc]` servi par le back, alors **rien ne change** pour la personne (écrans, textes, captures).
- Étant donné un plan d'Auchan envoyé à l'agent sur une page de Leclerc (ou l'inverse), alors `STORE_MISMATCH` sans aucune requête.
- Le mot « Auchan » n'apparaît dans `src/` et `drive-agent/src/` que dans les registres et le fichier de textes des conditions (test d'architecture).

## Tests exigés

`make check`, `make agent-verify`, Vitest (registre, contrat, élision « d’Auchan »), tests de protocole, captures lues.

## Definition of Done

Celle du dépôt front ; ordre de publication dit dans le rapport (front avant application) ; aucune étiquette ; dire ce qu'il faut cocher dans le back.

## Pièges connus

- Ne pas copier les conditions d'utilisation de Leclerc : chaque enseigne a ses mots.
- Le web ne doit pas proposer Auchan à une application qui ne l'annonce pas.
