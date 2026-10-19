# AUTH-002 — Console Google Cloud : projet, écran de consentement, deux clients OAuth

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M0 Décisions et console
- **Taille** : M (une demi-heure, seize étapes courtes)
- **Dépend de** : AUTH-001 (D4)
- **Faisable sans téléphone** : oui (un ordinateur suffit ; la clé de l'application se lit dans le terminal)
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) § 6 (les seize étapes, leur but et leur résultat attendu) ; `android/PUBLICATION.md` du dépôt front (étape 2 : lecture de l'empreinte) ; skill `repas-ops` (une étape par message, but expliqué, attendre son résultat, jamais de secret dans le chat).

## Contexte

Google doit connaître Repas (site et application) pour accepter le bouton et signer des jetons pour notre « ID client ». Tout se fait dans la console de Google Cloud, avec le compte Google de l'utilisateur. Aucun code, aucun dépôt touché ; aucun paiement n'est demandé pour cela (supposé : à confirmer à l'écran à l'étape 2).

## Portée

Guider l'utilisateur, **une action par message**, selon les étapes 1 à 16 du § 6 de l'analyse :

1. Ouvrir la console et se connecter. 2. Créer le projet « Repas ». 3. Ouvrir « Google Auth Platform ». 4. Informations sur l'application. 5. Audience « Externe ». 6. Contact et création. 7. Vérifier que les portées sont `openid`, `email`, `profile` et rien d'autre. 8. « Publier l'application » (statut « En production »). 9. Créer le client **Application Web** (origine `https://kantin.eu`, aucune URI de redirection). 10. Noter l'ID client dans son gestionnaire de mots de passe. 11. Lire l'empreinte SHA-1 du certificat de publication. 12. Créer le client **Android** (paquet `eu.kantin.repas.drive`, SHA-1). 13. **Ne pas** enregistrer l'empreinte de la clé de débogage. 14. (Recommandé) un second propriétaire du projet. 15. Rappel annuel (client inactif six mois = supprimé). 16. Garder l'ID client pour AUTH-008.

Si D4 = oui : ajouter les étapes de la vérification de marque (domaine `kantin.eu` dans Search Console, pages publiques, « Vérifier la marque ») après la publication de la page de conditions (AUTH-010).

À chaque étape l'assistant : dit le but en une phrase, donne l'adresse ou le nom du menu, dit ce que l'utilisateur doit voir si c'est bon, puis attend. Si le nom d'un menu a changé, il le lit sur la capture que l'utilisateur lui décrit, il ne devine pas.

## Hors portée

Le serveur (AUTH-008) ; le code ; la vérification de marque si D4 = non ; la création d'un second projet « Repas essai » (seulement si un essai de débogage Android est voulu un jour).

## Critères d'acceptation

- Étant donné la console terminée, quand l'utilisateur ouvre « Google Auth Platform » > « Audience », alors le statut est « En production », le type « Externe ».
- Quand il ouvre « Clients », alors il y a **exactement deux** clients : un « Application Web » dont les origines autorisées sont `https://kantin.eu` et aucune autre, sans URI de redirection ; un « Android » de paquet `eu.kantin.repas.drive` dont l'empreinte est celle de la clé de **publication**.
- Quand il ouvre « Accès aux données », alors aucune portée sensible n'est listée.
- L'ID client Web est conservé par l'utilisateur, **ni dans le chat, ni dans le dépôt**.

## Tests exigés

Aucun automatique. Preuve : l'utilisateur dit « fait » à chaque étape, l'assistant note la date de fin dans la roadmap (sans aucune valeur d'identifiant).

## Definition of Done

Les deux clients existent ; le statut est « En production » ; l'ID client Web est prêt pour AUTH-008 ; la roadmap est cochée dans la PR de documentation suivante.

## Pièges connus

- **L'empreinte de la clé de débogage est publique** (mot de passe `android`) : l'enregistrer permettrait à n'importe qui de signer une application au même nom de paquet et d'obtenir des jetons valables pour notre ID client. Jamais dans ce projet.
- **Le mot de passe de la clé de publication ne sort jamais du terminal** de l'utilisateur ; seule la ligne `SHA1:` (publique) peut être copiée dans la console.
- Un « code secret » peut s'afficher à la création du client Web : il ne sert pas (pas de flux par code). Ne pas le copier ni le ranger.
- Origine **exacte** : `https://kantin.eu`, sans barre finale, sans chemin, sans joker ; sinon `origin_mismatch`. Les changements d'origine mettent de 5 minutes à quelques heures à s'appliquer ([aide Google](https://support.google.com/cloud/answer/15549257)).
- En statut « Test », seuls 100 testeurs listés une par une peuvent se connecter ; ne pas laisser le statut « Test ».
- Ne pas ajouter de portée « sensible » : cela déclencherait une revue de Google et un écran d'avertissement.
- Le projet appartient au compte qui l'a créé : le recommander à l'utilisateur avec un second propriétaire (étape 14).
- Un client OAuth sans aucune demande de jeton pendant six mois est supprimé (récupérable environ 30 jours) : à mentionner dans le runbook (AUTH-008).
