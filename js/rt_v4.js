/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

// Cache des données brutes reçues de la chaudière (key1 → key2 → objet)
var boilerData = {};

/**
 * Convertit un entier en chaîne binaire (pad 8 bits).
 */
function dec2bin(dec) {
    return (dec >>> 0).toString(2).padStart(8, '0');
}

/**
 * Décode L_state (binaire) → texte des modes actifs (conf/red/vac/off).
 */
function decodeState(val) {
    var bin = dec2bin(val);
    var modes = [];
    // bits : 7=conf 6=red 5=vac 4=off (ordre MSB→LSB pour 8 bits)
    if (bin[4] === '1') { modes.push(lang.text.mode.off); }
    if (bin[5] === '1') { modes.push(lang.text.mode.vac); }
    if (bin[6] === '1') { modes.push(lang.text.mode.red); }
    if (bin[7] === '1') { modes.push(lang.text.mode.conf); }
    return modes.length > 0 ? modes.join(' / ') : val;
}

/**
 * Construit les options d'un select depuis un format "0:Off|1:Comfort|2:Reduced".
 */
function buildOptions(format, currentVal) {
    var parts = format.split('|');
    var html = '';
    parts.forEach(function(part) {
        var kv = part.split(':');
        if (kv.length < 2) { return; }
        var k   = kv[0];
        var lbl = kv.slice(1).join(':');
        var sel = (parseInt(k, 10) === parseInt(currentVal, 10)) ? ' selected' : '';
        html += '<option value="' + k + '"' + sel + '>' + lbl + '</option>';
    });
    return html;
}

/**
 * Met à jour un élément HTML identifié par id (peut contenir un point).
 */
function updateEl(id, text) {
    var el = document.getElementById(id);
    if (el) { el.textContent = text; }
}

/**
 * Formate une valeur brute selon factor et unit.
 * @param  {number} val
 * @param  {number} factor  diviseur (ex: 10 → val/10)
 * @param  {string} unit
 * @return {string}
 */
function formatVal(val, factor, unit) {
    var v = (factor && factor !== 1) ? val / factor : val;
    var s = (factor && factor !== 1) ? v.toFixed(1) : String(v);
    return unit ? s + ' ' + unit : s;
}

/**
 * Charge toutes les données depuis test_boiler.php et met à jour l'affichage.
 */
function connectBoiler_v4() {
    $('#bt_refresh_all_icon').addClass('glyphicon-spin');

    $.get('_include/bin_v4/test_boiler.php')
        .done(function(raw) {
            var json;
            try {
                json = (typeof raw === 'string') ? JSON.parse(raw) : raw;
            } catch (e) {
                $.growlWarning(lang.error.portNotRespond);
                $('#logginprogress').hide();
                $('#bt_refresh_all_icon').removeClass('glyphicon-spin');
                return;
            }

            if (!json || typeof json !== 'object') {
                $.growlWarning(lang.error.portNotRespond);
                $('#logginprogress').hide();
                $('#bt_refresh_all_icon').removeClass('glyphicon-spin');
                return;
            }

            boilerData = json;

            // Boucle sur key1 (pe1, hk1, ww1, system…) → key2 (capteur)
            $.each(json, function(key1, sensors) {
                if (typeof sensors !== 'object') { return; }
                $.each(sensors, function(key2, obj) {
                    if (typeof obj !== 'object' || !obj.hasOwnProperty('val')) { return; }
                    var id     = key1 + '.' + key2;
                    var val    = obj.val;
                    var unit   = obj.unit   || '';
                    var factor = obj.factor || 1;
                    var format = obj.format || null;

                    var display;
                    if (format) {
                        // Valeur énumérée
                        var parts  = format.split('|');
                        var found  = false;
                        parts.forEach(function(part) {
                            var kv = part.split(':');
                            if (parseInt(kv[0], 10) === parseInt(val, 10)) {
                                display = kv.slice(1).join(':');
                                found = true;
                            }
                        });
                        if (!found) { display = String(val); }
                    } else if (key2 === 'L_state') {
                        display = decodeState(val);
                    } else {
                        display = formatVal(val, factor, unit);
                    }

                    updateEl(id, display);
                });
            });

            $('#logginprogress').hide();
            $('#communication').show();
            $('#bt_refresh_all_icon').removeClass('glyphicon-spin');
        })
        .fail(function() {
            $.growlWarning(lang.error.portNotRespond);
            $('#logginprogress').hide();
            $('#bt_refresh_all_icon').removeClass('glyphicon-spin');
        });
}

/**
 * Recharge un seul capteur via get_captor_lim.php.
 */
function refreshSensor(sensorId) {
    var parts  = sensorId.split('.');
    var system = parts[0];
    var captor = parts[1];

    $.get('_include/bin_v4/get_captor_lim.php', { id: sensorId })
        .done(function(raw) {
            var json;
            try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch (e) { return; }
            if (!json || !json.hasOwnProperty('val')) { return; }

            // Mettre à jour le cache
            if (!boilerData[system]) { boilerData[system] = {}; }
            boilerData[system][captor] = json;

            var val    = json.val;
            var unit   = json.unit   || '';
            var factor = json.factor || 1;
            var format = json.format || null;

            var display;
            if (format) {
                var parts2 = format.split('|');
                var found  = false;
                parts2.forEach(function(p) {
                    var kv = p.split(':');
                    if (parseInt(kv[0], 10) === parseInt(val, 10)) {
                        display = kv.slice(1).join(':');
                        found = true;
                    }
                });
                if (!found) { display = String(val); }
            } else if (captor === 'L_state') {
                display = decodeState(val);
            } else {
                display = formatVal(val, factor, unit);
            }
            updateEl(sensorId, display);
        });
}

// ─── Document ready ──────────────────────────────────────────────────────────
$(document).ready(function() {

    // Chargement initial
    connectBoiler_v4();

    // Rafraîchissement global
    $('#bt_refresh_all').click(function() {
        connectBoiler_v4();
    });

    // Rafraîchissement d'un seul capteur
    $(document).on('click', '.refresh_v4', function() {
        var sensorId = $(this).data('sensor');
        refreshSensor(sensorId);
    });

    // ── Bouton change_v4 (valeur numérique) ──────────────────────────────
    $(document).on('click', '.change_v4', function() {
        var sensorId = $(this).data('sensor');
        var parts    = sensorId.split('.');
        var system   = parts[0];
        var captor   = parts[1];

        $.get('_include/bin_v4/get_captor_lim.php', { id: sensorId })
            .done(function(raw) {
                var json;
                try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch (e) { return; }
                if (!json) { return; }

                var factor = json.factor || 1;
                var unit   = json.unit   || '';
                var val    = json.val    !== undefined ? json.val / factor : '';
                var min    = json.min    !== undefined ? json.min / factor : '';
                var max    = json.max    !== undefined ? json.max / factor : '';

                $('#v4_sensorId').val(sensorId);
                $('#v4_sensorFactor').val(factor);
                $('#v4_sensorUnit').val(unit);
                $('#v4_sensorTitle').text(sensorId);
                $('#v4_sensorMin').text(min !== '' ? 'Min : ' + min + ' ' + unit : '');
                $('#v4_sensorMax').text(max !== '' ? 'Max : ' + max + ' ' + unit : '');
                $('#v4_sensorValue').val(val);
                $('#modal_change_v4').modal('show');
            });
    });

    // Confirmation changement numérique
    $('#bt_v4_confirm_change').click(function() {
        var sensorId = $('#v4_sensorId').val();
        var factor   = parseFloat($('#v4_sensorFactor').val()) || 1;
        var rawVal   = Math.round(parseFloat($('#v4_sensorValue').val()) * factor);

        $.get('_include/bin_v4/set_captor.php', { id: sensorId, val: rawVal })
            .done(function() {
                $('#modal_change_v4').modal('hide');
                refreshSensor(sensorId);
                $.growlValidate(lang.valid.communication);
            })
            .fail(function() { $.growlWarning(lang.error.configNotSave); });
    });

    // ── Bouton change_list_v4 (valeur énumérée) ──────────────────────────
    $(document).on('click', '.change_list_v4', function() {
        var sensorId = $(this).data('sensor');

        $.get('_include/bin_v4/get_captor_lim.php', { id: sensorId })
            .done(function(raw) {
                var json;
                try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch (e) { return; }
                if (!json || !json.format) { return; }

                $('#v4_listSensorId').val(sensorId);
                $('#v4_listSensorTitle').text(sensorId);
                $('#v4_listSensorValue').html(buildOptions(json.format, json.val));
                $('#modal_change_list_v4').modal('show');
            });
    });

    // Confirmation changement liste
    $('#bt_v4_confirm_list').click(function() {
        var sensorId = $('#v4_listSensorId').val();
        var val      = $('#v4_listSensorValue').val();

        $.get('_include/bin_v4/set_captor.php', { id: sensorId, val: val })
            .done(function() {
                $('#modal_change_list_v4').modal('hide');
                refreshSensor(sensorId);
                $.growlValidate(lang.valid.communication);
            })
            .fail(function() { $.growlWarning(lang.error.configNotSave); });
    });

    // Polling toutes les 60 secondes
    setInterval(function() {
        connectBoiler_v4();
    }, 60000);
});
