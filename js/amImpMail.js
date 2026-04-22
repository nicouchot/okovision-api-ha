/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek (inspired by skydarc)
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

$(document).ready(function() {

    // ── Chargement initial de la liste ─────────────────────────────────
    $.get('_include/bin_v4/get_list_mail.php').done(function(jsonMail) {

        if (!jsonMail) {
            $("#inwork-remotefile").hide();
            $.growlErreur(lang.error.mailboxDontRespond);
            return;
        }

        var attachments;
        try {
            attachments = (typeof jsonMail === 'string') ? JSON.parse(jsonMail) : jsonMail;
        } catch (e) {
            $("#inwork-remotefile").hide();
            $.growlErreur(lang.error.mailboxDontRespond);
            return;
        }

        if (attachments.response === true) {

            var mailList;
            try {
                mailList = (typeof attachments.mailArray === 'string')
                    ? JSON.parse(attachments.mailArray)
                    : attachments.mailArray;
            } catch (e) {
                $("#inwork-remotefile").hide();
                $.growlErreur(lang.error.mailboxDontRespond);
                return;
            }

            var maxkey;

            $("#inwork-remotefile").hide();
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

        } else if (attachments.response === 'noMail') {
            $("#inwork-remotefile").hide();
            $.growlErreur(lang.error.noMail);
        } else {
            $("#inwork-remotefile").hide();
            $.growlErreur(lang.error.mailboxDontRespond);
        }
    }).fail(function() {
        $("#inwork-remotefile").hide();
        $.growlErreur(lang.error.mailboxDontRespond);
    });

    // ── Toggle "Tout sélectionner" via la case #index_all ──────────────
    $("body").on("click", "#index_all", function() {
        if ($('#index_0').is(':checked')) {
            $('input:checkbox').prop('checked', false);
        } else {
            $('input:checkbox').prop('checked', true);
        }
    });

    // ── Import de la sélection ─────────────────────────────────────────
    $("#bt_import").click(function() {
        var mailSelected = [];
        $.each($("input:checkbox:checked"), function() {
            if (!isNaN($(this).attr('name'))) {
                mailSelected.push($(this).attr('name'));
            }
        });
        var list = mailSelected.join(",");

        if (list !== "") {
            $.get('_include/bin_v4/download_csv.php?list=' + list).done(function(raw) {
                if (raw == 'true') $.growlValidate(lang.valid.csvImport);
                else $.growlErreur(lang.error.csvImport);
            });
        } else {
            $.growlErreur(lang.error.noSelect);
        }
    });

    // ── Suppression d'un mail (bouton poubelle par ligne) ──────────────
    $("body").on("click", "[id^='del_mail']:button", function() {
        var key = $(this).attr('name');

        $.get('_include/bin_v4/delete_mail.php?list=' + key).done(function(raw) {
            if (raw == 'true') $.growlValidate(lang.valid.delMail);
            else $.growlErreur(lang.error.delMail);

            setTimeout(function() { location.reload(true); }, 2000);
        });
    });
});
