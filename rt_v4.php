<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : skydarc (inspired by Stawen Dronek)
* Utilisation commerciale interdite sans mon accord
*/

if (!file_exists('config.php')) {
    header('Location: setup.php');
} else {
    include_once 'config.php';
    include_once '_templates/header.php';
    include_once '_templates/menu.php';
}

?>

    <div class="container theme-showcase" role="main">


		<div class="page-header">
		    <div class="row">
		        <div class="col-md-11 rtTitle"><?php echo session::getInstance()->getLabel('lang.text.page.rt.boilerName'); ?> <?php echo 'http://'.CHAUDIERE; ?></div>
		    </div>
		</div>
        <?php include __DIR__.'/_templates/rt/loading_block.php'; ?>

        <div id="communication" style="display: none;">

            <div class="tab-content">

                 <div role="tabpanel" class="tab-pane active" id="indicateurs">

            		<div class="row">

            		    <div class="col-md-12" ><h2><small><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.indic'); ?></small></h2></div>

<?php
                        $panels = [
                            ['id' => 'pe1.L_modulation',   'key' => 'CAPPL:FA[0].L_modulationsstufe',     'action' => 'refresh_v4', 'savable' => true,  'default' => ''],
                            ['id' => 'pe1.L_state',        'key' => 'CAPPL:FA[0].L_kesselstatus',         'action' => 'refresh_v4', 'savable' => true,  'default' => ''],
                        ];
                        foreach ($panels as $p) {
                            extract($p);
                            include __DIR__.'/_templates/rt/sensor_panel.php';
                        }
                        ?>

						<div class="col-md-12" ><h2></h2></div>

<?php
                        $panels = [
                            ['id' => 'pe1.L_avg_runtime', 'key' => 'CAPPL:FA[0].L_mittlere_laufzeit',         'action' => '', 'savable' => false, 'default' => ''],
                            ['id' => 'pe1.L_starts',      'key' => 'CAPPL:FA[0].L_brennerstarts',             'action' => '', 'savable' => false, 'default' => ''],
                            ['id' => 'pe1.L_runtime',     'key' => 'CAPPL:FA[0].L_brennerlaufzeit_anzeige',   'action' => '', 'savable' => false, 'default' => ''],
                        ];
                        foreach ($panels as $p) {
                            extract($p);
                            include __DIR__.'/_templates/rt/sensor_panel.php';
                        }
                        ?>

                        <div class="col-md-12" ><h2><small><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.tcambiante'); ?></small></h2></div>

<?php
                        $panels = [
                            ['id' => 'hk1.temp_heat',    'key' => 'CAPPL:LOCAL.hk[0].raumtemp_heizen',   'action' => 'change_v4',      'savable' => true, 'default' => ''],
                            ['id' => 'hk1.temp_setback', 'key' => 'CAPPL:LOCAL.hk[0].raumtemp_absenken', 'action' => 'change_v4',      'savable' => true, 'default' => ''],
                            ['id' => 'hk1.mode_auto',    'key' => 'CAPPL:LOCAL.hk[0].betriebsart[1]',    'action' => 'change_list_v4', 'savable' => true, 'default' => ''],
                            ['id' => 'hk1.L_state',      'key' => 'CAPPL:LOCAL.L_hk[0].status',          'action' => 'refresh_v4',     'savable' => true, 'default' => ''],
                        ];
                        foreach ($panels as $p) {
                            extract($p);
                            include __DIR__.'/_templates/rt/sensor_panel.php';
                        }
                        ?>

						<div class="col-md-12" ><h2><small><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.ECS'); ?></small></h2></div>

<?php
                        $panels = [
                            ['id' => 'ww1.L_ontemp_act',  'key' => 'CAPPL:LOCAL.L_ww[0].switch-on_sensor_actual', 'action' => 'refresh_v4',     'savable' => true, 'default' => ''],
                            ['id' => 'ww1.temp_max_set',  'key' => 'CAPPL:LOCAL.ww[0].temp_heizen',               'action' => 'change_v4',      'savable' => true, 'default' => ''],
                            ['id' => 'ww1.temp_min_set',  'key' => 'CAPPL:LOCAL.ww[0].temp_absenken',             'action' => 'change_v4',      'savable' => true, 'default' => ''],
                            ['id' => 'ww1.mode_auto',     'key' => 'CAPPL:LOCAL.ww[0].betriebsart[1]',            'action' => 'change_list_v4', 'savable' => true, 'default' => ''],
                            ['id' => 'ww1.heat_once',     'key' => 'CAPPL:LOCAL.ww[0].einmal_aufbereiten',        'action' => 'change_list_v4', 'savable' => true, 'default' => ''],
                        ];
                        foreach ($panels as $p) {
                            extract($p);
                            include __DIR__.'/_templates/rt/sensor_panel.php';
                        }
                        ?>

                    </div>
                </div>
            </div>
        </div>

        <?php $confirmId = 'btConfirmSensor_v4'; include __DIR__.'/_templates/rt/modal_change.php'; ?>

		<div class="modal fade" id="modal_change_list" tabindex="-1" role="dialog" aria-labelledby="changeValue" aria-hidden="true">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title" id="sensorTitle_list"></h4>
                    </div>
                    <div class="modal-body">
                        <div class="hidden">
                            <input type="text" id="sensorId">
                            <input type="number" id="sensorDivisor">
                            <input type="text" id="sensorUnitText">
                        </div>
                        <br/>
                        <form>
							<select id="listChoise" class="form-control text-center input-lg col-xs-10">
							</select>
                        </form>
                        <br/> <br/>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">
                            <span class="glyphicon glyphicon-remove" aria-hidden="true"></span>
                        </button>
                        <button type="button" id="btConfirmList_v4" class="btn btn-default btn-sm">
                            <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

<?php
include __DIR__.'/_templates/footer.php';
?>
<!--appel des scripts personnels de la page -->
	<script src="js/jquery/jquery-ui-timepicker-addon.js"></script>
	<script src="_langs/<?php echo session::getInstance()->getLang(); ?>.datepicker.js"></script>
	<script src="js/rt_v4.js"></script>
	</body>
</html>
