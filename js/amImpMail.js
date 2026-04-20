/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */

$(document).ready(function() {

    /**
     * Charge la liste des emails disponibles depuis get_list_mail.php.
     */
    function loadMailList() {
        $('#inwork-remotefile').show();
        $('#listeFichierFromMailBox').hide();
        $('#import-actions').hide();
        $('#listeFichierFromMailBox tbody').empty();

        $.get('_include/bin_v4/get_list_mail.php')
            .done(function(raw) {
                var json;
                try {
                    json = (typeof raw === 'string') ? JSON.parse(raw) : raw;
                } catch (e) {
                    $.growlWarning(lang.error.mailboxDontRespond);
                    $('#inwork-remotefile').hide();
                    return;
                }

                $('#inwork-remotefile').hide();

                if (!json || !json.response) {
                    $.growlWarning(lang.error.getFileFromMailBox);
                    return;
                }

                var mails = json.mailArray || [];

                if (mails.length === 0) {
                    $.growlWarning(lang.error.noMail);
                    return;
                }

                var tbody = $('#listeFichierFromMailBox tbody');
                mails.forEach(function(item, idx) {
                    var row = '<tr>' +
                        '<td><input type="checkbox" class="mail-check" data-index="' + idx + '" data-email="' + item.emailNumber + '"></td>' +
                        '<td>' + item.file + '</td>' +
                        '<td class="text-right">' +
                            '<button type="button" class="btn btn-xs btn-danger bt-del-mail" data-index="' + idx + '" data-email="' + item.emailNumber + '">' +
                                '<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>' +
                            '</button>' +
                        '</td>' +
                        '</tr>';
                    tbody.append(row);
                });

                $('#listeFichierFromMailBox').show();
                $('#import-actions').show();
            })
            .fail(function() {
                $.growlWarning(lang.error.mailboxDontRespond);
                $('#inwork-remotefile').hide();
            });
    }

    // Chargement initial
    loadMailList();

    // ── Sélectionner tout ──────────────────────────────────────────────
    $('#selectAll').change(function() {
        var checked = $(this).is(':checked');
        $('.mail-check').prop('checked', checked);
    });

    // ── Suppression d'un mail ──────────────────────────────────────────
    $(document).on('click', '.bt-del-mail', function() {
        var emailNumber = $(this).data('email');

        $.get('_include/bin_v4/delete_mail.php', { list: emailNumber })
            .done(function(raw) {
                var json;
                try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch (e) { json = null; }

                if (json && json.response) {
                    $.growlValidate(lang.valid.delMail);
                } else {
                    $.growlWarning(lang.error.delMail);
                }
                setTimeout(function() { loadMailList(); }, 1500);
            })
            .fail(function() { $.growlWarning(lang.error.delMail); });
    });

    // ── Import de la sélection ─────────────────────────────────────────
    $('#bt_import_selected').click(function() {
        var selected = [];
        $('.mail-check:checked').each(function() {
            selected.push($(this).data('email'));
        });

        if (selected.length === 0) {
            $.growlWarning(lang.error.noSelect);
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        $btn.find('.glyphicon').addClass('glyphicon-spin');

        $.get('_include/bin_v4/download_csv.php', { list: selected.join(',') })
            .done(function(raw) {
                var json;
                try { json = (typeof raw === 'string') ? JSON.parse(raw) : raw; } catch (e) { json = null; }

                $btn.prop('disabled', false);
                $btn.find('.glyphicon').removeClass('glyphicon-spin');

                if (json && json.response) {
                    $.growlValidate(lang.valid.import);
                    setTimeout(function() { loadMailList(); }, 1500);
                } else {
                    $.growlWarning(lang.error.importFile);
                }
            })
            .fail(function() {
                $btn.prop('disabled', false);
                $btn.find('.glyphicon').removeClass('glyphicon-spin');
                $.growlWarning(lang.error.importFile);
            });
    });
});
