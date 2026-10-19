# STORE-021 — (Facultatif) Envoi automatique de l'AAB vers la piste de test interne

- **Dépôt** : front (`.github/workflows/android-release.yml` ou un workflow voisin, `android/__tests__/publication.spec.ts`, `android/PUBLICATION.md`)
- **Jalon** : M5 Transition et suites
- **Taille** : S
- **Dépend de** : STORE-016 et **trois envois manuels réussis** (le premier envoi est manuel de toute façon)
- **Faisable sans téléphone ni compte Play** : le code oui ; la preuve exige la console Play
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire (facultatif)
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 5 ; [prise en main de l'API](https://developers.google.com/android-publisher/getting_started) et [`edits.bundles.upload`](https://developers.google.com/android-publisher/api-ref/rest/v3/edits.bundles/upload) ; [ADR 0017](../../adr/0017-deploiement-et-releases.md) (l'humain approuve) ; skill `repas-ops`.

## Contexte

L'envoi à la main (télécharger l'AAB, ouvrir la console, téléverser) est correct pour peu de versions. Si le rythme augmente, un compte de service peut envoyer l'AAB **vers la piste de test interne seulement**. La promotion en production reste un geste humain dans la console, comme l'approbation d'un déploiement du site.

## Portée

1. L'humain crée un **compte de service** (projet Google Cloud) avec l'API Google Play Developer activée, l'invite dans *Utilisateurs et autorisations* avec **uniquement** la permission de publier sur les pistes de test, et pose la clé JSON comme secret d'un **environnement GitHub** `play-interne` à approbation obligatoire du propriétaire. L'assistant ne voit jamais la clé.
2. Un job `play-interne` (après `build`, avant ou à côté de `publish`) : télécharge l'artefact `android-bundle`, vérifie sa somme, envoie vers la piste `internal` avec une action pinnée **par empreinte de commit** (ou l'API en `curl` authentifié), en brouillon de version ; aucune étape ne touche à la piste de production ; la clé n'est dans l'environnement que de l'étape d'envoi.
3. `publication.spec.ts` : le secret n'apparaît que dans un bloc `env`, la piste est littéralement `internal`, aucune autre piste n'est nommée, l'environnement exige une approbation.
4. `PUBLICATION.md` : comment l'activer, le couper, et révoquer la clé du compte de service.

## Hors portée

La promotion vers le test fermé ou la production ; le premier envoi ; tout compte de service avec des droits de production.

## Critères d'acceptation

- Étant donné une étiquette, quand le job d'envoi est approuvé, alors le même AAB que celui de l'artefact apparaît dans la piste interne de la console.
- Étant donné le workflow, alors aucune piste autre que `internal` n'y figure et le job échoue si l'approbation n'est pas donnée.
- Étant donné la clé du compte de service, alors elle n'est dans aucun fichier du dépôt ni journal.

## Tests exigés

`publication.spec.ts` ; revue manuelle du workflow ; essai réel par le propriétaire (la CI n'a pas accès à Play).

## Definition of Done

Celle du dépôt front ; description de PR : les permissions accordées au compte de service ; l'humain a fait un essai ; roadmap cochée.

## Pièges connus

- L'API ne peut **pas créer** l'application et le premier envoi doit être manuel (supposé, d'après des outils tiers).
- Un compte de service avec un droit de production serait un secret qui publie : ne jamais le lui donner.
- Une action tierce dans un workflow qui voit un secret est une dépendance de chaîne d'approvisionnement : la pinner par empreinte de commit et la relire.
- Les quotas de l'API sont documentés dans la page de l'API ; sans importance à ce rythme.
