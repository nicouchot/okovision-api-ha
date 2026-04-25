<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Bloc commun aux pages rt.php et rt_v4.php :
* spinner de chargement initial + bandeau d'alerte « configuration à sauver ».
*/
?>
<div id="logginprogress" class="page-header" align="center">
    <p><span class="glyphicon glyphicon-refresh glyphicon-spin"></span>&nbsp;<?php echo session::getInstance()->getLabel('lang.text.page.rt.logginprogress'); ?></p>
</div>
<div id="mustSaving" class="alert alert-warning" style="display: none;" role="alert">
    <span class="glyphicon glyphicon-floppy-save"></span>&nbsp;<?php echo session::getInstance()->getLabel('lang.text.page.rt.alertWarning'); ?>
</div>
