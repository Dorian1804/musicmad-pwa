<?php
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/includes/i18n.php';
$keys=catalogoIdiomaMusicMad('es');
foreach(['feed.php','perfil.php','includes/onboarding_page.php'] as $name) {
    $path=dirname(__DIR__).'/'.$name;$output='';
    foreach(token_get_all(file_get_contents($path)) as $token) {
        if(!is_array($token)){$output.=$token;continue;}
        $text=$token[1];
        if($token[0]===T_INLINE_HTML) {
            $text=preg_replace_callback('/(?<=>)([^<>]+)(?=<|$)/u',static function($match)use($keys){
                $key=trim($match[1]);if(!isset($keys[$key]))return $match[0];
                preg_match('/^(\s*)(.*?)(\s*)$/us',$match[1],$spaces);
                return $spaces[1].'<?= e(mmTexto('.var_export($key,true).')) ?>'.$spaces[3];
            },$text);
            $text=preg_replace_callback('/\b(aria-label|placeholder|title|data-sheet-title)="([^"]*)"/u',static function($match)use($keys){
                return isset($keys[$match[2]])?$match[1].'="<?= e(mmTexto('.var_export($match[2],true).')) ?>"':$match[0];
            },$text);
        }
        $output.=$text;
    }
    file_put_contents($path,$output);
}
