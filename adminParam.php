<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

    include_once 'config.php';
    include_once '_templates/header.php';
    include_once '_templates/menu.php';
?>



<div class="container theme-showcase" role="main">
<br/>
    <div class="page-header" >
        <h2><?php echo session::getInstance()->getLabel('lang.text.menu.admin.information'); ?></h2>
    </div>

                <form class="form-horizontal">
    				<fieldset>
    				
    				<!-- Form Name -->
    					<legend><?php echo session::getInstance()->getLabel('lang.text.page.admin.boilercomm'); ?></legend>
    					
    					<!-- Select Basic -->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="oko_typeconnect"><?php echo session::getInstance()->getLabel('lang.text.page.admin.boilergetfile'); ?></label>
    					  <div class="col-md-3">
    					    <select id="oko_typeconnect" name="oko_typeconnect" class="form-control">
    					        <option value="0">USB</option>
    			                <option value="1" <?php if (GET_CHAUDIERE_DATA_BY_IP === 1) { echo 'selected=selected'; } ?>>IP</option>
    			                <option value="2" <?php if (GET_CHAUDIERE_DATA_BY_IP === 2) { echo 'selected=selected'; } ?>>IP via Json (firmware v4.00b)</option>
    					    </select>
    					  </div>

    					</div>

                        <!-- Text input-->
                        <div class="form-group" id="form-ip" <?php if (GET_CHAUDIERE_DATA_BY_IP < 1) {
    echo 'style="display: none;"';
} ?>>
                            <label class="col-md-4 control-label" for="oko_ip"><?php echo session::getInstance()->getLabel('lang.text.page.admin.boilerip'); ?></label>  
                            <div class="col-md-3">
                                <input id="oko_ip" name="oko_ip" type="text" class="form-control input-md" placeholder="ex : 192.168.0.20" value="<?php echo CHAUDIERE; ?>">
                                <span class="help-block" id="url_csv"></span> 
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-xs btn-default" id="test_oko_ip">
                                    <span class="glyphicon glyphicon-share" aria-hidden="true"></span><?php echo session::getInstance()->getLabel('lang.text.page.admin.test'); ?>
                                </button>
                            </div>
    					</div>
    				
                        <!-- JSON V4 port -->
                        <div class="form-group" id="form-json-port" <?php if (GET_CHAUDIERE_DATA_BY_IP !== 2) { echo 'style="display: none;"'; } ?>>
                            <label class="col-md-4 control-label" for="oko_json_port"><?php echo session::getInstance()->getLabel('lang.text.page.admin.boilerjsonport'); ?></label>
                            <div class="col-md-3">
                                <input id="oko_json_port" name="oko_json_port" type="text" class="form-control input-md" placeholder="ex : 4444" value="<?php echo PORT_JSON; ?>">
                            </div>
                        </div>

                        <!-- JSON V4 password -->
                        <div class="form-group" id="form-json-pwd" <?php if (GET_CHAUDIERE_DATA_BY_IP !== 2) { echo 'style="display: none;"'; } ?>>
                            <label class="col-md-4 control-label" for="oko_json_pwd"><?php echo session::getInstance()->getLabel('lang.text.page.admin.boilerjsonPWD'); ?></label>
                            <div class="col-md-3">
                                <input id="oko_json_pwd" name="oko_json_pwd" type="text" class="form-control input-md" placeholder="ex : 1234" value="<?php echo PASSWORD_JSON; ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-xs btn-default" id="test_oko_json">
                                    <span class="glyphicon glyphicon-share" aria-hidden="true"></span><?php echo session::getInstance()->getLabel('lang.text.page.admin.test'); ?>
                                </button>
                            </div>
                        </div>

                        <!-- Mailbox configuration -->
                        <div class="form-group" id="form-mail-host" <?php if (GET_CHAUDIERE_DATA_BY_IP !== 2) { echo 'style="display: none;"'; } ?>>
                            <label class="col-md-4 control-label" for="mail_host"><?php echo session::getInstance()->getLabel('lang.text.page.admin.mailhost'); ?></label>
                            <div class="col-md-3">
                                <input id="mail_host" name="mail_host" type="text" class="form-control input-md" placeholder="ex : {imap.exemple.com:993/imap/ssl}" value="<?php echo URL_MAIL; ?>">
                                <span class="help-block"><?php echo session::getInstance()->getLabel('lang.text.page.admin.mailcomm'); ?></span>
                            </div>
                        </div>

                        <div class="form-group" id="form-mail-log" <?php if (GET_CHAUDIERE_DATA_BY_IP !== 2) { echo 'style="display: none;"'; } ?>>
                            <label class="col-md-4 control-label" for="mail_log"><?php echo session::getInstance()->getLabel('lang.text.page.admin.maillog'); ?></label>
                            <div class="col-md-3">
                                <input id="mail_log" name="mail_log" type="text" class="form-control input-md" placeholder="email@exemple.com" value="<?php echo LOGIN_MAIL; ?>">
                            </div>
                        </div>

                        <div class="form-group" id="form-mail-pwd" <?php if (GET_CHAUDIERE_DATA_BY_IP !== 2) { echo 'style="display: none;"'; } ?>>
                            <label class="col-md-4 control-label" for="mail_pwd"><?php echo session::getInstance()->getLabel('lang.text.page.admin.mailpwd'); ?></label>
                            <div class="col-md-3">
                                <input id="mail_pwd" name="mail_pwd" type="password" class="form-control input-md" value="<?php echo LOGIN_MAIL !== '' ? '••••••••' : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-xs btn-default" id="test_mail">
                                    <span class="glyphicon glyphicon-share" aria-hidden="true"></span><?php echo session::getInstance()->getLabel('lang.text.page.admin.test'); ?>
                                </button>
                            </div>
                        </div>

    				</fieldset>

    				<fieldset>
    				    <legend><?php echo session::getInstance()->getLabel('lang.text.page.admin.param'); ?></legend>
					
    					<!-- Text input-->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="param_zone"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.timezone'); ?></label>  
    					  <div class="col-md-3">
    					  	<select id="timezone" class="form-control">
							  <?php
                                $t = DateTimeZone::listIdentifiers(DateTimeZone::EUROPE);
                                $d = date_default_timezone_get();
                                echo '<option>UTC</option>';
                                foreach ($t as $zone) {
                                    if ($d === $zone) {
                                        echo '<option selected=selected>'.$zone.'</option>';
                                    } else {
                                        echo '<option>'.$zone.'</option>';
                                    }
                                }

                                ?>
							</select>
    					
    					  </div>
    					</div>
    					
    					<!-- Text input-->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="param_tcref"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.tcref'); ?></label>  
    					  <div class="col-md-3">
    					  <input id="param_tcref" name="param_tcref" type="text" placeholder="ex : 20" class="form-control input-md" required="" value="<?php echo TC_REF; ?>">
    					  <span class="help-block"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.tcref.desc'); ?></span>  
    					  </div>
    					</div>
    					
    					<!-- Text input-->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="param_poids_pellet"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.pellet'); ?></label>  
    					  <div class="col-md-3">
    					  <input id="param_poids_pellet" name="param_poids_pellet" type="text" placeholder="ex : 150" class="form-control input-md" required=""  value="<?php echo POIDS_PELLET_PAR_MINUTE; ?>">
    					  <span class="help-block"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.pellet.desc'); ?></span>  
    					  </div>
    					</div>
    					
    					<!-- Text input-->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="parap_poids_pellet"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.surface'); ?></label>
    					  <div class="col-md-3">
    					  <input id="surface_maison" name="param_surface" type="text" placeholder="ex : 180" class="form-control input-md" required=""  value="<?php echo SURFACE_HOUSE; ?>">
    					  <span class="help-block"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.surface.desc'); ?></span>
    					  </div>
    					</div>

    					<!-- PCI pellet -->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="pci_pellet">PCI pellet (kWh/kg) :</label>
    					  <div class="col-md-3">
    					    <input id="pci_pellet" name="pci_pellet" type="number" step="0.01" placeholder="ex : 4.90" class="form-control input-md" value="<?php echo PCI_PELLET; ?>">
    					  </div>
    					</div>

    					<!-- Rendement chaudière -->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="rendement_chaudiere">Rendement chaudière (%) :</label>
    					  <div class="col-md-3">
    					    <input id="rendement_chaudiere" name="rendement_chaudiere" type="number" step="0.01" placeholder="ex : 89.50" class="form-control input-md" value="<?php echo RENDEMENT_CHAUDIERE; ?>">
    					  </div>
    					</div>

				    </fieldset>

    				<fieldset>
    				    <legend>Token API</legend>

    				    <div class="form-group">
    				        <label class="col-md-4 control-label">Token HA :</label>
    				        <div class="col-md-3">
    				            <input id="api_token_display" type="text" class="form-control input-md" readonly value="<?php echo defined('TOKEN') ? substr(TOKEN, 0, 12).'...' : ''; ?>">
    				        </div>
    				        <div class="col-md-3">
    				            <button type="button" class="btn btn-xs btn-warning" id="bt_regen_token">
    				                <span class="glyphicon glyphicon-refresh" aria-hidden="true"></span> Régénérer
    				            </button>
    				        </div>
    				    </div>

    				</fieldset>

    				<fieldset>
    				    <legend>Recalcul historique</legend>

    				    <div class="form-group">
    				        <label class="col-md-4 control-label">Recalcul conso kWh + cumuls + prix :</label>
    				        <div class="col-md-3">
    				            <button type="button" class="btn btn-xs btn-default" id="bt_recalc_histo">
    				                <span class="glyphicon glyphicon-cog" id="bt_recalc_histo_icon" aria-hidden="true"></span> Recalculer
    				            </button>
    				        </div>
    				    </div>
    				    <div class="form-group" id="recalc_result" style="display:none;">
    				        <div class="col-md-offset-4 col-md-7">
    				            <div class="alert alert-info" id="recalc_result_content"></div>
    				        </div>
    				    </div>

				    </fieldset>
    			

                    <fieldset>
    				
    				<!-- Form Name -->
    					<legend><?php echo session::getInstance()->getLabel('lang.text.page.admin.silo'); ?></legend>
    					
    					<!-- Select Basic -->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="oko_loadingmode"><?php echo session::getInstance()->getLabel('lang.text.page.admin.loading_mode'); ?></label>
    					  <div class="col-md-3">
    					    <select id="oko_loadingmode" name="oko_loadingmode" class="form-control">
    					        <option value="0"><?php echo session::getInstance()->getLabel('lang.text.page.admin.loading_mode_bags'); ?></option>
    			                <option value="1" <?php if (HAS_SILO) {
                                    echo 'selected=selected';
                                } ?> ><?php echo session::getInstance()->getLabel('lang.text.page.admin.loading_mode_silo'); ?></option>
    					    </select>
    					  </div>
    					 
    					</div>
    					
                        <!-- Text input-->
                        <div class="form-group" id="form-silo-details" <?php if (!HAS_SILO) {
                                    echo 'style="display: none;"';
                                } ?>>
                            <label class="col-md-4 control-label" for="oko_silo_size"><?php echo session::getInstance()->getLabel('lang.text.page.admin.silo_size'); ?></label>  
                            <div class="col-md-3">
                                <input id="oko_silo_size" name="oko_silo_size" type="text" class="form-control input-md" placeholder="ex : 3500" value="<?php echo SILO_SIZE; ?>">
                            </div>
    					</div>
    				
    				    <!-- Text input-->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="oko_ashtray"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.ashtray'); ?></label>  
    					  <div class="col-md-3">
    					  <input id="oko_ashtray" name="oko_ashtray" type="text" placeholder="ex : 1000" class="form-control input-md" required=""  value="<?php echo ASHTRAY; ?>">
    					  <span class="help-block"><?php echo session::getInstance()->getLabel('lang.text.page.admin.param.ashtray.desc'); ?></span>  
    					  </div>
    					</div>
					</fieldset> 
					
					<fieldset>
    				
    				<!-- Form Name -->
    					<legend><?php echo session::getInstance()->getLabel('lang.text.page.admin.language'); ?></legend>
    					
    					<!-- Select Basic -->
    					<div class="form-group">
    					  <label class="col-md-4 control-label" for="oko_language"><?php echo session::getInstance()->getLabel('lang.text.page.admin.language.choice'); ?></label>
    					  <div class="col-md-3">
							<label class="radio-inline"><input id="lang_en" type="radio" value="en" name="oko_language" <?php if ('en' == session::getInstance()->getLang()) {
                                    echo 'checked';
                                } ?>><img src="css/images/en-flag.png"></label>
							<label class="radio-inline"><input id="lang_fr" type="radio" value="fr" name="oko_language"<?php if ('fr' == session::getInstance()->getLang()) {
                                    echo 'checked';
                                } ?>><img src="css/images/fr-flag.png"></label>  
    					  </div>
    					 
    					</div>
    					
    				</fieldset> 
                    
                    
    			</form>
                <div  align="center">
					    <button id="bt_save_infoge" name="bt_save_infoge" class="btn btn-primary" type="button"><?php echo session::getInstance()->getLabel('lang.text.page.admin.save'); ?></button>
				</div>
            </div>
            
            
            


<?php
include __DIR__.'/_templates/footer.php';
?>
    <!--script src="js/jquery.fileupload.js"></script-->
	<script src="js/adminParam.js"></script>
    </body>
</html>
