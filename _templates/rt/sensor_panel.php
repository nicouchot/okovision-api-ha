<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Helper de panel capteur partagé entre rt.php et rt_v4.php.
*
* Variables attendues avant include :
*   $id          : id du div de valeur (ex. "FA0_L_mittlere_laufzeit", "pe1.L_modulation")
*   $key         : clé de capteur partagée tooltip+label (ex. "CAPPL:FA[0].L_mittlere_laufzeit")
*   $action      : '' | 'change' | 'change_v4' | 'change_list_v4' | 'refresh_v4'
*   $savable     : bool — ajoute la classe "2save" sur le div de valeur (défaut false)
*   $default     : string — contenu initial du div valeur (ex. "--" pour rt.php, "" pour rt_v4.php)
*/
$id      = $id      ?? '';
$key     = $key     ?? '';
$action  = $action  ?? '';
$savable = $savable ?? false;
$default = $default ?? '';

$valueClass = 'huge' . ($savable ? ' 2save' : '');

$actionIcon = match ($action) {
    'refresh_v4' => 'glyphicon-refresh',
    'change', 'change_v4', 'change_list_v4' => 'glyphicon-pencil',
    default => '',
};

$session = session::getInstance();
?>
<div class="col-lg-3 col-md-6">
    <div class="panel panel-primary">
        <div class="panel-heading">
            <div class="row">
                <div class="col-xs-2 text-left"><span class="glyphicon glyphicon-info-sign tip" title="<?php echo $session->getLabel('lang.tooltip.'.$key); ?>" data-original-title="Tooltip"></span></div>
                <div class="col-xs-8 text-center">
                    <div class="<?php echo e($valueClass); ?>" id="<?php echo e($id); ?>"><?php echo e($default); ?></div>
                </div>
<?php if ($action !== ''): ?>
                <div class="col-xs-2 text-right">
                    <a href="javascript:void(0)" class="<?php echo e($action); ?>"><span class="glyphicon <?php echo e($actionIcon); ?>" aria-hidden="true"></span></a>
                </div>
<?php else: ?>
                <div class="col-xs-2"></div>
<?php endif; ?>
            </div>
        </div>
        <div class="panel-footer">
            <div class="labelbox"><?php echo $session->getLabel('lang.capteur.'.$key); ?></div>
        </div>
    </div>
</div>
