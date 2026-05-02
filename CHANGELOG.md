## Unrealised

## 2.5.0 — 2026-05-02 — Rapatriement master : kWh, coût pellet, import firmware V4

Apport des fonctions applicatives développées sur le master entre v0.44.2 et v0.54.0, adaptées à l'architecture refactorée v2.x (7 classes Admin, ajax_routes, strict_types). L'API Home Assistant est explicitement hors scope et sera traitée en v2.6 / v3.0.

### Calcul énergétique (Phases 1–3)

- **Schéma BDD** : 6 nouvelles colonnes dans `oko_resume_day` (`conso_kwh`, `cumul_kg`, `cumul_kwh`, `cumul_cycle`, `prix_kg`, `prix_kwh`)
- **Constantes** : `PCI_PELLET` (défaut 4,90 kWh/kg) et `RENDEMENT_CHAUDIERE` (défaut 89,50 %) dans `config_sample.php`, persistées dans `config.json`
- **Setup** : champs PCI/rendement dans le wizard `setup.php`
- **Migration** : `migrate_v2.php` — `ALTER TABLE` idempotent pour installs existantes
- **Backend** : `okofen::insertSyntheseDay()` calcule kWh, cumulatifs et prix FIFO à chaque synthèse journalière
- **AdminParam** : `saveInfoGenerale()` persiste PCI/rendement ; nouvelle méthode `recalcHistorique()` recalcule l'ensemble de l'historique (conso_kwh, cumulatifs, FIFO prix pellet)
- **UI histo** : badges Énergie/Coût (mensuel + saison), série kWh orange sur axe Y dédié, colonnes Énergie (kWh) et Coût (€) dans le tableau récap
- **UI adminParam** : champs PCI/rendement + bouton « Recalculer l'historique »

### Import firmware V4 multi-niveaux + snapshot live (Phase 4)

- **`okofen::storeLiveSnapshot()`** : appel `/all?`, parse JSON firmware V4, construction d'une ligne CSV (50 colonnes) → insertion dans `oko_historique_full` via `csv2bdd()`
- **`cron.php`** : refonte en 3 branches selon `GET_CHAUDIERE_DATA_BY_IP` — V4 (log0 + snapshot live + retry log1 + fallback mail), V3 legacy, USB

### Performance import + détection cycles (Phase 5)

- **`csv2bdd()`** : refonte en batch `INSERT IGNORE` — une seule requête SQL par fichier ; `col_startCycle` forcé à NULL à l'import
- **`recalcStartCycleForDay()`** : recalcule les fronts montants statut→4 depuis la BDD après chaque import, en initialisant `old_status` avec le dernier statut de la veille (élimine les faux démarrages minuit)
- **`makeSyntheseByDay()`** : appelle `recalcStartCycleForDay()` avant `insertSyntheseDay()` pour garantir un `nb_cycle` fiable

Hors scope (reporté en v2.6 / v3.0) : API Home Assistant (`ha_api.php`), historique silo/cendrier par jour, fallback `is_new` daily/today.

## 2.4.0 — 2026-05-01 — Release finale : corrections post refactoring

Stabilisation complète de la v2.4.0 après éclatement d'`administration.class.php` (Phase 5). Cinq release candidates ont corrigé les régressions PHP 8.4 introduites par le refactoring et harmonisé le menu « Actions Manuelles ». Détail ci-dessous.

## 2.4.0-rc.5 — 2026-04-30 — Refonte libellés menu « Actions Manuelles »

Réorganisation et clarification du sous-menu « Actions Manuelles » : la page upload USB est en réalité un upload de fichier CSV (peu importe sa provenance), elle remonte donc en 2e position juste après l'import direct chaudière. Les libellés sont harmonisés pour rendre lisible le périmètre de chaque page (source des données + lieu de stockage intermédiaire).

- **`_templates/menu.php`** : `amImpUsb.php` remonte en 2e position (avant `amImpMail.php`).
- **`_langs/fr.text.json`** :
  - `menu.manual.import.usb` : "Mise à jour des données (import usb)" → "Mise à jour des données (upload fichier)"
  - `menu.manual.import.mail` : "Mise à jour des données (import mail)" → "Chargement des fichiers en masse (via mail)"
  - `menu.manual.import.mass` : "Import en masse" → "Mise à jour en masse (dossier _tmp)"
  - `page.import.title` : "Importation en masse" → "Mise à jour en masse (dossier _tmp)" (titre `<h2>` de `amImportMass.php` aligné sur le menu)
- **`_langs/en.text.json`** : mêmes modifications côté EN (`Data update (file upload)`, `Bulk file download (via mail)`, `Bulk update (_tmp folder)`).

Cohérence titres `<h2>` : les pages `amImpBoiler.php`, `amImpUsb.php`, `amImpMail.php`, `amSynthese.php` réutilisent déjà directement la clé de menu pour leur titre — la mise à jour des libellés suffit. Seul `amImportMass.php` utilise une clé dédiée (`page.import.title`), réalignée explicitement.

Ordre final du sous-menu :
1. Mise à jour des données (depuis chaudière)
2. Mise à jour des données (upload fichier)
3. Chargement des fichiers en masse (via mail)
4. Mise à jour en masse (dossier _tmp)
5. Calcul des synthèses journalières

## 2.4.0-rc.4 — 2026-04-30 — Fix mktime() résiduels cron.php + setup.php (PHP 8.4)

Suite logique de rc.3 : nettoyage des deux dernières occurrences du pattern `mktime(0, 0, 0, date('m'), date('d'), date('Y'))` qui crashaient sous PHP 8.4 (`mktime()` exige désormais des `?int`, `date()` renvoie des `string` → `TypeError`). Bugs latents dans des branches d'exécution rares mais critiques le jour où elles s'activeront.

- **`cron.php:35`** : branche `else` du traitement des fichiers mail — exécutée uniquement quand `_tmp/` est vide (aucun mail à traiter). En cas de crash : synthèse de la veille jamais calculée, échec silencieux du cron. Remplacement par `date('Y-m-d', strtotime('-1 day'))` (équivalent fonctionnel, gère naturellement le 1er du mois).
- **`setup.php:64`** : script d'installation initiale, pré-remplissage de `oko_dateref` (2014-09-01 → 2037-09-01). Crash bloquerait toute nouvelle installation. Ici on décale `$start_day` de `$i` jours → simplification en arithmétique directe sur le timestamp : `$start_day + ($i * 86400)`.

## 2.4.0-rc.3 — 2026-04-30 — Fix amSynthese.php : liste des jours sans synthèse vide (PHP 8.4)

La page `amSynthese.php` n'affichait plus aucun jour importé sans synthèse au chargement (réponse AJAX `{"response":false,"debug":"mktime(): Argument #4 ($month) must be of type ?int, string given"}`).

- **`_include/AdminMatrix.class.php` — `getDayWithoutSynthese()`** : `date('m')` / `date('d')` / `date('Y')` renvoient des `string` ; PHP 8.4 a renforcé la signature `mktime(?int, ?int, ?int, ?int, ?int, ?int)` et lève désormais un `TypeError` au lieu d'une coercition silencieuse. La construction `mktime(0, 0, 0, date('m'), date('d'), date('Y'))` était de toute façon une no-op (équivalente à minuit du jour courant) → simplifiée en `date('Y-m-d')`. La réponse AJAX redevient un JSON valide et le tableau se peuple avec les jours retournés par la requête anti-jointure `oko_historique_full LEFT JOIN oko_resume_day WHERE b.jour IS NULL`.

Contexte : ces jours apparaissent typiquement après un import via `amImportMass.php` (qui ne déclenche pas de synthèse automatique, contrairement à `cron.php` qui enchaîne `csv2bdd()` + `makeSyntheseByDay()` à chaque passe).

## 2.4.0-rc.2 — 2026-04-30 — Fix index.php bloqué + import matrice (PHP 8.4)

Page `index.php` figée sur l'animation de chargement et page `adminMatrix.php` incapable d'importer la matrice de référence : trois familles de régressions liées à la migration PHP 8.4 + `strict_types=1` corrigées.

- **Conflit de visibilité `sendResponse()`** (régression Phase 5 alpha.2) : `connectDb::sendResponse(mixed $data): void` est `protected` mais trois classes filles déclaraient encore `private function sendResponse(string|array $t): void` hérité de la version V3 ; PHP 8.4 lève `Fatal error: Access level to <child>::sendResponse() must be protected (as in class connectDb) or weaker`. Toutes les méthodes des trois classes étaient impactées (loader index.php bloqué, graphes muets).
  - `_include/rendu.class.php` : `private string` → `protected mixed` (LSP), corps inchangé (les callers pré-encodent en JSON).
  - `_include/realTime.class.php` : idem.
  - `_include/gstGraphique.class.php` : suppression de l'override (sémantique identique au parent).

- **Réponses ajax/API corrompues par `display_errors=1`** : sur le vhost dev, les warnings/deprecations PHP sont affichés inline dans le corps HTTP, ce qui casse `JSON.parse` côté jQuery (les `done()` callbacks ne se déclenchent jamais). Pratique OWASP standard : silencer l'affichage sur les dispatchers de réponse.
  - `ajax.php` : `ini_set('display_errors', '0')` en tête de dispatcher (les erreurs restent loguées via `log_errors=1`).
  - `api.php` : idem.

- **Upload de la matrice cassé sur Synology DSM** : `AdminMatrix::uploadCsv()` s'appuyait sur la lib jQuery File Upload (`UploadHandler.class.php`), incompatible PHP 8.4 (création de propriétés dynamiques, `parse_url(null)`, `stripslashes(null)`) et qui appelle `filesize()` sur le tmp upload PHP. Sous DSM, `upload_tmp_dir = /volume1/@tmp/` est hors `open_basedir` → `filesize()` warning → la lib croit le fichier vide ("File is too small") → `move_uploaded_file()` jamais appelé. Réécriture : appel direct de `move_uploaded_file()` (qui contourne `open_basedir` par design pour les uploads HTTP) + réponse JSON formatée comme attendue par le plugin jQuery File Upload (`{"files":[{"name":..., "size":..., "type":..., "url":...}]}`).
  - `_include/AdminMatrix.class.php` :
    - `uploadCsv()` : suppression de la dépendance à `UploadHandler`, gestion directe via `move_uploaded_file()` + `is_uploaded_file()`. Retourne maintenant des codes d'erreur explicites (`Upload PHP error code N`, `Unknown actionFile`, `move_uploaded_file failed`).
    - `initMatriceFromFile()` : rendu idempotent — `TRUNCATE oko_capteur` + `DROP/CREATE oko_historique_full` au début pour éviter `Duplicate column name 'col_N'` quand la matrice était déjà initialisée. Et `rtrim($line, ';')` pour retirer le `;` final de l'entête CSV (remplace l'antipattern `substr($line, 0, strlen($line) - 2)` qui chopait deux caractères en trop).

## 2.4.0-rc.1 — 2026-04-30 — Fix import depuis la chaudière (firmware V4)

Déblocage de la v2.4.0 : trois bugs en chaîne empêchaient l'import via la page `amImpBoiler.php` ; l'erreur initiale "Échec de l'importation" masquait des causes racines distinctes corrigées une à une.

- **`_include/AdminImport.class.php` — `getFileFromChaudiere()`** : refonte. Le firmware V4 expose 4 slots `/log0../log3` en buffer tournant ; un slot vide retourne soit la page d'aide HTTP 200 soit "Wait 2500ms" HTTP 401. L'ancien code supposait que `/log0` contenait toujours du CSV avec une date sur la ligne 2, ce qui provoquait un `TypeError: explode(): Argument #2 must be of type string, null given` sous PHP 8.4 + `strict_types=1`. Désormais on sonde séquentiellement les 4 slots (avec respect du rate-limit ≥ 2500 ms entre requêtes), on parse la date depuis le CSV quand il y en a un, et on liste systématiquement les 4 slots avec mention `(slot vide)` pour ceux qui ne contiennent pas de CSV.
- **`_include/okofen.class.php` — `download()`** : ajout d'un retry x3 espacé de 3 s, identique au fix appliqué sur master au commit `3f54d5a`. Le rate-limit V4 (HTTP 401 si < 2500 ms entre deux requêtes) faisait échouer l'import en single-shot quand l'utilisateur cliquait juste après le chargement de la liste.
- **`_include/okofen.class.php` — `csv2bdd()`** :
  - `while (!feof($file)) { $ligne = fgets(...); strlen($ligne) - 2 }` → `while (($ligne = fgets()) !== false) { rtrim($ligne, "\r\n") }`. La forme `!feof()` ne capture pas un retour `false` de `fgets()` en fin de fichier : sous PHP 8.4, `strlen(false)` lève un `TypeError`.
  - Garde-fou sur `$capteurStatus['position_column_csv']` : quand le capteur `type=status` n'est pas configuré dans `oko_capteur`, la détection de début de cycle est désormais sautée proprement plutôt que d'émettre des warnings à chaque ligne. Avec `display_errors=1` sur le vhost dev, ces warnings se glissaient dans le corps de la réponse HTTP et corrompaient le JSON, provoquant un faux `response=false` côté client malgré un import en base réussi.
- **`_include/version.json`** : 2.1.2 → 2.4.0-rc.1 (rattrapage : le fichier était resté en 2.1.2 alors que le changelog était déjà en 2.4.0-alpha.3).

État de la chaudière de référence : firmware V4.00b, 4 slots `/logN` rotatifs, rate-limit minimum 2500 ms entre requêtes JSON.

## 2.4.0-alpha.3 — 2026-04-27 — Phase 5 — sous-commit 5.3 : éclatement d'administration.class.php

Suppression du dernier monolithe admin : `administration.class.php` (823 LOC) découpé en 5 classes cohésives, toutes `declare(strict_types=1)`, toutes < 200 LOC.

- **Nouveau** `_include/AdminParam.class.php` : `ping()`, `saveInfoGenerale()`.
- **Nouveau** `_include/AdminImport.class.php` : `getFileFromChaudiere()`, `importFileFromChaudiere()`, `getFileFromTmp()`, `importFileFromTmp()`, `importcsv()`.
- **Nouveau** `_include/AdminMatrix.class.php` : `uploadCsv()`, `getHeaderFromOkoCsv()`, `statusMatrice()`, `deleteMatrice()`, `getDayWithoutSynthese()`, `makeSyntheseByDay()`, `initMatriceFromFile()` (privée), `updateMatriceFromFile()` (privée).
- **Nouveau** `_include/AdminSeason.class.php` : `getSaisons()`, `existSaison()`, `setSaison()`, `deleteSaison()`, `updateSaison()`, `getDateSaison()` (privée).
- **Nouveau** `_include/AdminEvent.class.php` : `getEvents()`, `setEvent()`, `updateEvent()`, `deleteEvent()`.
- **Supprimé** `_include/administration.class.php`.
- `_include/ajax_routes.php` : toutes les routes `admin.*` pointent vers les nouvelles classes spécialisées.
- `api.php` : `new administration()` remplacé par instanciations directes des classes métier.
- `simu_upgrade.php` : `administration::getCurrentVersion()` → `AdminUpdate::getCurrentVersion()`.

## 2.4.0-alpha.2 — 2026-04-27 — Phase 5 — sous-commit 5.2 : extraction AdminAuth + AdminUpdate

Extraction des responsabilités authentification et mise à jour hors d'`administration.class.php`.

- **Nouveau** `_include/AdminAuth.class.php` : `login()`, `logout()`, `changePassword()`. Correction du hashage : `sha1($pass)` sans `realEscapeString` (le hash SHA1 étant déjà de l'hex, l'échappement avant hachage était incorrect et produisait des hashes incohérents).
- **Nouveau** `_include/AdminUpdate.class.php` : `getVersion()`, `getCurrentVersion()` (static), `checkUpdate()`, `addOkoStat()`, `makeUpdate()`. Correction d'un bug pré-existant : assignation `=` au lieu de comparaison `===` sur `AutoUpdate::ERROR_SIMULATE`.
- `_include/connectDb.class.php` : ajout de `sendResponse(mixed $data): void` protégée, mutualisée par toutes les sous-classes Admin.
- `_include/ajax_routes.php` : routes `admin.login/logout/changePassword` → `AdminAuth` ; `admin.checkUpdate/makeUpdate/getVersion` → `AdminUpdate`.

## 2.4.0-alpha.1 — 2026-04-27 — Phase 5 — sous-commit 5.1 : routeur ajax + fix régression connectDb

Refonte du dispatcher ajax et correction d'une régression introduite en Phase 3.

- **`ajax.php`** : 340 → 51 LOC. Le `switch` imbriqué est remplacé par un dispatch via table de closures. `set_exception_handler` ajouté pour retourner du JSON en cas d'exception non capturée.
- **Nouveau** `_include/ajax_routes.php` : 63 routes organisées par type (`admin`, `graphique`, `rendu`, `rt`), chacune encapsulée dans une `static function(): void`.
- **Fix régression Phase 3** `connectDb::getInstance()` : `static` → `self` comme type de retour. La LSB (`static`) forçait PHP à s'attendre à une instance de la sous-classe appelante, alors que `new self()` retourne toujours un `connectDb`. Ce bug cassait silencieusement toutes les requêtes SQL des sous-classes depuis la Phase 3.

## 2.3.0-beta.1 — 2026-04-25 — Phase 4 : migration des panels capteurs vers helper

Migration de l'ensemble des panels capteurs Bootstrap des pages `rt.php` et `rt_v4.php` vers le helper `_templates/rt/sensor_panel.php`.

- **rt.php** : 642 → 237 LOC (−63 %), 24 panels migrés (4 indicateurs + 6 tcambiante + 10 waterHT + 4 paramBruleur).
- **rt_v4.php** : 350 → 143 LOC (−59 %), 14 panels migrés (5 indicateurs + 4 tcambiante + 5 ECS).
- Pattern : tableau de configuration (`id`, `key`, `action`, `savable`, `default`) + `foreach` + `include` du helper.
- Le rendu DOM reste fonctionnellement identique (validé par POC v2.3.0-alpha.3).

- Fichiers modifiés : `rt.php`, `rt_v4.php`. Net : −611 lignes.

## 2.3.0-alpha.3 — 2026-04-25 — Phase 4 : helper sensor_panel + POC

Création du helper paramétrable factorisant le HTML des panels capteurs partagé entre `rt.php` et `rt_v4.php`.

- **Nouveau** `_templates/rt/sensor_panel.php` : génère un panel Bootstrap (`col-lg-3 col-md-6` + `panel-primary`) à partir des variables `$id`, `$key`, `$action`, `$savable`, `$default`.
- Actions supportées : `''` (read-only), `change`, `change_v4`, `change_list_v4` (icône crayon), `refresh_v4` (icône refresh).
- POC : 1 panel migré dans chaque page (`FA0_L_mittlere_laufzeit` dans rt.php, `pe1.L_modulation` dans rt_v4.php) pour valider l'équivalence de rendu avant migration en masse.

- Fichiers : `_templates/rt/sensor_panel.php` (nouveau), `rt.php`, `rt_v4.php`.

## 2.3.0-alpha.2 — 2026-04-25 — Phase 4 : extraction modal_change partagée

Factorisation de la modale d'édition d'une valeur de capteur, partagée entre `rt.php` et `rt_v4.php`.

- **Nouveau** `_templates/rt/modal_change.php` : modale paramétrée par `$confirmId` (`btConfirmSensor` ou `btConfirmSensor_v4`).
- Inclusion via `<?php $confirmId = '...'; include __DIR__.'/_templates/rt/modal_change.php'; ?>`.

- Fichiers modifiés : `rt.php`, `rt_v4.php` (−6 LOC chacun).

## 2.3.0-alpha.1 — 2026-04-25 — Phase 4 : extraction loading_block partagé

Première étape de déduplication entre `rt.php` et `rt_v4.php` : extraction du bloc commun « spinner de chargement + bandeau alerte sauvegarde ».

- **Nouveau** `_templates/rt/loading_block.php` : `#logginprogress` + `#mustSaving`.
- Inclusion via `<?php include __DIR__.'/_templates/rt/loading_block.php'; ?>`.

- Fichiers modifiés : `rt.php`, `rt_v4.php` (−6 LOC chacun).

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
