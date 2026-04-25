## Unrealised

## 2.2.2 — 2026-04-25 — Fix régressions PHP 8.4 (rt_v4.php + adminParam.php)

Correction de deux régressions liées à la migration PHP 8.4 et à des bugs latents découverts pendant le refactoring.

**Bug 1 — page `rt_v4.php` bloquée sur le spinner de chargement** :
- Root cause : `utf8_encode()` supprimé en PHP 8.4 → fatal error dans `_include/bin_v4/test_boiler.php` → réponse HTTP 500 → callback `.done()` jamais appelé. En complément, le JS `$.connectBoiler_v4` parsait `jsArray.data` avant de checker `jsArray.response`, levant `JSON.parse(undefined)` quand la chaudière était injoignable.
- Fix `_include/bin_v4/test_boiler.php` : `utf8_encode($raw)` → `mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1')`. Branche erreur retourne désormais du JSON valide (`echo json_encode(...)`) au lieu de `print_r($json)` (qui sortait `Array ( [response] => )`).
- Fix `js/rt_v4.js` : `try/catch` autour de `JSON.parse(jsdata)`, `jsResp` déplacé à l'intérieur du bloc `if (jsArray.response)`, ajout d'un handler `.fail()` qui cache le spinner.

**Bug 2 — bouton « Tester » du bloc « Communication avec votre chaudière » (mode JSON) inopérant** :
- Root cause : `js/adminParam.js` ligne 73 appelait `$.get('_include/bin_v4/get_softVersion.php', { ip: ip, port: port, mdp: mdp })` mais les variables `ip` et `port` n'étaient jamais déclarées → `ReferenceError` → click handler crashait silencieusement.
- Fix `js/adminParam.js` : lecture de `#oko_ip` et `#oko_json_port` depuis le DOM avant l'appel.
- Fix `_include/bin_v4/get_softVersion.php` : réécriture complète. `declare(strict_types=1)`, validation des paramètres GET, suppression des `@fsockopen` / `@fclose`, ajout de `curl_close`, remplacement de `utf8_encode` par `mb_convert_encoding`, branches d'erreur retournent du JSON typé (`missing_params`, `unreachable`, `curl_failed`, `bad_response`).

**Bug 3 — fichier de prod hors versioning** :
- `_include/bin_v4/test_boiler.php` n'était pas tracké par git à cause du pattern `test*` dans `.gitignore` (ligne 53), trop large : il matchait n'importe quel fichier commençant par « test » à n'importe quel niveau de l'arborescence. Tout fix ultérieur sur ce fichier aurait été perdu au prochain déploiement.
- Fix `.gitignore` : `test*` → `/test*` (restreint au top-level uniquement).
- `test_boiler.php` re-ajouté au tracking.

- Fichiers modifiés : `.gitignore`, `_include/bin_v4/test_boiler.php` (nouveau), `_include/bin_v4/get_softVersion.php`, `js/rt_v4.js`, `js/adminParam.js`.

## 2.2.1 — 2026-04-24 — Phase 3 : typage progressif + SQL injections résiduelles

Typage complet des classes `_include/*.class.php` (sauf `AutoUpdate` et `UploadHandler`, traités en Phase 5) et élimination des injections SQL résiduelles hors `administration.class.php`.

- **Typage** : propriétés (`string`, `int`, `float`, `bool`, `?self`, `?mysqli`, `logger`, `array`) et signatures (paramètres + retours) sur toutes les méthodes publiques et privées. Unions modernes PHP 8.1+ (`\mysqli_result|bool`, `string|false`, `mixed`).
- **SQL** : migration vers `prepare()` dans `capteur`, `gstGraphique`, `realTime`, `okofen`, `rendu`. Pour les requêtes à colonnes dynamiques (`col_X`), `realEscapeString` appliqué sur les paramètres date.
- **Nettoyage** : `@fopen` / `@unlink` → vérifications explicites, `_formdata` déclarée en propriété d'`okofen`, casse correcte (`new logger()` au lieu de `new Logger()`).

- Fichiers modifiés : 13 classes `_include/*.class.php`.

## 2.2.0-beta.1 — 2026-04-24 — Phase 2 : sécurité critique

Élimination des vulnérabilités SQL injection / XSS / CSRF dans `administration.class.php`, `setup.php` et templates.

- **SQL** : 15+ requêtes concaténées dans `administration.class.php` migrées vers `prepare()` (`mysqli::prepare` + `bind_param`). Méthode `prepare(string $sql, array $params)` ajoutée à `connectDb`.
- **XSS** : helper `e()` (`htmlspecialchars + ENT_QUOTES + UTF-8`) ajouté dans `_include/helpers.php`, autoload via `autoloader::register()`. Échappements appliqués dans `_templates/header.php` (token de session), `administration.class.php`, `rt.php`, `rt_v4.php`.
- **CSRF** : token de session `md5(uniqid())` → `bin2hex(random_bytes(16))` dans `session.class.php`.
- **Nettoyage** : suppression des `@` de masquage d'erreurs dans `administration.class.php` et `UploadHandler.class.php`.

## 2.2.0-alpha.1 — 2026-04-24 — Phase 1 : socle technique

Mise en place de l'outillage de qualité avant tout refactoring.

- `composer.json` (PHP ^8.4, autoload PSR-4 `Okovision\` sur `_include/`, dev-deps `phpstan/phpstan` + `squizlabs/php_codesniffer`).
- `phpstan.neon` niveau 3 (exclut `AutoUpdate` et `UploadHandler`).
- `.php-cs-fixer.php` (PSR-12).
- `declare(strict_types=1)` ajouté en tête de tous les fichiers `_include/*.class.php`.

## 2.1.2 — 2026-04-23 — Refonte chargement mails IMAP

Refonte complète du sous-système mail pour corriger le bug de non-fonctionnement sur `develop` et éliminer plusieurs défauts structurels.

**Bug corrigé** : le bouton « test » de la boîte mail (adminParam.php) et la page amImpMail.php retournaient un message d'erreur générique pour toute défaillance IMAP, rendant impossible le diagnostic.

**Root cause identifiée grâce à l'instrumentation** : l'extension `ext/imap` a été retirée du core PHP 8.4 (déplacée vers PECL) et n'est pas bundlée sur le paquet DSM PHP 8.4 du Synology (la case « IMAP » du profil PHP-FPM est un vestige UI sans binaire associé). Contournement immédiat : vhost dev repassé en PHP 8.2. Dette à traiter avant PHP 9 : migrer vers une librairie userland (`ddeboer/imap` ou `webklex/php-imap`).

- **Nouveau : `_include/mail.class.php`** — façade centralisée sur l'extension IMAP.
  - `mail::open()`, `mail::close()`, `mail::isAvailable()`, `mail::lastError()`, `mail::allErrors()`
  - `mail::classifyOpenFailure()` — classe l'erreur en `ext_missing` / `auth_failed` / `connection_failed`
  - `mail::listCsvParts()`, `mail::fetchPartBody()`, `mail::decorateName()` — factorisation du parsing d'attachments (était dupliqué 3×)
  - `mail::requireLoggedSession()` — session guard commun
  - `mail::respond()`, `mail::errorResponse()` — réponses JSON structurées uniformes

- **Sécurité** : `test_mail.php`, `download_csv.php`, `delete_mail.php` passent en `require_once config.php` → inaccessibles sans session authentifiée (antérieurement accessibles publiquement).

- **Credentials** : le bouton « Test » mail passe de GET à POST (`$.post`) → mot de passe hors URL (plus dans logs Nginx, header Referer, historique navigateur).

- **UX/Diagnostic** : les 4 endpoints retournent du JSON structuré `{ success, error: { code, message, diagnose } }` à la place d'une chaîne vide ou de `'true'`. Le JS affiche maintenant le message d'erreur spécifique (`lang.error.mail.extMissing`, `.authFailed`, `.connectionFailed`).

- **i18n** : 3 nouveaux labels dans `_langs/fr.text.js` et `_langs/en.text.js` (`error.mail.extMissing`, `.authFailed`, `.connectionFailed`).

- Fichiers modifiés : `_include/mail.class.php` (nouveau), `_include/bin_v4/test_mail.php`, `_include/bin_v4/get_list_mail.php`, `_include/bin_v4/download_csv.php`, `_include/bin_v4/delete_mail.php`, `js/adminParam.js`, `js/amImpMail.js`, `_langs/fr.text.js`, `_langs/en.text.js`, `_include/version.json`.

## 2.1.1 — 2026-04-23 — Correctifs admin

- **Fix — `adminParam.php` : paramètre « Mode de récupération du fichier CSV » non persisté pour l'option « firmware v4.00b » (valeur 2).**
  - Cause : dans `config.php` (et son template `config_sample.php`), la constante `GET_CHAUDIERE_DATA_BY_IP` était définie via `($config['get_data_from_chaudiere']==1)?true:false`, ce qui coerçait la valeur en booléen. La valeur `2` devenait donc `false` au rechargement, cassant toutes les comparaisons `== 2` (option du select non re-sélectionnée, champs JSON port/password masqués, entrée de menu « import mail » absente).
  - Correctif : conservation de la valeur entière brute via `(int)($config['get_data_from_chaudiere'] ?? 0)`. Compatible avec les tests booléens existants (0 falsy, 1/2 truthy) **et** avec les comparaisons strictes `== 1` et `== 2`.
  - Fichiers modifiés : `config.php`, `config_sample.php`.
- **Fix — `js/adminParam.js` : nettoyage d'un bloc mort (lignes 37-41) référençant une variable `val` non définie, provoquant une `ReferenceError` dans le handler `change` de `#oko_typeconnect` (sans impact UI car exécuté après le show/hide).**

## 2.1.0 — 2026-04-22 — Compatibilité PHP 8.4

Mise en conformité avec PHP 8.4 du code rapatrié de `skydarc/okovision_v2` (mai 2022, PHP 7.x).

- Remplacement de `utf8_encode()` (supprimée depuis PHP 8.2, fatale en 8.4) par `mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1')` dans 4 fichiers :
  - `_include/administration.class.php`
  - `_include/bin_v4/set_captor.php`
  - `_include/bin_v4/get_softVersion.php`
  - `_include/bin_v4/get_captor_lim.php`
- Aucun paramètre typé nullable implicite détecté (audit initial infirmé après grep complet).
- Extension IMAP : dépréciée en PHP 8.4 (notice soft uniquement) — confirmée présente et active sur le profil PHP du vhost Synology (DSM, 2026-04-22). Aucune action requise.

Note : PHPUnit non ajouté (code legacy non conçu pour les tests unitaires). Prévu comme pré-requis du chantier Home Assistant.

## 2.0.0 — 2026-04-22 — Migration V4 (rapatriement skydarc/okovision_v2)

Support du firmware OkoFen V4 (API JSON), dashboard temps réel dédié, et import CSV via mail IMAP. Les apports viennent du fork [skydarc/okovision_v2](https://github.com/skydarc/okovision_v2) (mai 2022), rapatriés phase par phase sur ce fork.

- **Phase 0** (`v2.0.0-alpha.0`) : socle config (5 constantes `PORT_JSON`, `PASSWORD_JSON`, `URL_MAIL`, `LOGIN_MAIL`, `PASSWORD_MAIL`) + i18n EN/FR pour V4 et mail.
- **Phase 1** (`v2.0.0-alpha.1`) : cœur V4 — méthodes JSON dans `administration.class.php` (IP hardcodée supprimée, utilisation des constantes) + 8 scripts `_include/bin_v4/*` (test boiler/mail, download CSV, list/delete mail, config capteurs).
- **Phase 2** (`v2.0.0-alpha.2`) : UI admin V4 — formulaires JSON et mail dans `setup.php` / `adminParam.php`, routage V3/V4 dans le menu, page `amImpMail.php` (version provisoire).
- **Phase 3** (`v2.0.0-alpha.3`) : dashboard temps réel V4 (`rt_v4.php`, `js/rt_v4.js`) + classes CSS V4. Note : Highcharts non branché (à intégrer séparément).
- **Phase 4** (`v2.0.0-beta.1`) : docs (README, changelog, about) + crédit skydarc.
- **Phase 5** (`v2.0.0-rc.1`) : cherry-pick de la reconstruction propre de l'import mail (remplace la version provisoire rapatriée en phases 1-2).

Chantiers séparés post-v2.0.0 : compat PHP 8.4, intégration Highcharts, intégration Home Assistant.

## 1.10.0

- Make Okovision International by adding English language (default).
  - For changing Language, go to parameters menu
- This is the last update for the coming months!

  1.9.2

---

- Fix #108 - Error Message rendu.getIndicByDay when you don' have pumpe Hot water

  1.9.1

---

- Fix #107 - Error Message after updating. In this update you still have the error. But not next update
- Fix script install with new data Hot Water

  1.9.0

---

- Add #85 :

  - Display of domestic hot water consumption for the current day, and into summary reports
  - To more data on the previous days, you must go to the menu "Calculation of daily summaries / Calcul des synthèse jouralières" and force recalculation

  1.8.5

---

- Fix #37 : Improved Cron function - Get ALL CSV on boiler if not yet imported

  1.8.4

---

- Okovision V2 Foundation : Cleanning Code
- Fix #95 : Import from CSV file Firmware V3 doesn't work (thank's John47 !)
- Improvement Wiki Documentation

  1.8.3

---

- Refactoring du code pour respecter l'indentation PHP
- Merge Fix84 proposé par bertrandgorge : Include all files with absolute paths
- Merge Fix82 proposé par grouxx : Gestion dans GetIndic de plusieurs circuite + ajout de nouveaux retours
- Merge Fix81 proposé par grouxx : Correction get and set boiler Mode pour choisir le bon circuit

  1.8.2

---

- Version compatible PHP 5.6 et PHP 7.2
- Correction de compatibilité avec Mysql 5.7 et MariaDB 10.3

  1.8.1

---

- Correction anomalie lors du setup pour le choix IP / USB
- Correction déconnexion sous FireFox

  1.8.0

---

- Correction orthographe
- Ajout gestion du stock des pellets (Silo et sac)
- Gestion des evenements de la chaudière (ramonage, vidage du cendrier, entretien)

  1.7.4

---

- Correction perte des noms des capteurs suite maj 1.7.3

  1.7.3

---

- Correction mineur dans initialisation de la base de donnée (bgorge)
- Quand un utilisateur import pour la meme journée le csv en http et CSV, les données ne sont plus en double (bgorge)

  1.7.2

---

- Ajout d'une API permettant à des applications tiers d'interagir avec okovision et la chaudière

  1.7.1

---

- Ajout d'une alerte pour dire de sauvegarder si changement de parametres
- Prise en compte d'installation multi-chaudiére (le calcul de la conso ne sera que pour la chaudière maitre)
- Revision de la methode d'installation -> optimisation de l'espace de stockage necessaire pour les données journalières
- Graphe synthèse saison, ne pas voir le mois en cours

  1.7.0

---

- Correction orthographe / correction synthaxique
- Changement de l'unité ms en ds pour la vis sans fin
- Calendrier lors de la selection de la date sur la page d'accueil
- Afficher les noms des courbes dans l'ordre des courbes
- Ajout page "Temps Réel"
  - Visualisation des parametres de réglage combustion chaudière et régulation
  - Visualisation des graphiques en temps réel
  - Sauvegarde de la configuration de la chaudiere
  - Modification des parametres de la chaudiere via okovision
  - Rechargement de parametres sauvegardées et modification des parametres de la chaudière
  - Visualisation sur les graphes journaliers de la modification des parametres de la chaudière
- Suppression de la matrice sans perte de données de l'historique (mais suppresion des données journalières)

## 1.6.4

- préparation livraison 1.7.0
- correction orthographe / correction synthaxique

  1.6.2

---

- Correction setup (compte admin non present)
- Ajout colonne DJU dans tableau recap
- Maj du texte dans 'A propos'

  1.6.1

---

- Correction upload fichier en erreur
- Correction setup impossible

  1.6.0

---

- Creation d'un espace membre contenant la configuration de l'application (defaut admin/okouser)
- Ajout de page d'erreur
- Creation d'un .htaccess

  1.5.5

---

- Ajout d'une alert growl en page d'index pour un maj disponible

  1.5.4

---

- Correction definitive du probleme de fuseau horaire
- Correction probleme d'encodage lors de la creation de la matrice sur linux
- Mise en place Y axe min dynamique (par defaut 0 ou alors valeur negative)

  1.5.3

---

- ajout d'un parametre dans hightchart pour ne pas appliquer un offset sur le timestamp en fonction du navigateur. Force l'utilisation d'UTC

  1.5.0

---

- Possibilité de recalculer la synthese sur une periode choisie
- Mise à jour de la matrice possible sans perte de données
- Petites retouches ergonomiques
- Refraichir le numero de version après un l'installation d'une maj

  1.4.3

---

- Ajout du choix du fuseau Horaire

  1.4.0

---

- Tableau gr/dju/m2
- Sync zoom graphe + maj indicateur haut de page sur la zone séléctionnée
- Optimisation rendu graphe journalier
- Utilisation du status 4 et maj de la bdd

  1.3.0

---

- #26 - Refonte du modele de la base pour réduire son volume
- #26 - Creation d'un script de migration des données (lien disponible dans la page 'A propos')
- #27,#28,#29 - Réécriture du code impacté par le changement du modele de données
- #30 - correction anomalie sur calcul synthese lancé via Cron
- Optimisation des performances + gestion pool de connexion bdd
- Redécoupage des pages d'administration

  1.2.1

---

- #25 - voir la version courante
- #19 - anomalie html sur la page des historiques
- Amelioration du calcul global journalier
- #21 - import de masse

  1.1.1

---

- #23 - synthse journalière ne fonctionnait plus

  1.1.0

---

- #14 et #11 - EVOL - Externalisation des textes dans un fichier commun (pour gain de perf et internationnalisation si besoin)
- #11 - EVOL - Factorisation des appels Asynchrones - Uniformisation / performance / évolutions futur facilitée
- #2 et #18 - EVOL - Gestion de la position des graphes et de capteurs dans les graphes
- #12 - FIX - Page "A propos" - bouton encore visible après l'installation de la mise à jour
- #6 - FIX - Liste deroulante des saisons preselectionnées sur la periode en cours
- #3 - FIX - Raz du coefficiant de correction dans la boite modale d'association capteur / graphe

  1.0.0

---

1. Creation des graphiques (Front Page)

   - choix du nom du graphique
   - choix des données à mettre dans le graphique

2. Configuration
   - Choisir la T°C de reference
   - Choisir le poids de pellet pour 60 secondes de vis tremi
   - Definir saison de chauffage
   - Chemin http de la chaudiere (Ip ou Nom)
   - Parametrage BDD
   - Association structure fichier CSV de l'installation Okofen avec le nom des colonnes
   - Transfert csv sur serveur distant (Oui / Non)
3. Actions Manuelles

   - Recuperation csv depuis la chaudiere
     - Liste les fichiers presents sur la chaudieres
     - Choisir le fichier a importer (si date fichier different de date du jour alors faire la synthese automatiquement)
   - Import du CSV depuis upload via interface web
   - Faire la synthese journaliere
     - Afficher les jours n'ayant pas de synthese journaliere
     - Choisir un jour precis pour mettre a jour la synthese

4. A propos
   - Mise en place un mecanisme de maj automatique d'okobision en OTA (Over The Air)
   - Afficher les fixto dans chaque version
