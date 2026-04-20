<?php

    /**
     * Projet : Okovision - Supervision chaudiere OeKofen
     * Auteur : Stawen Dronek
     * Utilisation commerciale interdite sans mon accord.
     */
    function is_ajax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'xmlhttprequest' == strtolower($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    function testBddConnection($s)
    {
        mysqli_report(MYSQLI_REPORT_STRICT);

        $r = true;

        try {
            $db = new mysqli($s['db_adress'], $s['db_user'], $s['db_password']);
        } catch (Exception $e) {
            $r = false;
        }
        $t['response'] = $r;
        header('Content-type: text/json');
        echo json_encode($t, JSON_NUMERIC_CHECK);

        exit(23);
    }

    function makeInstallation($s)
    {
        if ($s['createDb']) {
            // create BDD
            $mysqli = new mysqli($s['db_adress'], $s['db_user'], $s['db_password']);

            // check connection
            if ($mysqli->connect_errno) {
                printf("Connect failed: %s\n", $mysqli->connect_error);
                exit(24);
            }

            $q = 'CREATE DATABASE IF NOT EXISTS `'.$s['db_schema'].'` /*!40100 DEFAULT CHARACTER SET utf8 */;';
            if (!$mysqli->query($q)) {
                echo 'Création BDD impossible';
                exit;
            }
            $mysqli->close();
        }

        $mysqli = new mysqli($s['db_adress'], $s['db_user'], $s['db_password'], $s['db_schema']);

        // execute multi query
        $mysqli->multi_query(file_get_contents('install/install.sql'));
        while ($mysqli->next_result()) {
        } // flush multi_queries

        // init de la table des dates de reference
        $start_day = mktime(0, 0, 0, 9, 1, 2014); //1er septembre 2014
        $stop_day = mktime(0, 0, 0, 9, 1, 2037); //justqu'au 1er septembre 2037, on verra en 2037 si j'utilise encore l'app.
        $nb_day = ($stop_day - $start_day) / 86400;
        $query = 'INSERT INTO oko_dateref (jour) VALUES ';
        for ($i = 0; $i <= $nb_day; ++$i) {
            $day = date('Y-m-d', mktime(0, 0, 0, date('m', $start_day), date('d', $start_day) + $i, date('Y', $start_day)));
            $query .= "('".$day."'),";
        }

        $query = substr($query, 0, strlen($query) - 1).';';

        $mysqli->query($query);

        $mysqli->close();

        // Make Config.php
        $configFile = file_get_contents('config_sample.php');

        $configFile = str_replace('###_BDD_IP_###', $s['db_adress'], $configFile);
        $configFile = str_replace('###_BDD_USER_###', $s['db_user'], $configFile);
        $configFile = str_replace('###_BDD_PASS_###', $s['db_password'], $configFile);
        $configFile = str_replace('###_BDD_SCHEMA_###', $s['db_schema'], $configFile);

        $configFile = str_replace('###_CONTEXT_###', getcwd(), $configFile);

        $configFile = str_replace('###_TOKEN_###', sha1(rand()), $configFile);
        //$configFile = str_replace("###_TOKEN-API_###",sha1(rand()),$configFile);

        file_put_contents('config.php', $configFile);

        // Make config.json
        $param = [
            'chaudiere'              => $s['oko_ip']               ?? '',
            'tc_ref'                 => $s['param_tcref']          ?? '20',
            'poids_pellet'           => $s['param_poids_pellet']   ?? '150',
            'surface_maison'         => $s['surface_maison']       ?? '180',
            'get_data_from_chaudiere'=> $s['oko_typeconnect']      ?? '0',
            'send_to_web'            => '0',
            'has_silo'               => '0',
            'lang'                   => 'en',
            'port_json'              => $s['oko_json_port']        ?? '',
            'password_json'          => $s['oko_json_pwd']         ?? '',
            'url_mail'               => $s['mail_host']            ?? '',
            'login_mail'             => $s['mail_log']             ?? '',
            'password_mail'          => $s['mail_pwd']             ?? '',
            'pci_pellet'             => $s['pci_pellet']           ?? '4.90',
            'rendement_chaudiere'    => $s['rendement_chaudiere']  ?? '89.50',
        ];

        file_put_contents('config.json', json_encode($param));
    }

    if (is_ajax()) {
        if (isset($_GET['type'])) {
            switch ($_GET['type']) {
                case 'connect':
                    testBddConnection($_POST);

                    break;
                case 'install':
                    makeInstallation($_POST);

                    break;
            }
        }
    }

?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>OkoVision</title>
    
	<!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap theme -->
    <link href="css/bootstrap-theme.min.css" rel="stylesheet">
    <link href="css/jquery-ui.min.css" rel="stylesheet">
    <link href="css/custom.css" rel="stylesheet">
    <link href="css/animate.css" rel="stylesheet">
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>+
    <![endif]-->
	<?php //include_once("analyticstracking.php");?>
	
	</head>

  <body role="document">
  	
<div class="container theme-showcase" role="main">
		<div class="page-header" align="center">
			<h2>Okovision installation</h2> <br>
		</div>
		<div>
			<h3><small>You can modify this information, after installation, through the settings screen</small></h3>
		</div>
		
		
			<fieldset>
				<form class="form-horizontal" id="formConnect">
				<!-- Form Name -->
					<legend>Database Connection</legend>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="db_adress">Address (*) :</label>  
					  <div class="col-md-3">
					  <input id="db_adress" name="db_adress" type="text" placeholder="ex : localhost, 192.168.xxx.xxx" class="form-control input-md" required="">
					  <span class="help-block"></span>  
					  </div>
					</div>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="db_adress">Name (*) :</label>  
					  <div class="col-md-3">
					  <input id="db_schema" name="db_schema" type="text" placeholder="ex : okovision" class="form-control input-md" required="">
					  <span class="help-block"></span>  
					  </div>
					</div>
					<div class="form-group">
						<label class="col-md-4 control-label" for="createDb">Create database :</label>  
					  	<div class="col-md-3 checkbox">
					    <label>
					      <input id="createDb" type="checkbox"> don't checked if database already exist
					    </label>
					
					  </div>
					</div>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="db_user">User (*) :</label>  
					  <div class="col-md-3">
					  <input id="db_user" name="db_user" type="text" placeholder="ex: root" class="form-control input-md" required="">
					  </div>
					</div>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="db_password">Password (*) :</label>  
					  <div class="col-md-3">
					  <input id="db_password" name="db_password" type="text" placeholder="ex : toor" class="form-control input-md" required="">
					  </div>
					</div>
					
					<!-- Button -->
					<label class="col-md-4 control-label"  for="bt_testConnection">Connection test :</label>
					  <div class="col-md-3">
					    <button id="bt_testConnection" name="bt_testConnection" class="btn btn-primary" type="button">Test</button>
					  </div>
					</form>
			</fieldset>
			

			<form class="form-horizontal">
				<fieldset>
				
				<!-- Form Name -->
					<legend>Boiler Communication</legend>
					
					<!-- Select Basic -->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="oko_typeconnect">CSV file grab mode :</label>
					  <div class="col-md-3">
					    <select id="oko_typeconnect" name="oko_typeconnect" class="form-control">
					        <option value="0">USB</option>
			                <option value="1">IP</option>
			                <option value="2">IP via Json (firmware v4.00b)</option>
					    </select>
					  </div>
					</div>

					<!-- Text input-->
					<div class="form-group" id="form-ip" style="display: none;">
					  <label class="col-md-4 control-label" for="oko_ip">Boiler IP address :</label>
					  <div class="col-md-3">
					    <input id="oko_ip" name="oko_ip" type="text" placeholder="ex : 192.168.0.xx" class="form-control input-md">
					  </div>
					</div>

					<!-- JSON V4 port -->
					<div class="form-group" id="form-json-port" style="display: none;">
					  <label class="col-md-4 control-label" for="oko_json_port">JSON port :</label>
					  <div class="col-md-3">
					    <input id="oko_json_port" name="oko_json_port" type="text" placeholder="ex : 4444" class="form-control input-md">
					  </div>
					</div>

					<!-- JSON V4 password -->
					<div class="form-group" id="form-json-pwd" style="display: none;">
					  <label class="col-md-4 control-label" for="oko_json_pwd">JSON password :</label>
					  <div class="col-md-3">
					    <input id="oko_json_pwd" name="oko_json_pwd" type="text" placeholder="ex : 1234" class="form-control input-md">
					  </div>
					</div>

					<!-- Mailbox host -->
					<div class="form-group" id="form-mail-host" style="display: none;">
					  <label class="col-md-4 control-label" for="mail_host">IMAP host :</label>
					  <div class="col-md-3">
					    <input id="mail_host" name="mail_host" type="text" placeholder="ex : {imap.exemple.com:993/imap/ssl}" class="form-control input-md">
					  </div>
					</div>

					<!-- Mailbox login -->
					<div class="form-group" id="form-mail-log" style="display: none;">
					  <label class="col-md-4 control-label" for="mail_log">IMAP login :</label>
					  <div class="col-md-3">
					    <input id="mail_log" name="mail_log" type="text" placeholder="email@exemple.com" class="form-control input-md">
					  </div>
					</div>

					<!-- Mailbox password -->
					<div class="form-group" id="form-mail-pwd" style="display: none;">
					  <label class="col-md-4 control-label" for="mail_pwd">IMAP password :</label>
					  <div class="col-md-3">
					    <input id="mail_pwd" name="mail_pwd" type="password" class="form-control input-md">
					  </div>
					</div>

				</fieldset>
			</form>

			<form class="form-horizontal">
				<fieldset>
				
				<!-- Form Name -->
					<legend>Application settings</legend>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="param_tcref">Reference °C :</label>  
					  <div class="col-md-3">
					  <input id="param_tcref" name="param_tcref" type="text" placeholder="ex : 20" class="form-control input-md" required="" value="20">
					  <span class="help-block">If you have 2 setpoints, reduced to 19°C and comfort at 21°C, you average -&gt; 20°C. It's for DJU calculation</span>  
					  </div>
					</div>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="param_poids_pellet">Pellet weight for 60 seconds of work : </label>  
					  <div class="col-md-3">
					  <input id="param_poids_pellet" name="param_poids_pellet" type="text" placeholder="ex : 150" class="form-control input-md" required=""  value="150">
					  <span class="help-block">Pellet weight in grams measured by operating the furnace feed screw for 60 seconds</span>  
					  </div>
					</div>
					
					<!-- Text input-->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="parap_poids_pellet">House surface : </label>
					  <div class="col-md-3">
					  <input id="surface_maison" name="param_surface" type="text" placeholder="ex : 180" class="form-control input-md" required=""  value="180">
					  <span class="help-block">in m²</span>
					  </div>
					</div>

					<!-- PCI pellet -->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="pci_pellet">Pellet PCI (kWh/kg) :</label>
					  <div class="col-md-3">
					    <input id="pci_pellet" name="pci_pellet" type="number" step="0.01" placeholder="ex : 4.90" class="form-control input-md" value="4.90">
					    <span class="help-block">Lower heating value of pellets in kWh/kg</span>
					  </div>
					</div>

					<!-- Rendement chaudière -->
					<div class="form-group">
					  <label class="col-md-4 control-label" for="rendement_chaudiere">Boiler efficiency (%) :</label>
					  <div class="col-md-3">
					    <input id="rendement_chaudiere" name="rendement_chaudiere" type="number" step="0.01" placeholder="ex : 89.50" class="form-control input-md" value="89.50">
					    <span class="help-block">Boiler combustion efficiency in %</span>
					  </div>
					</div>

				</fieldset>
			</form>
            
            	<!-- Button -->
					
					  <div class="col-md-12" align="center">
					    <button id="bt_install" name="bt_install" class="btn btn-primary" type="button">Install</button>
					  </div>

	 </div> <!-- /container -->
	
	 <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
  <script src="js/jquery/jquery.min.js"></script>
	<script src="js/jquery/jquery-ui.min.js"></script>
	<script src="js/bootstrap/bootstrap.min.js"></script>
	<script src="js/bootstrap/bootstrap-notify.min.js"></script>
	<script src="js/highstock/highstock.js"></script>
	
	<script src="_langs/fr.text.js"></script>
	<script src="js/custom.js"></script>
<!--appel des scripts personnels de la page -->
	<script src="js/setup.js"></script>
	<script>
	$(document).ready(function() {
		$('#oko_typeconnect').change(function() {
			var val = parseInt($(this).val(), 10);
			if (val >= 1) { $('#form-ip').show(); } else { $('#form-ip').hide(); }
			if (val === 2) {
				$('#form-json-port, #form-json-pwd, #form-mail-host, #form-mail-log, #form-mail-pwd').show();
			} else {
				$('#form-json-port, #form-json-pwd, #form-mail-host, #form-mail-log, #form-mail-pwd').hide();
			}
		});
	});
	</script>
    </body>
</html>