<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/linked_text.php';
final class LinkedTextTest extends TestCase
{
 public function testTagsUnicodeNewlinesAndAllHtmlAreEscaped(): void
 {
  $html=renderTextoSocialSeguro("<script>alert('x')</script> #Música\n#ROCK & \"texto\"");
  self::assertStringNotContainsString('<script>',$html);self::assertStringContainsString('&lt;script&gt;',$html);self::assertStringContainsString('buscar.php?tag=m%C3%BAsica',$html);self::assertStringContainsString('buscar.php?tag=rock',$html);self::assertStringContainsString('<br',$html);self::assertStringContainsString('&amp;',$html);
 }
 public function testEmailsUrlsPartialNamesAndTooLongTagsStayPlain(): void
 {
  $text='correo@musico.com https://example.com/#rock https://example.com/@musico abc#rock #a #'.str_repeat('a',41).' @'.str_repeat('a',31).' #rock';
  $html=renderTextoSocialSeguro($text,['musico'=>2]);self::assertSame(1,substr_count($html,'<a '));self::assertStringContainsString('correo@musico.com',$html);self::assertStringContainsString('https://example.com/@musico',$html);
 }
 public function testOnlyResolvedPositiveIntegerMentionsBecomeLocalLinks(): void
 {
  $html=renderTextoSocialSeguro('@ana_qa @privada @fake',['ana_qa'=>42,'privada'=>'javascript:alert(1)','fake'=>0]);
  self::assertStringContainsString('href="musico.php?id=42">@ana_qa</a>',$html);self::assertStringNotContainsString('javascript:',$html);self::assertSame(1,substr_count($html,'<a '));self::assertStringContainsString('@privada @fake',$html);
 }
 public function testOnlyEightDistinctTagsAreLinkedLikeTheSearchIndex(): void
 {
  $text=implode(' ',array_map(fn($i)=>'#tag'.$i,range(1,9)));$html=renderTextoSocialSeguro($text);
  self::assertSame(8,substr_count($html,'<a '));self::assertStringNotContainsString('href="buscar.php?tag=tag9"',$html);self::assertStringEndsWith('#tag9',$html);
 }

 public function testIndexAndRendererUseTheSameTokenRulesAndBoundedWork(): void
 {
  self::assertSame(['rock','música'],array_values(etiquetasEnTexto('#ROCK #rock #música https://example/#ignore correo@nombre #a')));
  self::assertCount(32,tokensSocialesTexto(str_repeat('#rock ',100)));
  self::assertSame(32,substr_count(renderTextoSocialSeguro(str_repeat('#rock ',100)),'<a '));
 }
}