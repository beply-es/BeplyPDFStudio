<?php
/** Native, synthetic-only HTTP contract for sales/purchase document export permissions. */
declare(strict_types=1);
if (getenv('BEPDF_SYNTHETIC_HTTP_EXPORT_TEST') !== '1') throw new RuntimeException('Explicit synthetic HTTP test required');
if (!defined('FS_FOLDER')) define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
if (!getenv('BEPDF_HTTP_EXPECTED_DB') || FS_DB_NAME !== getenv('BEPDF_HTTP_EXPECTED_DB')) throw new RuntimeException('Exact synthetic database required');
FacturaScripts\Core\Kernel::init();
FacturaScripts\Core\Plugins::init();
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FormatoDocumento;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
function need(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$owned=[];$documents=[];$server=null;$failures=[];
$dir=sys_get_temp_dir().'/BeplyPdfHttpExport-'.getmypid();need(!file_exists($dir)&&mkdir($dir,0700),'own HTTP artifact directory');
$proof=['runtime'=>'native Core HTTP + Dinamic exporters','database'=>FS_DB_NAME,'requests'=>[],'checks'=>[],'artifactDir'=>$dir];
$proof['sourceHashes']=[];foreach(['Init.php','Extension/Controller/EditController.php','Lib/Export/PDFExport.php','facturascripts.ini','Tests/run-http-export-contract.php']as$file)$proof['sourceHashes'][$file]=hash_file('sha256',FS_FOLDER.'/Plugins/BeplyPDFStudio/'.$file);
function check(bool $ok,string $name):void{global $proof,$failures;$proof['checks'][$name]=$ok;if(!$ok)$failures[]=$name;}
function saveOwned($model,string $label):void{global $owned;need($model->save(),$label);$owned[]=$model;}
function requestHttp(string $path,string $actor,?array $data=null):array{
 global $base,$nonce,$dir,$proof;
 $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>120,CURLOPT_COOKIEJAR=>$dir.'/'.$actor.'.cookies',CURLOPT_COOKIEFILE=>$dir.'/'.$actor.'.cookies']);
 if($data!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
 $raw=curl_exec($ch);need(is_string($raw),'HTTP transport');$info=curl_getinfo($ch);$headers=substr($raw,0,$info['header_size']);$body=substr($raw,$info['header_size']);curl_close($ch);
 need(str_contains(strtolower($headers),strtolower('X-Beply-Contract-Run: '.$nonce)),'HTTP instance binding');
 $file='response-'.count($proof['requests']).'.body';need(file_put_contents($dir.'/'.$file,$body)===strlen($body),'own HTTP evidence');chmod($dir.'/'.$file,0600);
 $row=['path'=>$path,'actor'=>$actor,'method'=>$data===null?'GET':'POST','http'=>$info['http_code'],'contentType'=>$info['content_type'],'pdf'=>str_starts_with($body,'%PDF'),'bodyFile'=>$file,'bodySha256'=>hash('sha256',$body),'exactInstance'=>true];$proof['requests'][]=$row;return [$row,$body];
}
function formToken(string $html):string{$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$values=$xp->query('//input[@name="multireqtoken"]/@value');need($values->length>0,'native login token');return $values->item(0)->nodeValue;}
function pdfText(string $file):string{$p=proc_open(['gs','-q','-dNOPAUSE','-dBATCH','-sDEVICE=txtwrite','-sOutputFile=-',$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);need(is_resource($p),'native PDF text reader');$text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);need(proc_close($p)===0&&is_string($text),'PDF text read failed');return $text;}
function loginHttp(string $actor,string $password):void{[, $html]=requestHttp('/Login',$actor);[$row]=requestHttp('/Login',$actor,['action'=>'login','fsNick'=>$actor,'fsPassword'=>$password,'multireqtoken'=>formToken($html)]);need($row['http']===302,'native login accepted');}
try {
 need(!Tools::settings('default','telemetryinstall')&&!Tools::settings('default','telemetrykey'),'synthetic runtime must not be telemetry enrolled');
 $admin=new User();need($admin->load('e2e_admin')&&$admin->admin&&$admin->enabled,'canonical synthetic admin');
 $user=new User();need(!$user->load('bpdfhttp_noexport'),'synthetic actor must be absent');
 $role=new Role();need(!$role->load('BPDFHTTP'),'synthetic role must be absent');$role->codrole='BPDFHTTP';$role->descripcion='Synthetic PDF export permission contract';saveOwned($role,'own role');
 $user->nick='bpdfhttp_noexport';$user->password=password_hash('synthetic-http-only',PASSWORD_DEFAULT);$user->admin=false;$user->enabled=true;$user->email='pdf-contract@example.test';$user->idempresa=$admin->idempresa;$user->codalmacen=$admin->codalmacen;$user->langcode='es_ES';saveOwned($user,'own restricted actor');
 $membership=new RoleUser();$membership->nick=$user->nick;$membership->codrole=$role->codrole;saveOwned($membership,'own role membership');
 foreach(['EditPedidoCliente','EditPedidoProveedor']as$page){$access=new RoleAccess();$access->codrole=$role->codrole;$access->pagename=$page;$access->allowexport=false;$access->allowupdate=false;$access->allowdelete=false;$access->allowimport=false;$access->onlyownerdata=false;saveOwned($access,'own page permission');$permission=new ControllerPermissions($user,$page);need($permission->allowAccess&&!$permission->allowExport&&!$permission->allowUpdate,'effective role permits reading but denies export');}
 foreach([['Cliente',Cliente::class,PedidoCliente::class],['Proveedor',Proveedor::class,PedidoProveedor::class]]as[$kind,$subjectClass,$documentClass]){
  $subject=new $subjectClass();$subject->nombre='Synthetic HTTP '.$kind;$subject->cifnif='';saveOwned($subject,'own synthetic subject');$doc=new $documentClass();need($doc->setSubject($subject),'native document subject');saveOwned($doc,'own synthetic document');$line=$doc->getNewLine();$line->descripcion='Synthetic HTTP export line';$line->cantidad=1;$line->pvpunitario=10.74;$line->codimpuesto='IVA21';$line->iva=21;need($line->save(),'own native line');$lines=$doc->getLines();need(Calculator::calculate($doc,$lines,true),'native document calculation');need($doc->loadFromCode($doc->id()),'document reload');$documents[$kind]=$doc;
 }
 $format=new FormatoDocumento();$format->nombre='BPDFHTTP_MANUAL';$format->titulo='BPDFHTTP_SELECTED_TITLE';$format->tipodoc='PedidoCliente';$format->idempresa=$documents['Cliente']->idempresa;$format->autoaplicar=false;saveOwned($format,'own manual format');
 foreach(['Cliente','Proveedor']as$suffix){$family='FacturaScripts\\Core\\Lib\\AjaxForms\\'.($suffix==='Cliente'?'SalesController':'PurchasesController');foreach(['Presupuesto','Pedido','Albaran','Factura']as$type){$class='FacturaScripts\\Dinamic\\Controller\\Edit'.$type.$suffix;check(is_subclass_of($class,$family),'native family '.$type.$suffix);}}
 $legacy=new ExportManager();$legacy->newDoc('PDF','Synthetic legacy model',0,'es_ES');try{$result=$legacy->addModelPage($documents['Cliente'],[]);check(is_bool($result),'legacy relative exporter class resolves');}catch(Throwable $error){$proof['legacyError']=['type'=>get_class($error),'message'=>$error->getMessage()];check(false,'legacy relative exporter class resolves');}
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);need(is_resource($socket),'local port admission');$endpoint=stream_socket_get_name($socket,false);$port=(int)substr(strrchr($endpoint,':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;$nonce=bin2hex(random_bytes(16));$router=$dir.'/router.php';file_put_contents($router,'<?php header('.var_export('X-Beply-Contract-Run: '.$nonce,true).'); require '.var_export(FS_FOLDER.'/index.php',true).';');chmod($router,0600);
 $server=proc_open(['php','-S','127.0.0.1:'.$port,'-t',FS_FOLDER,$router],[0=>['file','/dev/null','r'],1=>['file',$dir.'/server.stdout','a'],2=>['file',$dir.'/server.stderr','a']],$pipes,FS_FOLDER,null,['bypass_shell'=>true]);need(is_resource($server),'own native HTTP server');$proof['serverPid']=proc_get_status($server)['pid'];$ready=false;for($i=0;$i<30;$i++){need(proc_get_status($server)['running'],'own HTTP server exited');$socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1);if($socket){fclose($socket);$ready=true;break;}usleep(100000);}need($ready,'native HTTP readiness');
 $paths=[];foreach($documents as$kind=>$doc)$paths[$kind]='/EditPedido'.$kind.'?code='.rawurlencode((string)$doc->id()).'&action=export&option=PDF';
 [$anonymous]=requestHttp($paths['Cliente'],'anonymous');check(!$anonymous['pdf'],'anonymous cannot export');
 [, $login]=requestHttp('/Login','invalid_csrf');requestHttp('/Login','invalid_csrf',['action'=>'login','fsNick'=>'e2e_admin','fsPassword'=>'e2e_admin','multireqtoken'=>'invalid-contract-token']);[$csrf]=requestHttp($paths['Cliente'],'invalid_csrf');check(!$csrf['pdf'],'invalid login CSRF grants no PDF capability');
 loginHttp('e2e_admin','e2e_admin');foreach($paths as$kind=>$path){[$row]=requestHttp($path,'e2e_admin');check($row['http']===200&&$row['pdf'],'admin prints '.$kind);}
 [$manual]=requestHttp('/EditPedidoCliente?code='.rawurlencode((string)$documents['Cliente']->id()).'&action=export','e2e_admin',['option'=>'PDF','idformat'=>$format->id(),'langcode'=>'es_ES']);check($manual['http']===200&&$manual['pdf'],'admin manual format returns PDF');check(str_contains(preg_replace('/\s+/u','',pdfText($dir.'/'.$manual['bodyFile'])),'BPDFHTTP_SELECTED_TITLE'),'selected format title is inside real PDF');
 loginHttp('bpdfhttp_noexport','synthetic-http-only');foreach($paths as$kind=>$path){[, $html]=requestHttp(str_replace('&action=export&option=PDF','',$path),'bpdfhttp_noexport');check(str_contains($html,'Synthetic HTTP export line')&&!str_contains($html,'name="fsPassword"'),'restricted actor can read own '.$kind);[$row]=requestHttp($path,'bpdfhttp_noexport');check($row['http']===403&&!$row['pdf'],'export denied before PDF '.$kind);}
 foreach($documents as$kind=>$doc){$id=$doc->id();$before=serialize([$doc->toArray(),array_map(static fn($line)=>$line->toArray(),$doc->getLines())]);need($doc->loadFromCode($id),'post HTTP reload');check($before===serialize([$doc->toArray(),array_map(static fn($line)=>$line->toArray(),$doc->getLines())]),'HTTP export preserves native '.$kind);}
} catch(Throwable $error) {$proof['error']=['type'=>get_class($error),'message'=>$error->getMessage()];$failures[]='runtime error';}
finally {
 if(is_resource($server)){proc_terminate($server);proc_close($server);$proof['serverStopped']=true;}
 foreach(array_reverse($owned)as$model){if(!$model->delete())$failures[]='own cleanup '.get_class($model);}
 $proof['failures']=$failures;$proof['passed']=count(array_filter($proof['checks']));$proof['failed']=count($failures);file_put_contents($dir.'/proof.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod($dir.'/proof.json',0600);echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
exit($failures===[]?0:1);
