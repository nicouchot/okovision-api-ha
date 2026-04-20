<?php
/*****************************************************
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
******************************************************/

include_once 'config.php';
include_once '_templates/header.php';
include_once '_templates/menu.php';
?>

<div class="container theme-showcase" role="main">
<br/>
    <div class="page-header">
        <h2><?php echo session::getInstance()->getLabel('lang.text.menu.manual.import.mail'); ?></h2>
    </div>

    <p><?php echo session::getInstance()->getLabel('lang.text.page.manual.mail.import'); ?></p>

    <div id="inwork-remotefile">
        <br/><span class="glyphicon glyphicon-refresh glyphicon-spin"></span>&nbsp;<?php echo session::getInstance()->getLabel('lang.text.page.manual.workinprogress'); ?>
    </div>

    <table id="listeFichierFromMailBox" class="table table-hover" style="display:none;">
        <thead>
            <tr>
                <th class="col-md-1">
                    <input type="checkbox" id="selectAll" title="Sélectionner tout">
                </th>
                <th class="col-md-9"><?php echo session::getInstance()->getLabel('lang.text.page.manual.mail.filefromboiler'); ?></th>
                <th class="col-md-2"></th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>

    <div id="import-actions" style="display:none; margin-top:10px;">
        <button type="button" id="bt_import_selected" class="btn btn-primary">
            <span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span>
            <?php echo session::getInstance()->getLabel('lang.text.importAll'); ?>
        </button>
    </div>

</div>

<?php include(__DIR__.'/_templates/footer.php'); ?>
<script src="js/amImpMail.js"></script>
</body>
</html>
