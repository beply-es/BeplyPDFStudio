<?php
/** Native use-design authorization contract. Synthetic, disposable database only. */
declare(strict_types=1);
if (getenv('BEPDF_SYNTHETIC_HTTP_DESIGN_TEST') !== '1') throw new RuntimeException('Explicit synthetic HTTP test required');
if (!defined('FS_FOLDER')) define('FS_FOLDER', dirname(__DIR__, 3));
assertHttpRuntimeRoot(FS_FOLDER);
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
if (!getenv('BEPDF_HTTP_EXPECTED_DB') || FS_DB_NAME !== getenv('BEPDF_HTTP_EXPECTED_DB')) throw new RuntimeException('Exact synthetic database required');
FacturaScripts\Core\Kernel::init();
FacturaScripts\Core\Plugins::init();
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
function assertHttpRuntimeRoot(string $root): void
{
 $canonical=realpath($root);
 if($canonical===false||!is_dir($canonical))throw new RuntimeException('Exact synthetic HTTP root required');
 // Core index.php resolves config.php and FS_FOLDER from its own __DIR__.
 foreach(['index.php','config.php']as$file){
  $path=$root.'/'.$file;
  if(!is_file($path)||is_link($path)||realpath($path)!==$canonical.'/'.$file)throw new RuntimeException('Synthetic HTTP entrypoint and config must belong to exact root');
 }
}
function need(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$owned=[];$server=null;$failures=[];
$dir=sys_get_temp_dir().'/BeplyPdfHttpExport-'.getmypid();need(!file_exists($dir)&&mkdir($dir,0700),'own HTTP artifact directory');
$proof=['runtime'=>'native Core HTTP + Dinamic exporters','database'=>FS_DB_NAME,'requests'=>[],'checks'=>[],'artifactDir'=>$dir];
$proof['sourceHashes']=[];foreach(['Init.php','Extension/Controller/EditController.php','Lib/Export/PDFExport.php','facturascripts.ini','Tests/run-http-export-contract.php']as$file)$proof['sourceHashes'][$file]=hash_file('sha256',FS_FOLDER.'/Plugins/BeplyPDFStudio/'.$file);
function check(bool $ok,string $name):void{global $proof,$failures;$proof['checks'][$name]=$ok;if(!$ok)$failures[]=$name;}
function saveOwned($model,string $label):void{global $owned;need($model->save(),$label);$owned[]=$model;}
function requestHttp(string $path,string $actor,?array $data=null):array{
 global $base,$nonce,$dir,$proof,$server;
 $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>120,CURLOPT_COOKIEJAR=>$dir.'/'.$actor.'.cookies',CURLOPT_COOKIEFILE=>$dir.'/'.$actor.'.cookies']);
 if($data!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
 $raw=curl_exec($ch);$info=curl_getinfo($ch);
 if(!is_string($raw)){
  $code=curl_errno($ch);$status=is_resource($server)?proc_get_status($server):null;
  // Only fixed descriptions/numbers: curl_error(), URL and headers can contain credentials.
  $proof['transportFailure']=['requestIndex'=>count($proof['requests']),'method'=>$data===null?'GET':'POST',
   'curlCode'=>$code,'curlDescription'=>curl_strerror($code),'http'=>$info['http_code'],
   'expectedBytes'=>$info['download_content_length'],'receivedBytes'=>$info['size_download'],
   'headerBytes'=>$info['header_size'],'serverRunning'=>$status['running']??null,
   'serverExitCode'=>$status['exitcode']??null,'exactInstance'=>false];
  curl_close($ch);throw new RuntimeException('HTTP transport (curl '.$code.')');
 }
 $headers=substr($raw,0,$info['header_size']);$body=substr($raw,$info['header_size']);curl_close($ch);
 need(str_contains(strtolower($headers),strtolower('X-Beply-Contract-Run: '.$nonce)),'HTTP instance binding');
 $file='response-'.count($proof['requests']).'.body';need(file_put_contents($dir.'/'.$file,$body)===strlen($body),'own HTTP evidence');chmod($dir.'/'.$file,0600);
 $row=['path'=>$path,'actor'=>$actor,'method'=>$data===null?'GET':'POST','http'=>$info['http_code'],'contentType'=>$info['content_type'],'pdf'=>str_starts_with($body,'%PDF'),'bodyFile'=>$file,'bodySha256'=>hash('sha256',$body),'exactInstance'=>true];$proof['requests'][]=$row;return [$row,$body];
}
function formToken(string $html):string{$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$values=$xp->query('//input[@name="multireqtoken"]/@value');need($values->length>0,'native login token');return $values->item(0)->nodeValue;}
function loginHttp(string $actor,string $password):void{[, $html]=requestHttp('/Login',$actor);[$row]=requestHttp('/Login',$actor,['action'=>'login','fsNick'=>$actor,'fsPassword'=>$password,'multireqtoken'=>formToken($html)]);need($row['http']===302,'native login accepted');}

function styleSnapshot():array {
 $out=[];foreach(\FacturaScripts\Dinamic\Model\BeplyPdfStyle::all([],['id'=>'ASC'],0,0)as$s){
  $cols=[];foreach(\FacturaScripts\Dinamic\Model\BeplyPdfColumn::all([new DataBaseWhere('idstyle',$s->id)],['id'=>'ASC'],0,0)as$col)$cols[]=$col->toArray();
  $out[]=['row'=>$s->toArray(),'columns'=>$cols];
 } return $out;
}
try {
 need(getenv('BEPDF_HTTP_EXPECTED_CORE') !== false && number_format(\FacturaScripts\Core\Kernel::version(), 2, '.', '') === getenv('BEPDF_HTTP_EXPECTED_CORE'), 'exact native Core version required');
 need(!Tools::settings('default','telemetryinstall')&&!Tools::settings('default','telemetrykey'),'synthetic runtime not telemetry enrolled');
 $proof['harnessSha256']=hash_file('sha256',__FILE__);$proof['testContract']='use-design authorization';$proof['disposableDatabaseRequired']=true;
 foreach(['Controller/AdminBeplyPdf.php','Model/BeplyPdfStyle.php','View/BeplyPdfCards.html.twig','Tests/run-http-design-permission-contract.php']as$f)$proof['sourceHashes'][$f]=hash_file('sha256',FS_FOLDER.'/Plugins/BeplyPDFStudio/'.$f);
 $admin=new User();need($admin->load('e2e_admin')&&$admin->admin&&$admin->enabled,'canonical synthetic admin');
 $role=new Role();need(!$role->load('BPDFHTTP'),'fresh synthetic role');$role->codrole='BPDFHTTP';$role->descripcion='Synthetic read-only Studio authorization probe';saveOwned($role,'own role');
 $user=new User();need(!$user->load('bpdfhttp_noexport'),'fresh synthetic actor');$user->nick='bpdfhttp_noexport';$user->password=password_hash('synthetic-http-only',PASSWORD_DEFAULT);$user->admin=false;$user->enabled=true;$user->email='pdf-contract@example.test';$user->idempresa=$admin->idempresa;$user->codalmacen=$admin->codalmacen;$user->langcode='es_ES';saveOwned($user,'own restricted actor');
 $membership=new RoleUser();$membership->nick=$user->nick;$membership->codrole=$role->codrole;saveOwned($membership,'own membership');
 $access=new RoleAccess();$access->codrole=$role->codrole;$access->pagename='AdminBeplyPdf';$access->allowexport=false;$access->allowupdate=false;$access->allowdelete=false;$access->allowimport=false;$access->onlyownerdata=false;saveOwned($access,'own read-only Studio access');
 $permission=new ControllerPermissions($user,'AdminBeplyPdf');need($permission->allowAccess&&!$permission->allowUpdate&&!$user->admin,'effective native read-only permission');
 $proof['effectivePermission']=['allowAccess'=>$permission->allowAccess,'allowUpdate'=>$permission->allowUpdate,'admin'=>$user->admin];
 $writerRole=new Role();need(!$writerRole->load('BPDFEDIT'),'fresh writer role');$writerRole->codrole='BPDFEDIT';$writerRole->descripcion='Synthetic Studio writer';saveOwned($writerRole,'own writer role');
 $writer=new User();need(!$writer->load('bpdfhttp_writer'),'fresh writer actor');$writer->nick='bpdfhttp_writer';$writer->password=password_hash('synthetic-write-only',PASSWORD_DEFAULT);$writer->admin=false;$writer->enabled=true;$writer->email='writer@example.test';$writer->idempresa=$admin->idempresa;$writer->codalmacen=$admin->codalmacen;$writer->langcode='es_ES';saveOwned($writer,'own writer actor');
 $writerMembership=new RoleUser();$writerMembership->nick=$writer->nick;$writerMembership->codrole=$writerRole->codrole;saveOwned($writerMembership,'own writer membership');
 $writerAccess=new RoleAccess();$writerAccess->codrole=$writerRole->codrole;$writerAccess->pagename='AdminBeplyPdf';$writerAccess->allowexport=false;$writerAccess->allowupdate=true;$writerAccess->allowdelete=false;$writerAccess->allowimport=false;$writerAccess->onlyownerdata=false;saveOwned($writerAccess,'own writer access');
 $writerPermission=new ControllerPermissions($writer,'AdminBeplyPdf');need($writerPermission->allowAccess&&$writerPermission->allowUpdate&&!$writer->admin,'native non-admin writer permission');

 $configRole=new Role();need(!$configRole->load('BPDFCONF'),'fresh configure-viewer role');$configRole->codrole='BPDFCONF';$configRole->descripcion='Synthetic independent configure access';saveOwned($configRole,'own configure role');
 $configUser=new User();need(!$configUser->load('bpdfhttp_configure'),'fresh configure viewer');$configUser->nick='bpdfhttp_configure';$configUser->password=password_hash('synthetic-configure-only',PASSWORD_DEFAULT);$configUser->admin=false;$configUser->enabled=true;$configUser->email='configure@example.test';$configUser->idempresa=$admin->idempresa;$configUser->codalmacen=$admin->codalmacen;$configUser->langcode='es_ES';saveOwned($configUser,'own configure viewer');
 $configMembership=new RoleUser();$configMembership->nick=$configUser->nick;$configMembership->codrole=$configRole->codrole;saveOwned($configMembership,'own configure membership');
 foreach(['AdminBeplyPdf','EditBeplyPdfStyle']as$page){$configAccess=new RoleAccess();$configAccess->codrole=$configRole->codrole;$configAccess->pagename=$page;$configAccess->allowexport=false;$configAccess->allowupdate=false;$configAccess->allowdelete=false;$configAccess->allowimport=false;$configAccess->onlyownerdata=false;saveOwned($configAccess,'own configure read access');}
 $configPermission=new ControllerPermissions($configUser,'AdminBeplyPdf');need($configPermission->allowAccess&&!$configPermission->allowUpdate&&$configUser->can('EditBeplyPdfStyle'),'native independent configure capability');
 $layouts=array_keys(\FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout::selectableRegistry());need(count($layouts)>1,'two real native designs');
 $before=styleSnapshot();$active=null;foreach($before as$row)if($row['row']['idempresa']===null&&$row['row']['idformato']===null){$active=$row['row']['diseno'];break;}
 $chosen=array_values(array_filter($layouts,static fn($key)=>$key!==$active));$adminDesign=$chosen[0];$readerDesign=array_values(array_filter($layouts,static fn($key)=>$key!==$adminDesign))[0];
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);need(is_resource($socket),'own local port');$endpoint=stream_socket_get_name($socket,false);$port=(int)substr(strrchr($endpoint,':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;$nonce=bin2hex(random_bytes(16));$router=$dir.'/router.php';file_put_contents($router,'<?php header('.var_export('X-Beply-Contract-Run: '.$nonce,true).'); require '.var_export(FS_FOLDER.'/index.php',true).';');chmod($router,0600);
 $server=proc_open(['php','-S','127.0.0.1:'.$port,'-t',FS_FOLDER,$router],[0=>['file','/dev/null','r'],1=>['file',$dir.'/server.stdout','a'],2=>['file',$dir.'/server.stderr','a']],$pipes,FS_FOLDER,null,['bypass_shell'=>true]);need(is_resource($server),'own native HTTP server');$proof['serverPid']=proc_get_status($server)['pid'];$ready=false;for($i=0;$i<30;$i++){need(proc_get_status($server)['running'],'own HTTP server exited');$socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,.1);if($socket){fclose($socket);$ready=true;break;}usleep(100000);}need($ready,'native HTTP ready');
 [, $anonymousLogin]=requestHttp('/Login','anonymous');$beforeAnonymous=styleSnapshot();requestHttp('/AdminBeplyPdf','anonymous',['action'=>'use-design','diseno'=>$adminDesign,'multireqtoken'=>formToken($anonymousLogin)]);check($beforeAnonymous===styleSnapshot(),'anonymous valid-form POST cannot change styles');
 loginHttp('e2e_admin','e2e_admin');[$view,$html]=requestHttp('/AdminBeplyPdf','e2e_admin');check($view['http']===200,'admin can open native gallery');
 $preAdminInvalid=styleSnapshot();requestHttp('/AdminBeplyPdf','e2e_admin',['action'=>'use-design','diseno'=>$adminDesign,'multireqtoken'=>'invalid-contract-token']);check($preAdminInvalid===styleSnapshot(),'invalid CSRF prevents writes for allowed administrator');
 [$response]=requestHttp('/AdminBeplyPdf','e2e_admin',['action'=>'use-design','diseno'=>$adminDesign,'multireqtoken'=>formToken($html)]);
 $afterAdmin=styleSnapshot();check($response['http']===200||$response['http']===302,'admin use-design returns native success response');check($before!==$afterAdmin,'positive control admin really changes style data');
 $preGet=styleSnapshot();[$getProbe]=requestHttp('/AdminBeplyPdf?action=use-design','e2e_admin');check($getProbe['http']===403&&$preGet===styleSnapshot(),'administrator GET action denied without style write');
 loginHttp('bpdfhttp_noexport','synthetic-http-only');[$view,$html]=requestHttp('/AdminBeplyPdf','bpdfhttp_noexport');check($view['http']===200,'native read-only role opens gallery');
 $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);check($xp->query('//form[input[@name="action"][@value="use-design"]]')->length===0,'read-only gallery hides write forms');
 check($xp->query('//a[contains(@href,"EditBeplyPdfStyle")]')->length===0,'read-only gallery hides inaccessible configuration');
 $preInvalid=styleSnapshot();requestHttp('/AdminBeplyPdf','bpdfhttp_noexport',['action'=>'use-design','diseno'=>$readerDesign,'multireqtoken'=>'invalid-contract-token']);check($preInvalid===styleSnapshot(),'invalid CSRF prevents style writes');
 [$view,$html]=requestHttp('/AdminBeplyPdf','bpdfhttp_noexport');need($view['http']===200,'fresh native read-only form');$preRestricted=styleSnapshot();
 [$response,$deniedHtml]=requestHttp('/AdminBeplyPdf','bpdfhttp_noexport',['action'=>'use-design','diseno'=>$readerDesign,'multireqtoken'=>formToken($html)]);
 $afterRestricted=styleSnapshot();check($preRestricted===$afterRestricted,'read-only role cannot change style with valid CSRF');check($response['http']===403,'read-only use-design receives native403');

 $deniedDom=new DOMDocument();@$deniedDom->loadHTML($deniedHtml !== '' ? $deniedHtml : '<html></html>');$deniedXpath=new DOMXPath($deniedDom);$deniedHeading=$deniedXpath->query('//h1');check($deniedHeading->length===1&&str_contains($deniedHeading->item(0)->textContent,Tools::trans('access-denied')),'read-only denial uses native access denied heading');
 check(str_contains($deniedDom->textContent,Tools::trans('not-allowed-modify')),'denial explains modify permission');
 $backTarget=null;foreach($deniedXpath->query('//a[@href]')as$link){$target=parse_url($link->getAttribute('href'));parse_str($target['query']??'',$params);if(trim($link->textContent)===Tools::trans('back')&&basename($target['path']??'')==='AdminBeplyPdf'&&!isset($params['action']))$backTarget='/'.$target['path'];}
 check($backTarget!==null,'denial has safe gallery back link');if($backTarget!==null){[$back]=requestHttp($backTarget,'bpdfhttp_noexport');check($back['http']===200,'denied reader returns to gallery without new login');}
 $proof['authorizationProbe']=['readerHttp'=>$response['http'],'readOnlyWriteObserved'=>$preRestricted!==$afterRestricted,'preimageSha256'=>hash('sha256',json_encode($preRestricted)),'postimageSha256'=>hash('sha256',json_encode($afterRestricted))];
 loginHttp('bpdfhttp_writer','synthetic-write-only');[$writerView,$writerHtml]=requestHttp('/AdminBeplyPdf','bpdfhttp_writer');check($writerView['http']===200,'non-admin writer can read gallery');
 $writerDom=new DOMDocument();@$writerDom->loadHTML($writerHtml);$writerXpath=new DOMXPath($writerDom);check($writerXpath->query('//form[input[@name="action"][@value="use-design"]]')->length>0,'non-admin writer sees write forms');check($writerXpath->query('//a[contains(@href,"EditBeplyPdfStyle")]')->length===0,'writer without destination access has no configure link');
 $beforeWriter=styleSnapshot();requestHttp('/AdminBeplyPdf','bpdfhttp_writer',['action'=>'use-design','diseno'=>$readerDesign,'multireqtoken'=>'invalid-contract-token']);check($beforeWriter===styleSnapshot(),'invalid CSRF prevents non-admin writer changes');
 [$writerResponse]=requestHttp('/AdminBeplyPdf','bpdfhttp_writer',['action'=>'use-design','diseno'=>$readerDesign,'multireqtoken'=>formToken($writerHtml)]);check(in_array($writerResponse['http'],[200,302],true)&&$beforeWriter!==styleSnapshot(),'allowed non-admin POST with valid CSRF really changes styles');

 loginHttp('bpdfhttp_configure','synthetic-configure-only');[$configView,$configHtml]=requestHttp('/AdminBeplyPdf','bpdfhttp_configure');need($configView['http']===200,'configure viewer gallery');$configDom=new DOMDocument();@$configDom->loadHTML($configHtml);$configXpath=new DOMXPath($configDom);$configLinks=$configXpath->query('//a[contains(@href,"EditBeplyPdfStyle")]');check($configLinks->length>0,'non-admin destination reader keeps configuration link');check($configXpath->query('//form[input[@name="action"][@value="use-design"]]')->length===0,'configuration access does not show apply forms');
 if($configLinks->length>0){$beforeConfigRead=styleSnapshot();[$configTarget]=requestHttp('/'.ltrim($configLinks->item(0)->getAttribute('href'),'/'),'bpdfhttp_configure');check($configTarget['http']===200&&$beforeConfigRead===styleSnapshot(),'allowed destination reader opens style without writes');}else check(false,'allowed destination reader opens style without writes');
 $beforeConfigPost=styleSnapshot();[$configPost]=requestHttp('/AdminBeplyPdf','bpdfhttp_configure',['action'=>'use-design','diseno'=>$adminDesign,'multireqtoken'=>formToken($configHtml)]);check($configPost['http']===403&&$beforeConfigPost===styleSnapshot(),'configuration access does not authorize applying designs');

} catch(Throwable $error) {$proof['error']=['type'=>get_class($error),'message'=>$error->getMessage()];$failures[]='runtime error';}
finally {
 if(is_resource($server)){proc_terminate($server);proc_close($server);$proof['serverStopped']=true;}
 foreach(array_reverse($owned)as$model)if(!$model->delete())$failures[]='own cleanup '.get_class($model);
 $proof['failures']=$failures;$proof['passed']=count(array_filter($proof['checks']));$proof['failed']=count($failures);file_put_contents($dir.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod($dir.'/proof.json',0600);echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
exit($failures===[]?0:1);
