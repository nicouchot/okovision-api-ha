/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek (inspired by skydarc)
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

$(document).ready(function() {

    // ── Helpers ────────────────────────────────────────────────────────────

    function mailErrorMsg(json) {
        if (!json || !json.error) return lang.error.mailboxDontRespond;
        var code = json.error.code;
        if (code === 'ext_missing')        return lang.error.mail.extMissing;
        if (code === 'auth_failed')        return lang.error.mail.authFailed;
        if (code === 'connection_failed')  return lang.error.mail.connectionFailed;
        if (json.error.message)            return json.error.message;
        return lang.error.mailboxDontRespond;
    }

    // ── Chargement initial de la liste ─────────────────────────────────────
    $.get('_include/bin_v4/get_list_mail.php').done(function(json) {

        $("#inwork-remotefile").hide();

        if (!json || !json.success) {
            $.growlErreur(mailErrorMsg(json));
            return;
        }

        var mailList;
        try {
            mailList = (typeof json.mailArray === 'string')
                ? JSON.parse(json.mailArray)
                : json.mailArray;
        } catch (e) {
            $.growlErreur(lang.error.mailboxDontRespond);
            return;
        }

        if (!mailList || Object.keys(mailList).length === 0) {
            $.growlErreur(lang.error.noMail);
            return;
        }

        var maxkey;

        $("#listeFichierFromMailBox > tbody").html("");

        // Ligne sentinelle masquée (utilisée par le toggle #index_all)
        $('#listeFichierFromMailBox > tbody').append(
            '<tr style="display:none;"><td><input type="checkbox" id="index_0"></td><td></td></tr>'
        );

        $.each(mailList, function(key, mail) {
            maxkey = key;
            $('#listeFichierFromMailBox > tbody').append(
                '<tr>' +
                    '<td><input type="checkbox" id="index_' + key + '" name="' + key + '"> ' + mail + '</td>' +
                    '<td><button type="button" id="del_mail" class="btn btn-default btn-sm" name="' + key + '">' +
                        '<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>' +
                    '</button></td>' +
                '</tr>'
            );
        });

        if (typeof maxkey !== 'undefined') {
            $('#listeFichierFromMailBox > tbody').append(
                '<tr>' +
                    '<td><input type="checkbox" id="index_all"> ' + lang.text.importAll + '</td>' +
                    '<td><button type="button" id="del_mail" class="btn btn-default btn-sm" name="1:' + maxkey + '">' +
                        '<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>' +
                    '</button></td>' +
                '</tr>'
            );
        }

    }).fail(function() {
        $("#inwork-remotefile").hide();
        $.growlErreur(lang.error.mailboxDontRespond);
    });

    // ── Toggle "Tout sélectionner" via la case #index_all ──────────────────
    $("body").on("click", "#index_all", function() {
        if ($('#index_0').is(':checked')) {
            $('input:checkbox').prop('checked', false);
        } else {
            $('input:checkbox').prop('checked', true);
        }
    });

    // ── Import de la sélection ─────────────────────────────────────────────
    $("#bt_import").click(function() {
        var mailSelected = [];
        $.each($("input:checkbox:checked"), function() {
            if (!isNaN($(this).attr('name'))) {
                mailSelected.push($(this).attr('name'));
            }
        });
        var list = mailSelected.join(",");

        if (list === "") {
            $.growlErreur(lang.error.noSelect);
            return;
        }

        $.get('_include/bin_v4/download_csv.php?list=' + list).done(function(json) {
            if (json && json.success) {
                $.growlValidate(lang.valid.csvImport);
            } else {
                $.growlErreur(mailErrorMsg(json));
            }
        }).fail(function() {
            $.growlErreur(lang.error.csvImport);
        });
    });

    // ── Suppression d'un mail (bouton poubelle par ligne) ──────────────────
    $("body").on("click", "[id^='del_mail']:button", function() {
        var key = $(this).attr('name');

        $.get('_include/bin_v4/delete_mail.php?list=' + key).done(function(json) {
            if (json && json.success) {
                $.growlValidate(lang.valid.delMail);
            } else {
                $.growlErreur(mailErrorMsg(json));
            }
            setTimeout(function() { location.reload(true); }, 2000);
        }).fail(function() {
            $.growlErreur(lang.error.delMail);
            setTimeout(function() { location.reload(true); }, 2000);
        });
    });
});
