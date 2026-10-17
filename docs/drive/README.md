# Chantier Drive : envoyer la liste de courses au drive (E.Leclerc)

**À lire en premier par tout agent qui reprend ce chantier.** Rédigé le 2026-10-17 par l'assistant Product Owner / architecte, qui n'implémente pas : d'autres agents exécutent les tickets.

## Le besoin en deux phrases

Sur Android, une fois la liste de courses prête, l'utilisateur touche « Envoyer au drive » : la liste est verrouillée (`SENT`), Repas ouvre le drive Leclerc dans Chrome, un favori JavaScript (l'**agent**) met au panier les produits déjà connus avec la bonne quantité et fait choisir un produit pour chaque nouvel ingrédient ; Repas mémorise les choix. L'utilisateur finalise sa commande sur leclercdrive.fr. Aucun identifiant ni cookie Leclerc ne passe par nos serveurs.

## Où en est-on

**Décision G1 du 2026-10-17 : GO.** Le moyen d'exécuter l'agent est une **application Android** (WebView en mode ordinateur, agent embarqué), car Chrome Android refuse les favoris JavaScript. Lire [`spikes/DRV-005-decision-g1.md`](spikes/DRV-005-decision-g1.md) (preuves et décisions) et [`pivot-application-android.md`](pivot-application-android.md). Les tickets M1 à M5 peuvent démarrer selon leurs dépendances ; M3b regroupe l'application.

Voir [`roadmap.md`](roadmap.md) (cases à cocher). Rien de produit n'est codé avant la décision **G1** (DRV-005), qui dépend de trois découvertes : le banc d'essai sur le téléphone (DRV-001, DRV-002), les CGU de Leclerc (DRV-003) et ses en-têtes de sécurité (DRV-004).

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
| `tickets/DRV-*.md` | Un ticket par fichier, autonome. |
| `spikes/` | Gabarits des rapports de découverte (à remplir par l'humain ou l'agent). |
| `plan-initial.md` | Copie intégrale du plan, pour ne rien perdre. |
