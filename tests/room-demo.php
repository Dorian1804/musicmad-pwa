<?php
// QA fixture only; never package this file.
if(PHP_SAPI!=='cli')exit;putenv('MM_RUN_QA=1');require __DIR__.'/bootstrap.php';if(DB_NAME!=='musicmad_qa')throw new RuntimeException('QA only.');
$id=(int)($argv[1]??0);$room=salaDisponiblePara(1,$id);if(!$room||(int)$room['anfitrion_id']!==1||!str_ends_with($room['nombre'],'QA'))throw new RuntimeException('Expected fictitious room.');
entrarSalaEscucha(2,$id,$room['codigo']);if(($argv[2]??'')!=='leer')enviarMensajeSala(2,$id,'Buen sonido, ya estoy escuchando. Mensaje ficticio QA.',bin2hex(random_bytes(16)));$dto=estadoSalaEscucha(2,$id);echo 'Participantes: '.count($dto['integrantes']).'; estado: '.$dto['reproduccion'].'; posición: '.round($dto['posicion'],2)."; sin código en DTO.\n";
