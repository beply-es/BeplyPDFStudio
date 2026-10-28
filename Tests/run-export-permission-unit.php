<?php
/** Focused extension contract; native HTTP coverage lives in run-http-export-contract.php. */
declare(strict_types=1);

namespace FacturaScripts\Core {
    class Response { public const HTTP_FORBIDDEN = 403; }
}
namespace FacturaScripts\Core\Lib\AjaxForms {
    trait DocumentControllerProbe {
        public $permissions;
        public $status = 200;
        public $template = '';
        public function getMainViewName() { return 'main'; }
        public function response() { return $this; }
        public function setHttpCode($code) { $this->status = $code; return $this; }
        public function setTemplate($template) { $this->template = $template; }
    }
    class SalesController { use DocumentControllerProbe; }
    class PurchasesController { use DocumentControllerProbe; }
}
namespace {
    require __DIR__ . '/../Extension/Controller/EditController.php';
    $extension = new \FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller\EditController();
    function expect($condition, $message): void { if (!$condition) throw new \RuntimeException($message); }
    foreach ([\FacturaScripts\Core\Lib\AjaxForms\SalesController::class, \FacturaScripts\Core\Lib\AjaxForms\PurchasesController::class] as $class) {
        $controller = new $class();
        foreach ([false, true] as $allowed) {
            $controller->permissions = (object) ['allowExport' => $allowed];
            $main = (object) ['settings' => ['btnPrint' => true]];
            $other = (object) ['settings' => ['btnPrint' => true]];
            $load = $extension->loadData()->bindTo($controller, $controller);
            $load('main', $main);
            $load('other', $other);
            expect($main->settings['btnPrint'] === $allowed, 'main print matches export permission');
            expect($other->settings['btnPrint'] === true, 'other view unchanged');
            $controller->status = 200;
            $controller->template = '';
            $result = $extension->execPreviousAction()->bindTo($controller, $controller)('export');
            expect($result === $allowed, 'export stops only when denied');
            expect($controller->status === ($allowed ? 200 : 403), 'denial is HTTP 403');
            expect($allowed || $controller->template === 'BeplyPdfExportDenied', 'denial selects native framed view');
        }
        $controller->permissions = null;
        $view = (object) ['settings' => ['btnPrint' => true]];
        $extension->loadData()->bindTo($controller, $controller)('main', $view);
        expect($view->settings['btnPrint'] === false, 'missing permissions hide print');
        expect($extension->execPreviousAction()->bindTo($controller, $controller)('export') === false, 'missing permissions deny export');
        expect($extension->execPreviousAction()->bindTo($controller, $controller)('edit') === true, 'other actions continue');
    }
    echo "export permission extension: PASS\n";
}
