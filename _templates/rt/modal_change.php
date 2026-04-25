<?php
/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Modale d'édition d'une valeur de capteur, partagée entre rt.php et rt_v4.php.
*
* Variable attendue avant include :
*   $confirmId : id du bouton de confirmation (btConfirmSensor | btConfirmSensor_v4)
*/
$confirmId = $confirmId ?? 'btConfirmSensor';
?>
<div class="modal fade" id="modal_change" tabindex="-1" role="dialog" aria-labelledby="changeValue" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="sensorTitle"></h4>
            </div>
            <div class="modal-body">
                <div class="hidden">
                    <input type="text" id="sensorId">
                    <input type="number" id="sensorDivisor">
                    <input type="text" id="sensorUnitText">
                </div>
                <div class="col-md-6 text-center" id="sensorMin"></div>
                <div class="col-md-6 text-center" id="sensorMax"></div>
                <br/>
                <form>
                    <input type="number" class="form-control text-center input-lg col-xs-10" id="sensorValue" step="0.1">
                </form>
                <br/> <br/>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">
                    <span class="glyphicon glyphicon-remove" aria-hidden="true"></span>
                </button>
                <button type="button" id="<?php echo e($confirmId); ?>" class="btn btn-default btn-sm">
                    <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>
</div>
