<?php
require __DIR__ . '/bootstrap.php';
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyInvoiceCertification;
function same($want, $got): void { if ($want !== $got) throw new RuntimeException(json_encode([$want,$got])); }
$d = BeplyInvoiceCertification::calculate('10000.00', '12100.00', '20000.00', '5', '10000');
same(3000000, $d['accumulated']);
same(2000000, $d['previous']);
same(1000000, $d['period']);
same(50000, $d['guarantee']);
same(1160000, $d['payable']);
// Already net period: no second deduction; exact cent rounding.
same(1, BeplyInvoiceCertification::calculate('0.10','0.10','0','5','0.10')['guarantee']);
foreach ([['100','121','-1','5','100'], ['100','121','0','101','100'], ['100','121','0','50','1000'], ['100','121','0','NaN','100'], ['100','121','1e5','5','100']] as $args) {
    try { BeplyInvoiceCertification::calculate(...$args); throw new RuntimeException('invalid accepted'); }
    catch (InvalidArgumentException $e) {}
}
echo "PASS certification arithmetic, no double deduction, rounding and invalid inputs\n";
$original = [(object)['idrecibo'=>1,'importe'=>12100,'pagado'=>false]];
same([1=>1160000, 0=>50000], BeplyInvoiceCertification::receiptPlan($original, null, 1210000, 50000));
$pair = [(object)['idrecibo'=>1,'importe'=>11600,'pagado'=>false], (object)['idrecibo'=>2,'importe'=>500,'pagado'=>false]];
same([], BeplyInvoiceCertification::receiptPlan($pair, 2, 1210000, 50000));
$pair[0]->pagado = true;
try { BeplyInvoiceCertification::receiptPlan($pair, 2, 1210000, 60000); throw new RuntimeException('paid receipt modified'); } catch (InvalidArgumentException $e) {}
try { BeplyInvoiceCertification::receiptPlan($pair, 3, 1210000, 50000); throw new RuntimeException('foreign receipt accepted'); } catch (InvalidArgumentException $e) {}
echo "PASS receipt idempotence, paid protection and ownership\n";
