<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
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
            <div class="col-md-11 rtTitle"><?php echo session::getInstance()->getLabel('lang.text.page.rt.boilerName'); ?> <?php echo 'http://'.CHAUDIERE.':'.PORT_JSON; ?></div>
            <div class="col-md-1 text-right">
                <button type="button" id="bt_refresh_all" class="btn btn-xs btn-default" title="Rafraîchir">
                    <span class="glyphicon glyphicon-refresh" id="bt_refresh_all_icon" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>

    <div id="logginprogress" class="page-header" align="center">
        <p><span class="glyphicon glyphicon-refresh glyphicon-spin"></span>&nbsp;<?php echo session::getInstance()->getLabel('lang.text.page.rt.logginprogress'); ?></p>
    </div>

    <div id="communication" style="display: none;">

        <!-- ══ Indicateurs généraux ══════════════════════════════════ -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <strong><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.indic'); ?></strong>
            </div>
            <div class="panel-body">
                <div class="row">

                    <!-- État chaudière -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.L_state">--</div>
                                        <small id="pe1.L_statetext" class="text-muted">--</small>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.L_state"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].L_kesselstatus'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Modulation -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.L_modulation">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.L_modulation"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].L_modulationsstufe'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Nb démarrages -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.L_starts">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.L_starts"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].L_brennerstarts'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Temps moyen brûleur -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.L_avg_runtime">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.L_avg_runtime"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].L_mittlere_laufzeit'); ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══ Températures chaudière ═══════════════════════════════ -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <strong><?php echo session::getInstance()->getLabel('lang.text.page.rt.paramBruleur'); ?></strong>
            </div>
            <div class="panel-body">
                <div class="row">

                    <!-- T°C chaudière réelle -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.L_temp_kessel">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.L_temp_kessel"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox">T°C chaudière (mesurée)</div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C consigne -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="pe1.P_Kesseltemp_Soll">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.P_Kesseltemp_Soll"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_v4" data-sensor="pe1.P_Kesseltemp_Soll"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].pe_kesseltemperatur_soll'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C coupure -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="pe1.P_Abschalttemp">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.P_Abschalttemp"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_v4" data-sensor="pe1.P_Abschalttemp"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].pe_abschalttemperatur'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Puissance -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="pe1.P_Kesselleistung">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="pe1.P_Kesselleistung"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:FA[0].pe_kesselleistung'); ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══ Chauffage ════════════════════════════════════════════ -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <strong><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.tcambiante'); ?></strong>
            </div>
            <div class="panel-body">
                <div class="row">

                    <!-- Mode circuit chauffage -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="hk1.mode">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="hk1.mode"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_list_v4" data-sensor="hk1.mode"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.hk[0].betriebsart[1]'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C ambiante confort -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="hk1.temp_heat">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="hk1.temp_heat"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_v4" data-sensor="hk1.temp_heat"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.hk[0].raumtemp_heizen'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C ambiante réduit -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="hk1.temp_reduce">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="hk1.temp_reduce"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_v4" data-sensor="hk1.temp_reduce"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.hk[0].raumtemp_absenken'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C départ circuit -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="hk1.L_flowtemp_act">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="hk1.L_flowtemp_act"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox">T°C départ (mesurée)</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══ ECS ══════════════════════════════════════════════════ -->
        <div class="panel panel-default">
            <div class="panel-heading">
                <strong><?php echo session::getInstance()->getLabel('lang.text.page.rt.title.ECS'); ?></strong>
            </div>
            <div class="panel-body">
                <div class="row">

                    <!-- Mode ECS -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="ww1.mode">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="ww1.mode"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_list_v4" data-sensor="ww1.mode"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.ww[0].betriebsart[1]'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C ECS mesurée -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-10 text-center">
                                        <div class="huge" id="ww1.L_temp_ist">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="ww1.L_temp_ist"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.L_ww[0].switch-on_sensor_actual'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- T°C ECS consigne -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="ww1.temp_heat">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="ww1.temp_heat"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_v4" data-sensor="ww1.temp_heat"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.ww[0].temp_heizen'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charge ECS forcée -->
                    <div class="col-lg-3 col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <div class="row">
                                    <div class="col-xs-8 text-center">
                                        <div class="huge" id="ww1.einmal_aufbereiten">--</div>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="refresh_v4" data-sensor="ww1.einmal_aufbereiten"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
                                    </div>
                                    <div class="col-xs-2 text-right">
                                        <a href="javascript:void(0)" class="change_list_v4" data-sensor="ww1.einmal_aufbereiten"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span></a>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-footer">
                                <div class="labelbox"><?php echo session::getInstance()->getLabel('lang.capteur.CAPPL:LOCAL.ww[0].einmal_aufbereiten'); ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div><!-- #communication -->

</div><!-- .container -->

<!-- Modale valeur numérique -->
<div class="modal fade" id="modal_change_v4" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="v4_sensorTitle">--</h4>
            </div>
            <div class="modal-body">
                <div class="hidden">
                    <input type="text" id="v4_sensorId">
                    <input type="number" id="v4_sensorFactor">
                    <input type="text" id="v4_sensorUnit">
                </div>
                <div class="row">
                    <div class="col-xs-6 text-center"><span id="v4_sensorMin"></span></div>
                    <div class="col-xs-6 text-center"><span id="v4_sensorMax"></span></div>
                </div>
                <br/>
                <form>
                    <input type="number" class="form-control text-center input-lg" id="v4_sensorValue" step="0.1">
                </form>
                <br/>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">
                    <span class="glyphicon glyphicon-remove" aria-hidden="true"></span>
                </button>
                <button type="button" id="bt_v4_confirm_change" class="btn btn-default btn-sm">
                    <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modale valeur liste -->
<div class="modal fade" id="modal_change_list_v4" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="v4_listSensorTitle">--</h4>
            </div>
            <div class="modal-body">
                <div class="hidden">
                    <input type="text" id="v4_listSensorId">
                </div>
                <form>
                    <select class="form-control" id="v4_listSensorValue"></select>
                </form>
                <br/>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">
                    <span class="glyphicon glyphicon-remove" aria-hidden="true"></span>
                </button>
                <button type="button" id="bt_v4_confirm_list" class="btn btn-default btn-sm">
                    <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__.'/_templates/footer.php'; ?>
<script src="js/rt_v4.js"></script>
</body>
</html>
