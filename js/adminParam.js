/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

$(document).ready(function() {

	/*
	 * Espace Information general
	 */

	$("#oko_typeconnect").change(function() {
		var val = parseInt($(this).val(), 10);
		if (val >= 1) {
			$("#form-ip").show();
		} else {
			$("#form-ip").hide();
		}
		if (val === 2) {
			$("#form-json-port, #form-json-pwd, #form-mail-host, #form-mail-log, #form-mail-pwd").show();
		} else {
			$("#form-json-port, #form-json-pwd, #form-mail-host, #form-mail-log, #form-mail-pwd").hide();
		}
	});

	$('#test_oko_ip').click(function() {

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
	});

	$('#test_oko_json').click(function() {
		var ip   = $('#oko_ip').val();
		var port = $('#oko_json_port').val();
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

		$.get('_include/bin_v4/test_mail.php', { host: host, log: log, mdp: mdp })
			.done(function(raw) {
				if (raw === 'success') {
					$.growlValidate(lang.valid.communication);
				} else {
					$.growlWarning(lang.error.mailboxDontRespond + ' : ' + raw);
				}
			})
			.fail(function() { $.growlWarning(lang.error.mailboxDontRespond); });
	});

	$('#bt_regen_token').click(function() {
		if (!confirm('Régénérer le token API ? L\'ancien token sera invalidé.')) { return; }

		$.api('POST', 'admin.regenerateToken', {}).done(function(json) {
			if (json.response) {
				$('#api_token_display').val(json.token + '...');
				$.growlValidate('Token régénéré');
			} else {
				$.growlWarning(lang.error.configNotSave);
			}
		});
	});

	$('#bt_recalc_histo').click(function() {
		var $btn  = $(this);
		var $icon = $('#bt_recalc_histo_icon');

		$btn.prop('disabled', true);
		$icon.addClass('glyphicon-spin');

		$.api('POST', 'admin.recalcHistorique', {}).done(function(json) {
			$btn.prop('disabled', false);
			$icon.removeClass('glyphicon-spin');

			if (json.response) {
				var msg = json.rows + ' lignes recalculées — '
					+ json.lots + ' lot(s) — PCI ' + json.pci + ' kWh/kg — rendement ' + json.rendement + '% — '
					+ json.elapsed + 's';
				$('#recalc_result_content').text(msg);
				$('#recalc_result').show();
				$.growlValidate(lang.valid.configSave);
			} else {
				$.growlWarning(lang.error.configNotSave);
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			$icon.removeClass('glyphicon-spin');
			$.growlWarning(lang.error.configNotSave);
		});
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
			param_tcref: $('#param_tcref').val(),
			param_poids_pellet: $('#param_poids_pellet').val(),
			surface_maison: $('#surface_maison').val(),
			oko_typeconnect: $('#oko_typeconnect').val(),
			timezone: $("#timezone").val(),
			send_to_web: 0,
			has_silo: $('#oko_loadingmode').val(),
			silo_size: $('#oko_silo_size').val(),
			ashtray: $('#oko_ashtray').val(),
			lang: $('input[name=oko_language]:checked').val(),
			oko_json_port: $('#oko_json_port').val(),
			oko_json_pwd: $('#oko_json_pwd').val(),
			mail_host: $('#mail_host').val(),
			mail_log: $('#mail_log').val(),
			mail_pwd: $('#mail_pwd').val(),
			pci_pellet: $('#pci_pellet').val(),
			rendement_chaudiere: $('#rendement_chaudiere').val()
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