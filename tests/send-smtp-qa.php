<?php
require_once __DIR__.'/paths.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require_once dirname(__DIR__).'/includes/mailer.php';
if(getenv('MM_RUN_QA')!=='1'||DB_NAME!=='musicmad_qa')throw new RuntimeException('Solo QA aislada.');
putenv('MM_MAIL_HOST=127.0.0.1');putenv('MM_MAIL_PORT=2465');putenv('MM_MAIL_ENCRYPTION=ssl');putenv('MM_MAIL_USER=qa-fixture');putenv('MM_MAIL_PASS=qa-fixture');putenv('MM_MAIL_FROM=sender@musicmad.invalid');putenv('MM_TEST_MAIL_CA='.musicmadQaRoot().'/smtp-qa.crt');
enviarCorreoTrabajo(['to'=>'recipient@musicmad.invalid','subject'=>'Prueba local MusicMad','body'=>'Correo ficticio en loopback; acentos y música.']);
echo "PHPMailer entregó el mensaje ficticio al servidor TLS local.\n";
