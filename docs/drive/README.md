# Chantier Drive : envoyer la liste de courses au drive (E.Leclerc)

**À lire en premier par tout agent qui reprend ce chantier.** Rédigé le 2026-10-17 par l'assistant Product Owner / architecte, qui n'implémente pas : d'autres agents exécutent les tickets. État mis à jour le 2026-10-18 (audit de cohérence).

## Le besoin en deux phrases

Sur Android, une fois la liste de courses prête, l'utilisateur touche « Envoyer au drive » : la liste est verrouillée (`SENT`), l'application Android de Repas ouvre le drive Leclerc sur un second écran (WebView en mode ordinateur), où un programme embarqué (l'**agent**) met au panier les produits déjà connus avec la bonne quantité et fait choisir un produit pour chaque nouvel ingrédient ; Repas mémorise les choix. L'utilisateur finalise sa commande sur le site de Leclerc. Aucun identifiant ni cookie Leclerc ne passe par nos serveurs.

## Où en est-on

**Décision G1 du 2026-10-17 : GO.** Le moyen d'exécuter l'agent est une **application Android** (WebView en mode ordinateur, agent embarqué), car Chrome Android refuse les favoris JavaScript ; depuis le 2026-10-18 (D28) c'est l'application **Repas complète** (coquille autour du front, le drive est son second écran, pont natif `repasNative`). Lire [`spikes/DRV-005-decision-g1.md`](spikes/DRV-005-decision-g1.md) (preuves et décisions) et [`pivot-application-android.md`](pivot-application-android.md). Le favori JavaScript et le fragment d'adresse vers Leclerc sont abandonnés : tout passage de la documentation qui les décrit est historique.

**Au 2026-10-18** : le back est terminé et fusionné sur `main` (verrou `SENT`, contexte `Drive`, plan, choix, estimation du coût, durcissement DRV-613 partie 1) ; l'agent, l'application (coquille, passage du plan, test automatique, émulateur partie 1) et les écrans du front sont fusionnés. **Restent** : la publication signée de l'application (DRV-315, étapes humaines), la lecture du magasin depuis l'application (DRV-320), la partie 2 de DRV-613 (agent), l'émulateur complet (DRV-319 partie 2), puis la **recette sur téléphone** ([`recette.md`](recette.md), DRV-505, par l'utilisateur) et l'activation de `DRIVE_ENABLED` en production. D'après `gh release list` (2026-10-18), rien du drive n'est encore déployé en production : les dernières versions publiées, le back `v0.5.0` et le front `v0.4.1`, n'en contiennent rien. Suivi des cases : [`roadmap.md`](roadmap.md).

## Ordre de lecture

1. Ce fichier.
2. [`architecture.md`](architecture.md) : invariants, règle de liste envoyée, contexte `Drive`, API, protocole de l'agent, front, sécurité, tests, déploiement. **Référence technique.**
3. [`roadmap.md`](roadmap.md) puis le ticket choisi dans [`tickets/`](tickets/) (autonome).
4. [ADR 0022](../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../adr/0023-liste-envoyee-au-drive.md) (décisions ; elles gagnent en cas d'écart).
5. Au besoin : [`analyse.md`](analyse.md) (pourquoi), [`recherche.md`](recherche.md) (faits sourcés), [`plan-initial.md`](plan-initial.md) (copie du plan complet du 2026-10-17).
6. [`consignes-agents.md`](consignes-agents.md) : règles communes à tout agent qui implémente un ticket.
7. Les règles du dépôt où l'on travaille : `CLAUDE.md`, skill `.claude/skills/repas-dev/` (back) ; `CLAUDE.md` du front.

## Dépôts

- **Back** : `/home/kantin/Projet/Repas`, `kcharignon/repas` (public). Contextes `Repas`, `User`, `Shared` et le nouveau `Drive`.
- **Front** : `/home/kantin/Projet/repas-front`, `kcharignon/repas-front` (public). Vue 3 + TypeScript ; tout tourne dans Docker (`make up`, `make check`). L'agent vit dans son dossier `drive-agent/`.

## Règles du chantier

- **Invariants** (`architecture.md` § 2) : aucun identifiant, cookie ni jeton d'enseigne chez nous ; le back ne contacte jamais une enseigne ; pas de code distant dans l'agent ; l'agent ne valide ni ne paie jamais ; une liste `SENT` n'est plus modifiable ; un envoi ne coche aucune ligne ; le panier de l'enseigne n'est jamais vidé.
- **Dépôts publics** : aucun secret, identifiant, adresse e-mail personnelle, numéro de magasin personnel ni HTML brut de Leclerc dans le code, les tests ou la doc. Les tests utilisent des données synthétiques.
- **Leclerc ne s'ouvre jamais depuis un serveur** : DataDome bloque. Les essais réels se font sur le téléphone de l'utilisateur (tâches `humain`).
- Un ticket = une PR relisible. Si un ticket impose une décision absente de l'ADR, écrire ou amender l'ADR dans la même PR.
- Si un fait de `recherche.md` se révèle faux ou périmé, le corriger avec la date et la source.
- Ne pas demander à l'utilisateur « quand lancer le code » : les tickets disent ce qui est prêt (dépendances fusionnées). Demander seulement ce qui bloque vraiment, en phrases simples et précises (l'utilisateur n'est pas anglophone natif).

## Glossaire

Voir `architecture.md` § 1 : drive, enseigne, magasin, agent, plan, produit, choix, liste envoyée, passage (`runId`).

## Fichiers

| Fichier | Rôle |
|---|---|
| `README.md` | Ce point d'entrée. |
| `architecture.md` | Référence technique. |
| `analyse.md` | Analyse produit, options écartées, décisions par défaut, risques. |
| `recherche.md` | Faits sourcés et ce qui reste à vérifier. |
| `roadmap.md` | Jalons, tickets, dépendances, Definition of Done. |
| `runbook.md` | « Leclerc a changé » : symptômes, diagnostic, coupure (`DRIVE_ENABLED=false`), repli, correction, retour en service (DRV-503). Mise en production et retour arrière : `../production.md`. |
| `note-vie-privee.md` | Note de vie privée de la fonction (texte court et complet à afficher dans le front, ce qui est vrai et d'où ça vient, fiche de registre des traitements) (DRV-504, partie back). |
| `revue-securite-back.md` | Revue indépendante de sécurité et de qualité du back (2026-10-18) : défauts corrigés, risques à décider (fiches de produits partagées), points vérifiés, recommandations pour la recette. |
| `recette.md` | Procédure de recette en conditions réelles (DRV-501 pour l'écriture, DRV-505 pour l'exécution) : pas à pas pour l'utilisateur sur son téléphone, critères de réussite, mesures, tableau de signature. |
| `tickets/DRV-*.md` | Un ticket par fichier, autonome. |
| `spikes/` | Gabarits des rapports de découverte (à remplir par l'humain ou l'agent). |
| `plan-initial.md` | Copie intégrale du plan, pour ne rien perdre. |
