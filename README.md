# OKOVISION (fork nicouchot/okovision-ha-api)

Interface web de supervision d'une chaudière Okofen — firmware **V3** (HTML/CSV) et **V4** (API JSON).

## Lignée

- **Projet d'origine** : [stawen/okovision](https://github.com/stawen/okovision) (archivé) — auteur : Stawen Dronek. Doc upstream toujours consultable : <https://okovision.dronek.com/documentation/>
- **Fork parallèle** : [skydarc/okovision_v2](https://github.com/skydarc/okovision_v2) — support V4 initial (mai 2022), plus maintenu.
- **Ce fork** : [nicouchot/okovision-ha-api](https://github.com/nicouchot/okovision-ha-api) — rapatrie les apports V4 de skydarc, cible PHP 8.x / MariaDB 10 / Nginx, intégration Home Assistant prévue.

## Stack

- PHP 8.x (testé 8.2 et 8.4 — voir note IMAP ci-dessous)
- MariaDB 10
- Nginx

## Chantiers réalisés

### Compatibilité firmware 4.00b (v2.0.0, inspirée de skydarc)

Rapatriement par phases des apports V4 du fork `skydarc/okovision_v2` : socle config (constantes JSON/mail), cœur V4 dans `administration.class.php` + scripts `_include/bin_v4/*` (API JSON chaudière, import CSV via IMAP), UI admin (formulaires JSON/mail, routage V3/V4, page `amImpMail.php`), dashboard temps réel dédié (`rt_v4.php`). Reconstruction propre de l'import mail en phase finale (RC) pour remplacer la version provisoire rapatriée.

### Compatibilité PHP 8.4 (v2.1.0)

Mise en conformité du code legacy (origine PHP 7.x) avec PHP 8.4. Remplacement de `utf8_encode()` (supprimée depuis 8.2) par `mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1')` dans 4 fichiers (`administration.class.php`, `set_captor.php`, `get_softVersion.php`, `get_captor_lim.php`). Audit nullable-implicites : aucun cas. **Note IMAP** : l'extension `ext/imap` a été retirée du core PHP 8.4 et n'est plus bundlée sur certaines distributions — une migration vers une librairie userland (ex. `ddeboer/imap`, `webklex/php-imap`) sera nécessaire avant PHP 9.

### Refactoring de l'import mail (v2.1.2)

Refonte complète du sous-système mail pour corriger un bug de non-fonctionnement et éliminer plusieurs défauts structurels. Création de `_include/mail.class.php` : façade centralisée sur l'extension IMAP (ouverture, diagnostic, parsing d'attachments factorisé — ex-duplication 3×). Durcissement sécurité : `test_mail.php`, `download_csv.php`, `delete_mail.php` désormais inaccessibles sans session authentifiée (antérieurement publics). Bouton « Test » passe de GET à POST (mot de passe hors URL/logs). Les endpoints retournent un JSON structuré `{ success, error: { code, message, diagnose } }` permettant un diagnostic précis côté UI (extension manquante, auth KO, serveur injoignable), avec labels i18n FR/EN dédiés.

## Licence

Utilisation commerciale interdite sans accord de l'auteur d'origine (Stawen Dronek).
