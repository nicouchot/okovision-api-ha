/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek mod by skydarc for V2
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

$(document).ready(function() {

	/*
	 * Espace Information general
	 */

	$("#oko_typeconnect").change(function() {
		
		if ($(this).val() == 1) {
			$("#form-ip").show();
			$("#div_test_oko_ip").show();
			$("#form-json-port").hide();
			$("#form-json-pwd").hide();
			$("#form-mail").hide();
		}
		else if ($(this).val() == 2) {
			$("#form-ip").show();
			$("#div_test_oko_ip").hide();
			$("#form-json-port").show();
			$("#form-json-pwd").show();
			$("#form-mail").show();
		}
		else {
			$("#form-ip").hide();
			$("#div_test_oko_ip").hide();
			$("#form-json-port").hide();
			$("#form-json-pwd").hide();
			$("#form-mail").hide();
		}
	});

	$('#test_oko_ip').click(function() {


		//if(/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test($('#oko_ip').val())){

		var ip = $('#oko_ip').val();

		$.api('GET', 'admin.testIp', {
			ip: ip
		}).done(function(json) {
			
			if (json.response) {
				
				$('#url_csv').html("");
				$.growlValidate(lang.valid.communication);
				$('#url_csv').append('<a target="_blank" href="' + json.url + '">' + lang.text.seeFileOnboiler + '</a>');
			}
			else {
				$.growlWarning(lang.error.ipNotPing);
			}
		});

		/*    
		}else{
		    $.growlErreur('Adresse Ip Invalide !');
		}
		*/
	});
	
	$('#test_oko_json').click(function() {


		var mdp  = $('#oko_json_pwd').val();

		$.get('_include/bin_v4/get_softVersion.php', { ip: ip, port: port, mdp: mdp })
			.done(function(raw) {
				var json;
				try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch(e) { $.growlWarning(lang.error.portNotRespond); return; }

				if (!json || !json.version) { $.growlWarning(lang.error.portNotRespond); return; }

				var ver = parseFloat(json.version);
				if (isNaN(ver)) { $.growlWarning(lang.error.portNotRespond); return; }
				if (ver < 4) { $.growlWarning(lang.error.tooldfirmware); return; }

				$.growlValidate(lang.valid.communication + ' — firmware ' + json.version);
			})
			.fail(function() { $.growlWarning(lang.error.portNotRespond); });
	});

	$('#test_mail').click(function() {
		var host = $('#mail_host').val();
		var log  = $('#mail_log').val();
		var mdp  = $('#mail_pwd').val();

		$.post('_include/bin_v4/test_mail.php', { host: host, login: log, mdp: mdp })
			.done(function(json) {
				if (json && json.success) {
					$.growlValidate(lang.valid.communication);
					return;
				}
				var msg = lang.error.mailboxDontRespond;
				if (json && json.error) {
					var code = json.error.code;
					if (code === 'ext_missing')        msg = lang.error.mail.extMissing;
					else if (code === 'auth_failed')   msg = lang.error.mail.authFailed;
					else if (code === 'connection_failed') msg = lang.error.mail.connectionFailed;
					else if (json.error.message)       msg = json.error.message;
				}
				$.growlWarning(msg);
			})
			.fail(function() { $.growlWarning(lang.error.mailboxDontRespond); });
	});

	$("#oko_loadingmode").change(function() {

		if ($(this).val() == 1) {
			$("#form-silo-details").show();
		}
		else {
			$("#form-silo-details").hide();
		}
	});
        
	$('#bt_save_infoge').click(function() {

		var tab = {
			oko_ip: $('#oko_ip').val(),
			oko_json_port: $('#oko_json_port').val(),
			oko_json_pwd: $('#oko_json_pwd').val(),
			mail_host: $('#mail_host').val(),
			mail_log: $('#mail_log').val(),
			mail_pwd: $('#mail_pwd').val(),
			param_tcref: $('#param_tcref').val(),
			param_poids_pellet: $('#param_poids_pellet').val(),
			surface_maison: $('#surface_maison').val(),
			oko_typeconnect: $('#oko_typeconnect').val(),
			timezone: $("#timezone").val(),
			send_to_web: 0,
            has_silo: $('#oko_loadingmode').val(),
            silo_size: $('#oko_silo_size').val(),
			ashtray : $('#oko_ashtray').val(),
			lang : $('input[name=oko_language]:checked').val()
		};
		
		$.api('POST', 'admin.saveInfoGe', tab, false).done(function(json) {
			//console.log(a);
			if (json.response) {
				$.growlValidate(lang.valid.configSave);
				setTimeout(function() {
					document.location.reload();
				  }, 1000);
				
			}
			else {
				$.growlWarning(lang.error.configNotSave);
			}
		});

	});

	


});