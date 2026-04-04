v0.53.1 (2026-04-04)
--------------------
* okofen.class.php : csv2bdd() — correction doublon `col_startCycle` dans le batch INSERT.
  Si le capteur startCycle avait `position_column_csv != -1`, sa colonne DB apparaissait deux
  fois dans la liste (une fois ajoutée explicitement, une fois via le loop), provoquant une
  erreur MySQL "Column specified twice" et un import silencieusement vide.
  Fix : le loop exclut désormais `col_startCycle` via un `continue` ; la valeur calculée
  (front montant statut=4 → 1, sinon NULL) reste la seule source pour cette colonne.
* okofen.class.php : csv2bdd() — le résultat du batch INSERT est maintenant vérifié.
  Avant ce fix, un échec MySQL était ignoré et la fonction retournait toujours `true` en
  loggant "SUCCESS". En cas d'échec, elle retourne désormais `false` et loggue une erreur.
  Cela permet à isDayComplete() de retourner false et aux étapes 2/3 du cron de retenter.
* cron.php (étape 3) : suppression du double appel csv2bdd() après importFileFromTmp().
  importFileFromTmp() → importcsv() → csv2bdd() déjà en interne ; l'appel
  supplémentaire à $oko->csv2bdd() était redondant.

Unrealised
----------

v0.53.0 (2026-04-02)
--------------------
* ha_api.php : nouvel endpoint action=live — retourne le dernier snapshot
  temps-réel stocké en base (dernière ligne de oko_historique_full).
  Données exposées : températures (ext, chaudière, départ, ambiance, FRT, UW),
  combustion (modulation, ventilateur, tirage, vis, pause), circuit (pompe, état),
  pellets (niveau silo, ZWB), boiler_running (bool), boiler_state (code PE1).
  Résolution des colonnes dynamique via capteur::getForImportCsv() → compatible
  toutes installations. Retourne 404 si aucune donnée en base.

v0.52.0 (2026-04-02)
--------------------
* okofen.class.php : csv2bdd() réécrit en batch INSERT — toutes les lignes du CSV
  sont regroupées en une seule requête INSERT IGNORE multi-valeurs au lieu de N requêtes
  individuelles (1440 → 1 requête pour un log0 complet de 24h).
* cron.php : sleep(5) réduit à sleep(2) entre l'étape 1 et l'étape 1b — le rate-limit
  API est 2500ms, 5s était excessif.
* cron.php : évitement du double calcul de synthèse pour la veille — si la synthèse
  a déjà été calculée à l'étape 1, l'étape 2 (cas veille déjà complète) ne la recalcule pas.

v0.51.0 (2026-04-02)
--------------------
* cron.php : ajout de l'étape 1b — snapshot temps réel de la journée courante.
  À chaque appel du cron, l'endpoint /all? de l'API V4 est interrogé et une ligne
  synthétique est insérée dans oko_historique_full pour l'heure courante (résolution 5min).
  Cela alimente les graphiques et indicateurs de la journée en cours sur index.php,
  car log0 n'est écrit qu'une seule fois à minuit par la chaudière.
* okofen.class.php : nouvelle méthode storeLiveSnapshot() — récupère /all?, mappe les
  valeurs JSON sur les colonnes CSV (matrice.csv), écrit une ligne dans _tmp/import.csv,
  avec retry ×3 (4s) et encodage utf8_encode() pour compatibilité ISO-8859-1 du firmware.
* okofen.class.php : fix download() — remplacement fopen par cURL avec retry ×3 et
  vérification du code HTTP (corrige les erreurs 401 du rate-limit de l'API V4).

v0.50.0 (2026-04-01)
--------------------
* cron.php : réécriture complète — import 3 niveaux pour firmware V4 :
  1. Étape 1 (temps réel) : téléchargement log0 (aujourd'hui) à chaque appel du cron via
     l'API V4 JSON (http://CHAUDIERE:PORT/PASSWORD/log0), import et calcul synthèse.
  2. Étape 2 (vérification veille, à partir de 00h01) : si la journée d'hier est incomplète
     en base, téléchargement log1 (hier), import et recalcul synthèse veille.
  3. Étape 3 (fallback mail) : si la veille est toujours incomplète après l'étape 2,
     déclenchement du pipeline mail (get_list_mail → download_csv → importFileFromTmp).
* Fix : suppression de print_r($dataFilename) dans okofen.class.php::getDateFromFilename().
* Fix : suppression de var_dump($nb_mail_last) dans cron2.php.
* Fix : suppression de l'URL hardcodée 192.168.86.28:4321/r18n/log3 dans cron2.php.
* Fix : remplacement de l'IP hardcodée 192.168.1.2:4321/Ob9v dans
  administration.class.php::getFileFromChaudiere() par les constantes CHAUDIERE/PORT_JSON/PASSWORD_JSON.

v0.49.0 (2026-03-30)
--------------------
* API HA monthly : ajout de silo_pellets_restants, silo_niveau,
  cendrier_capacite_restante, cendrier_niveau_de_remplissage pour chaque
  jour dans le tableau days[]. Valeurs calculées historiquement via
  sous-requêtes corrélées (stock à la livraison − conso cumulée jusqu'au jour J).
* API HA : documentation complète de la requête monthly dans api-doc.md.

v0.48.0 (2026-03-29)
--------------------
* API HA daily/today : nouveau champ is_new (true = synthèse présente,
  false = fallback avec cumulatifs d'avant-hier quand la synthèse n'est
  pas encore calculée).
* API HA today : retourne désormais la synthèse d'hier au lieu d'un
  calcul live (l'app ne connaît jamais les données du jour en cours).

v0.47.0 (2026-03-29)
--------------------
* Compatibilité firmware V4 : nouvelle page temps réel (rt_v4.php,
  js/rt_v4.js) et scripts backend (_include/bin_v4/).
* Import CSV par boite mail IMAP (amImpMail.php, js/amImpMail.js, cron2.php).
* Menu : ajout route import mail, restructuration pour firmware V4.
* Langues : nouvelles clés pour l'import mail, l'ECS temps réel,
  les capteurs firmware V4.
* favicon : ajout <link rel="icon"> explicite dans header.php pour
  fonctionner en installation sous-dossier.

v0.46.0 (2026-03-27)
--------------------
* Calcul du prix pellet par logique FIFO (cumul consommé vs cumul livré) :
  le prix d'un lot ne s'applique que lorsque le lot précédent est épuisé.
  Impacte insertSyntheseDay, recalcHistorique et toutes les requêtes de coût.
* Histo : affichage du coût cumulé du mois et de la saison, colonne
  Coût (€) dans le tableau de synthèse.
* API HA : nouveau champ cumul_cout dans les actions today et daily.
* config_sample.php : détection multi-candidats du répertoire app pour
  compatibilité Synology vhost par défaut (open_basedir).

2.00.b
------
* Make Okovision compatible with firmware V4.

1.10.0
------
* Make Okovision International by adding English language (default).
  * For changing Language, go to parameters menu
* This is the last update for the coming months!  

1.9.2
------
* Fix #108 - Error Message rendu.getIndicByDay when you don' have pumpe Hot water


1.9.1
------
* Fix #107 - Error Message after updating. In this update you still have the error. But not next update
* Fix script install with new data Hot Water

1.9.0
-----

* Add #85 : 
  * Display of domestic hot water consumption for the current day, and into summary reports
  * To more data on the previous days, you must go to the menu "Calculation of daily summaries / Calcul des synthèse jouralières" and force recalculation

1.8.5
-----

* Fix #37 : Improved Cron function - Get ALL CSV on boiler if not yet imported

1.8.4
-----

* Okovision V2 Foundation : Cleanning Code
* Fix #95 : Import from CSV file Firmware V3 doesn't work (thank's John47 !)
* Improvement Wiki Documentation

1.8.3
-----

* Refactoring du code pour respecter l'indentation PHP
* Merge Fix84 proposé par bertrandgorge : Include all files with absolute paths
* Merge Fix82 proposé par grouxx : Gestion dans GetIndic de plusieurs circuite + ajout de nouveaux retours
* Merge Fix81 proposé par grouxx : Correction get and set boiler Mode pour choisir le bon circuit

1.8.2
-----
* Version compatible PHP 5.6 et PHP 7.2
* Correction de compatibilité avec Mysql 5.7 et MariaDB 10.3

1.8.1
-----

* Correction anomalie lors du setup pour le choix IP / USB
* Correction déconnexion sous FireFox

1.8.0
-----

* Correction orthographe
* Ajout gestion du stock des pellets (Silo et sac)
* Gestion des evenements de la chaudière (ramonage, vidage du cendrier, entretien)

1.7.4
-----

* Correction perte des noms des capteurs suite maj 1.7.3

1.7.3
-----

* Correction mineur dans initialisation de la base de donnée (bgorge)
* Quand un utilisateur import pour la meme journée le csv en http et CSV, les données ne sont plus en double (bgorge)

1.7.2
-----

* Ajout d'une API permettant à des applications tiers d'interagir avec okovision et la chaudière

1.7.1
-----

* Ajout d'une alerte pour dire de sauvegarder si changement de parametres
* Prise en compte d'installation multi-chaudiére (le calcul de la conso ne sera que pour la chaudière maitre)
* Revision de la methode d'installation -> optimisation de l'espace de stockage necessaire pour les données journalières
* Graphe synthèse saison, ne pas voir le mois en cours

1.7.0
-----

* Correction orthographe / correction synthaxique
* Changement de l'unité ms en ds pour la vis sans fin
* Calendrier lors de la selection de la date sur la page d'accueil
* Afficher les noms des courbes dans l'ordre des courbes
* Ajout page "Temps Réel"
    *  Visualisation des parametres de réglage combustion chaudière et régulation
    *  Visualisation des graphiques en temps réel
    *  Sauvegarde de la configuration de la chaudiere
    *  Modification des parametres de la chaudiere via okovision
    *  Rechargement de parametres sauvegardées et modification des parametres de la chaudière
    *  Visualisation sur les graphes journaliers de la modification des parametres de la chaudière
*  Suppression de la matrice sans perte de données de l'historique (mais suppresion des données journalières)

1.6.4
-----

* préparation livraison 1.7.0
* correction orthographe / correction synthaxique

1.6.2
-----

* Correction setup (compte admin non present)
* Ajout colonne DJU dans tableau recap
* Maj du texte dans 'A propos'

1.6.1
-----

* Correction upload fichier en erreur
* Correction setup impossible

1.6.0
-----

* Creation d'un espace membre contenant la configuration de l'application (defaut admin/okouser)
* Ajout de page d'erreur
* Creation d'un .htaccess

1.5.5
-----

* Ajout d'une alert growl en page d'index pour un maj disponible

1.5.4
-----

* Correction definitive du probleme de fuseau horaire
* Correction probleme d'encodage lors de la creation de la matrice sur linux
* Mise en place Y axe min dynamique (par defaut 0 ou alors valeur negative)


1.5.3
-----
* ajout d'un parametre dans hightchart pour ne pas appliquer un offset sur le timestamp en fonction du navigateur. Force l'utilisation d'UTC

1.5.0
-----
* Possibilité de recalculer la synthese sur une periode choisie
* Mise à jour de la matrice possible sans perte de données
* Petites retouches ergonomiques
* Refraichir le numero de version après un l'installation d'une maj

1.4.3
-----

* Ajout du choix du fuseau Horaire

1.4.0
-----

* Tableau gr/dju/m2
* Sync zoom graphe + maj indicateur haut de page sur la zone séléctionnée
* Optimisation rendu graphe journalier
* Utilisation du status 4 et maj de la bdd

1.3.0
-----

* #26 - Refonte du modele de la base pour réduire son volume
* #26 - Creation d'un script de migration des données (lien disponible dans la page 'A propos')
* #27,#28,#29 - Réécriture du code impacté par le changement du modele de données
* #30 - correction anomalie sur calcul synthese lancé via Cron
* Optimisation des performances + gestion pool de connexion bdd
* Redécoupage des pages d'administration

1.2.1
-----

* #25 - voir la version courante
* #19 - anomalie html sur la page des historiques
* Amelioration du calcul global journalier
* #21 - import de masse

1.1.1
-----

* #23 - synthse journalière ne fonctionnait plus

1.1.0
------

* #14 et #11 - EVOL - Externalisation des textes dans un fichier commun (pour gain de perf et internationnalisation si besoin)
* #11 - EVOL -  Factorisation des appels Asynchrones - Uniformisation / performance / évolutions futur facilitée
* #2 et #18 - EVOL - Gestion de la position des graphes et de capteurs dans les graphes
* #12 - FIX - Page "A propos" - bouton encore visible après l'installation de la mise à jour
* #6 - FIX - Liste deroulante des saisons preselectionnées sur la periode en cours
* #3 - FIX - Raz du coefficiant de correction dans la boite modale d'association capteur / graphe

1.0.0
------

1. Creation des graphiques (Front Page)
	* choix du nom du graphique
	* choix des données à mettre dans le graphique

2. Configuration
	* Choisir la T°C de reference
	* Choisir le poids de pellet pour 60 secondes de vis tremi
	* Definir saison de chauffage
  	* Chemin http de la chaudiere (Ip ou Nom)
	* Parametrage BDD
	* Association structure fichier CSV de l'installation Okofen avec le nom des colonnes
	* Transfert csv sur serveur distant (Oui / Non)
	 
	
3. Actions Manuelles
	* Recuperation csv depuis la chaudiere
		* Liste les fichiers presents sur la chaudieres
		* Choisir le fichier a importer (si date fichier different de date du jour alors faire la synthese automatiquement)
	* Import du CSV depuis upload via interface web
	* Faire la synthese journaliere
		* Afficher les jours n'ayant pas de synthese journaliere
		* Choisir un jour precis pour mettre a jour la synthese

4. A propos
    * Mise en place un mecanisme de maj automatique d'okobision en OTA (Over The Air)
    * Afficher les fixto dans chaque version
