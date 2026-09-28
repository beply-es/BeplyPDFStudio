<?php
/** Run only in an isolated synthetic test instance. Never against a customer database. */
if (getenv('BEPDF_SYNTHETIC_CERTIFICATION_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated synthetic runtime required');
}
define('FS_FOLDER', dirname(__DIR__, 3));
require FS_FOLDER . '/vendor/autoload.php';
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
(new \FacturaScripts\Plugins\BeplyPDFStudio\Init())->init();
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfCertificationExtension;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Service\InvoiceCertificationService;
function check($condition, string $message): void {
    if (!$condition) {
        foreach (Tools::log()->read() as $log) echo json_encode($log)."\n";
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}
$user = new User(); check($user->loadFromCode('certtest'), 'synthetic user'); Session::set('user', $user);
$tax = new Impuesto();
$tax->codimpuesto='CERT21';$tax->descripcion='Synthetic 21%';$tax->iva=21;check($tax->save(), 'tax');
$customer = new Cliente(); $customer->nombre='CERTIFICATION TEST ONLY';$customer->cifnif=''; check($customer->save(), 'customer');
$invoice = new FacturaCliente();
try {
    foreach (\FacturaScripts\Dinamic\Model\EstadoDocumento::all([new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipodoc','FacturaCliente'),new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('editable',true)]) as $state) { $invoice->idestado=$state->idestado;break; }
    $invoice->setSubject($customer);check($invoice->save(), 'invoice');
    $line=$invoice->getNewLine();$line->descripcion='Trabajo del período';$line->cantidad=1;$line->pvpunitario=10000;$line->codimpuesto='CERT21';$line->iva=21;
    $lines=[$line];check(Calculator::calculate($invoice,$lines,true),'real calculator');
    check((float)$invoice->neto===10000.0 && (float)$invoice->total===12100.0,'10000 base / 12100 total');
    $input=['enabled'=>'1','previous'=>'20000','percent'=>'5','base'=>'10000','due'=>'2027-09-28','witness'=>InvoiceCertificationService::witness($invoice)];
    try { InvoiceCertificationService::save((int)$invoice->id(),$input,$user->nick); } catch (\Throwable $e) { foreach(Tools::log()->read() as $log) echo json_encode($log)."\n"; throw $e; }
    $invoice->loadFromCode($invoice->id());
    $receipts=$invoice->getReceipts();$amounts=array_map(static fn($r)=>(float)$r->importe,$receipts);sort($amounts);
    check($amounts === [500.0,11600.0],'two real receipts sum to fiscal total');
    check((float)$invoice->neto===10000.0 && (float)$invoice->total===12100.0,'fiscal totals preserved');
    $firstIds=array_map(static fn($r)=>$r->idrecibo,$receipts);
    $input['witness']=InvoiceCertificationService::witness($invoice);
    InvoiceCertificationService::save((int)$invoice->id(),$input,$user->nick);
    $invoice->loadFromCode($invoice->id());
    check($firstIds === array_map(static fn($r)=>$r->idrecibo,$invoice->getReceipts()),'idempotent no duplicate receipts');
    $input['witness']='stale';
    try { InvoiceCertificationService::save((int)$invoice->id(),$input,$user->nick);check(false,'stale rejected'); }
    catch (InvalidArgumentException $e) { check(true,'stale preimage rejected'); }
    $config=new BeplyPdfConfig();$config->showCertificationSettlement=true;
    $blocks=(new BeplyPdfCertificationExtension())->blocks(new BeplyPdfDocumentContext($config,$invoice));
    check(count($blocks)===1 && str_contains($blocks[0]->html,'A deducir: certificaciones anteriores') && str_contains($blocks[0]->html,'Líquido a abonar'),'PDF block and labels');
    $oldWitness=InvoiceCertificationService::witness($invoice);
    $input['witness']=$oldWitness; $input['percent']='101';
    try { InvoiceCertificationService::save((int)$invoice->id(),$input,$user->nick); check(false,'invalid percentage rejected'); }
    catch (InvalidArgumentException $error) { $invoice->loadFromCode($invoice->id());check($oldWitness===InvoiceCertificationService::witness($invoice),'invalid change fully rolled back'); }
    $savedReceipt=$invoice->bpf_guarantee_receipt;$invoice->bpf_guarantee_receipt=999999;
    try { (new BeplyPdfCertificationExtension())->blocks(new BeplyPdfDocumentContext($config,$invoice)); check(false,'mismatched receipt refused'); }
    catch (\FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfInconsistentDocumentException $error) { check(true,'mismatched receipt refuses PDF'); }
    $invoice->bpf_guarantee_receipt=$savedReceipt;
    $copy = new FacturaCliente();
    $copy->idestado = $invoice->idestado;
    $copy->setSubject($customer);
    $copy->bpf_certification = true; $copy->bpf_previous = 20000;
    $copy->bpf_guarantee_receipt = $savedReceipt;
    check($copy->save(), 'copy saved');
    $copy->loadFromCode($copy->id());
    check(!$copy->bpf_certification && !$copy->bpf_guarantee_receipt && !$copy->bpf_previous, 'copy does not inherit settlement or foreign receipt');
    check($copy->delete(), 'copy cleanup');
    new \FacturaScripts\Dinamic\Model\RoleUser();
    new \FacturaScripts\Dinamic\Model\RoleAccess();
    $reader = new User(); $reader->nick = 'certreader'; $reader->admin = false;
    $reader->setPassword('SyntheticLocalOnly2026'); check($reader->save(), 'restricted synthetic user');
    $input['percent'] = '6'; $input['witness'] = InvoiceCertificationService::witness($invoice);
    try { InvoiceCertificationService::save((int)$invoice->id(), $input, $reader->nick); check(false, 'restricted save rejected'); }
    catch (InvalidArgumentException $error) {
        $invoice->loadFromCode($invoice->id());
        check($input['witness'] === InvoiceCertificationService::witness($invoice), 'no write without invoice and receipt permissions');
    }
    check($reader->delete(), 'restricted user cleanup');
    $draftStatus = $invoice->idestado;
    foreach (\FacturaScripts\Dinamic\Model\EstadoDocumento::all([new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipodoc','FacturaCliente'),new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('editable',false)]) as $state) { $issuedStatus=$state->idestado;break; }
    check(isset($issuedStatus), 'issued state exists');
    $invoice->idestado = $issuedStatus; $invoice->bpf_guarantee_receipt = 999999;
    check(!$invoice->save(), 'cannot lock invoice with inconsistent guarantee');
    $invoice->loadFromCode($invoice->id());
    $invoice->idestado = $issuedStatus; check($invoice->save(), 'consistent invoice can be locked');
    $invoice->loadFromCode($invoice->id());
    $invoice->bpf_previous = 25000; check(!$invoice->save(), 'issued metadata cannot change');
    $invoice->loadFromCode($invoice->id());
    $input['witness'] = InvoiceCertificationService::witness($invoice);
    try { InvoiceCertificationService::save((int)$invoice->id(), $input, $user->nick); check(false, 'locked service rejected'); }
    catch (InvalidArgumentException $error) { check(true, 'service rejects locked document'); }
    $invoice->idestado = $draftStatus; check($invoice->save(), 'synthetic fixture unlock for cleanup');
    file_put_contents('/tmp/certification-test-invoice-id',(string)$invoice->id());
    // Keep only on request so an operator can inspect the actual PDF before cleanup.
    if (getenv('BEPDF_KEEP_SYNTHETIC_FIXTURE') === '1') { echo "FIXTURE " . $invoice->id() . "\n"; exit(0); }
} finally {
    if (getenv('BEPDF_KEEP_SYNTHETIC_FIXTURE') !== '1') {
        if ($invoice->id()) { check($invoice->delete(),'invoice cleanup'); }
        check($customer->delete(),'customer cleanup');
    }
}
