<?php
use PHPUnit\Framework\TestCase;
final class MediaStorageTest extends TestCase
{
    public function testPublicConfiguredUploadRootCannotPutNewBytesInsideDeployableCode():void
    {
        $process=proc_open([PHP_BINARY,__DIR__.'/media-update-fixture.php','coordinate','','public-configured'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$errors);
        foreach(json_decode($output,true,512,JSON_THROW_ON_ERROR) as $stage=>$checks)foreach($checks as $kind=>$passed)self::assertTrue($passed,$stage.': '.$kind);
    }
    public function testHostingStyleUpdateAcrossFreshProcessesPreservesAndRestoresAllSixKinds():void
    {
        $process=proc_open([PHP_BINARY,__DIR__.'/media-update-fixture.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$errors);
        $results=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        foreach($results as $stage=>$checks)foreach($checks as $kind=>$passed)self::assertTrue($passed,$stage.': '.$kind);
    }
    public function testPrimaryBytesSurviveReplacingTheAppAndDoNotOverwriteSources():void
    {
        $process=proc_open([PHP_BINARY,__DIR__.'/persistent-storage-fixture.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$errors);$data=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        foreach(['outside','legacy_copied','update_survives','immutable','traversal','image_name','photo_restored'] as $check)self::assertTrue($data[$check],$check);
        if(isset($data['symlink']))self::assertTrue($data['symlink']);
    }
    public function testExplicitIsolatedRootAndLegacyImageUrlsAreRetained():void
    {
        if(defined('APP_UPLOAD_ROOT'))self::assertSame(rtrim(APP_UPLOAD_ROOT,'/\\'),musicmadMediaRoot());
        else self::assertFalse(rutaDentroRespaldo(musicmadMediaRoot(),APP_ROOT));
        $apache=file_get_contents(APP_ROOT.'/.htaccess');self::assertStringContainsString('media-image.php?kind=$1&name=$2',$apache);
        self::assertStringContainsString("['GET','HEAD']",file_get_contents(APP_ROOT.'/media-image.php'));
    }
}
