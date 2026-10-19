<?php
/** One bounded batch, resumable by the last ID returned; never read media or contact external services. */
function reindexarEtiquetasHistoricas(int $after,int $limit=100): array
{
    if($after<0 || $limit<1 || $limit>200)throw new InvalidArgumentException('Usa un cursor positivo y un lote de 1 a 200 publicaciones.');
    if(!social3Disponible())throw new RuntimeException('El índice social no está disponible.');
    $db=getDB();if($db->inTransaction())throw new LogicException('La indexación necesita su propia transacción.');
    $st=$db->prepare('SELECT id FROM publicaciones WHERE id>? ORDER BY id LIMIT ?');$st->bindValue(1,$after,PDO::PARAM_INT);$st->bindValue(2,$limit,PDO::PARAM_INT);$st->execute();$ids=$st->fetchAll(PDO::FETCH_COLUMN);$processed=0;$last=$after;
    foreach($ids as $id){
        $db->beginTransaction();
        try{
            $st=$db->prepare('SELECT titulo,texto FROM publicaciones WHERE id=? FOR UPDATE');$st->execute([$id]);$row=$st->fetch();
            if($row){
                $db->prepare('DELETE FROM publicacion_hashtags WHERE publicacion_id=?')->execute([$id]);
                indexarEtiquetasPublicacion((int)$id,$row['titulo'].' '.$row['texto']);$processed++;
            }
            $db->commit();$last=(int)$id;
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    return ['procesadas'=>$processed,'ultimo_id'=>$last,'posibles_mas'=>count($ids)===$limit];
}