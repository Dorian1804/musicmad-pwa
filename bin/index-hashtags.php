<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)<2 || count($argv)>3 || !preg_match('/^--after=([0-9]{1,10})$/D',$argv[1],$after) || (isset($argv[2]) && !preg_match('/^--limit=(200|1[0-9]{2}|[1-9][0-9]?)$/D',$argv[2],$limit))){fwrite(STDERR,"Uso: php bin/index-hashtags.php --after=0 [--limit=100]\nProcesa solo un lote; conserva ultimo_id para continuar.\n");exit(2);}
require_once dirname(__DIR__).'/includes/auth.php';require_once dirname(__DIR__).'/includes/social.php';require_once dirname(__DIR__).'/includes/hashtag_backfill.php';
try{echo json_encode(reindexarEtiquetasHistoricas((int)$after[1],isset($limit[1])?(int)$limit[1]:100),JSON_THROW_ON_ERROR)."\n";}
catch(Throwable $error){error_log('Hashtag indexing: '.get_class($error));fwrite(STDERR,"Indexación detenida. Puedes repetir el último cursor: el índice no se duplica.\n");exit(1);}