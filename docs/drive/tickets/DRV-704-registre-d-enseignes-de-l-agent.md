# DRV-704 — Registre d'enseignes de l'agent, types neutres, protocole par enseigne

- **Dépôt** : front (`drive-agent/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701 (le contrat `retailers.sample.json` à recopier)
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.4, 4.3, 8 ; [`../architecture.md`](../architecture.md) §§ 8, 12 ; `drive-agent/protocol/README.md` ; `.claude/skills/repas-agent-dev/SKILL.md` (règles de sécurité, construction reproductible, protocole v1) ; DRV-302, DRV-303.

## Contexte

L'agent est déjà bien rangé : tout ce qui est propre au site de Leclerc est dans `drive-agent/src/leclerc/`, derrière l'interface `RetailerAdapter`. Mais ses types portent le nom Leclerc (`LeclercError`, `LeclercCause`), `agent.ts` instancie `LeclercAdapter` en dur, et le **protocole** fige l'enseigne : `plan.schema.json` (`"retailer": {"const": "leclerc"}`), `protocol/plan.ts` (`exactly(message, 'retailer', 'leclerc')`), `types.ts` (`retailer: 'leclerc'`), et le motif d'hôte `*.leclercdrive.fr` (`protocol/check.ts`, exposé comme `STORE_HOST`).

## Portée

1. **Types neutres.** `RetailerError`, `RetailerErrorCode`, `RetailerCause` dans une couche commune (`drive-agent/src/retailers/`), utilisés par `flow/`, `diagnostic/`, `selftest/` à la place de `Leclerc*`. Le dossier `leclerc/` les implémente (alias conservés le temps d'une transition, retirés dans la même PR si possible).
2. **Registre.** `drive-agent/src/retailers/index.ts` : la **seule** liste des enseignes, chaque entrée `{ id, matchesHost(host), createAdapter(deps) }`. `agent.ts` choisit l'adaptateur d'après l'hôte de la page **et** `plan.retailer` ; hôte d'une enseigne et plan d'une autre : `STORE_MISMATCH` sans requête. Aujourd'hui une seule entrée : `leclerc`.
3. **Protocole.** `retailer` devient une énumération (`["leclerc"]`) dans le schéma, `plan.ts` et `types.ts` ; la validation de l'hôte d'un magasin est **par enseigne** (le motif d'hôte de Leclerc ne vaut que pour `retailer: "leclerc"`). Les exemples `examples/valid` et `examples/invalid` restent valables sans modification d'attente ; ajouter un exemple invalide « enseigne inconnue » et un « hôte d'une autre enseigne ».
4. **Contrat.** `make samples-update` recopie `retailers.sample.json` dans `drive-agent/fixtures/contract/` ; un test vérifie que les identifiants, noms et règles d'hôte du registre de l'agent sont ceux du contrat.
5. **Garde-fou.** Test d'architecture : le mot « leclerc » (casse ignorée) n'apparaît dans `drive-agent/src/` qu'à l'intérieur de `src/leclerc/` et de l'entrée du registre ; `flow/`, `diagnostic/`, `selftest/`, `ui/` ne l'importent plus.
6. **Version de l'agent** : relever `AGENT_VERSION` et mettre à jour l'empreinte (`android/agent.js.sha384`) comme le demande le skill ; **aucune publication** (le registre ne change aucun message).

## Hors portée

Les textes (DRV-705), l'application (DRV-706, 707), un adaptateur d'une autre enseigne (DRV-717), toute modification du comportement de Leclerc.

## Critères d'acceptation

- Étant donné la suite de tests existante (`make agent-verify`, `make agent-e2e` contre le faux site), alors elle passe **sans changement d'attente**.
- Étant donné un plan `retailer: "leclerc"` sur une page `*.leclercdrive.fr`, alors le passage se déroule comme avant ; sur une page d'un autre hôte, `STORE_MISMATCH`/`UNSUPPORTED_PAGE` comme avant.
- Étant donné un plan `retailer: "inconnue"`, alors `INVALID_MESSAGE` sur le chemin `retailer`.
- Étant donné un registre de test à deux entrées fictives, alors l'agent choisit l'adaptateur de l'hôte de la page et refuse un plan de l'autre enseigne.

## Tests exigés

Tests du protocole (schéma et validateur écrits à la main, accord garanti par `examples/`), tests de sélection d'adaptateur, test d'architecture, `make agent-verify`, `make agent-e2e`, `make check`.

## Definition of Done

Celle du dépôt front et du skill `repas-agent-dev` ; `drive-agent/protocol/README.md` et `architecture.md` § 8 du back à jour (le back est indiqué dans le rapport : l'assistant le met à jour) ; **dire dans le rapport** que ce changement n'impose aucun ordre de publication particulier (aucun champ ajouté) mais que la publication de l'agent suit la règle « front avant application » ; aucune étiquette créée.

## Pièges connus

- Le module de protocole n'a aucune dépendance et pèse moins de 6 Ko (un test le mesure) : ne pas y importer le registre de l'agent ; le protocole reçoit la liste des enseignes en paramètre ou en constante propre.
- Le validateur du protocole rejette toute clé inconnue : ne rien ajouter au `result`.
- La construction est reproductible : un octet de plus change l'empreinte SHA-384 ; relancer la construction et recopier l'empreinte.
