<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Service\InvoiceCertificationService;

class EditFacturaCliente
{
    public function createViews(): Closure
    {
        return function (): void {
            $this->addHtmlView('BeplyCertification', 'BeplyCertification', 'FacturaCliente', 'Certificaciones y garantías', 'fa-solid fa-building');
        };
    }

    public function loadData(): Closure
    {
        return function ($name, $view): void {
            if ($name === 'BeplyCertification') {
                $view->loadData($this->getModel()->id());
            }
        };
    }

    public function beplyCertificationWitness(): Closure
    {
        return function (): string {
            return InvoiceCertificationService::witness($this->getModel());
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action): bool {
            if ($action !== 'beply-save-certification') {
                return true;
            }
            $invoice = $this->getModel();
            $receiptsPermission = new ControllerPermissions($this->user, 'EditReciboCliente');
            if ($this->request->method() !== 'POST' || !$this->permissions->allowUpdate
                || !$receiptsPermission->allowAccess || !$receiptsPermission->allowUpdate
                || !$invoice->id() || !$this->checkOwnerData($invoice)) {
                Tools::log()->warning('not-allowed-modify');
                return true;
            }
            if (!$this->validateFormToken()) {
                return true;
            }
            try {
                InvoiceCertificationService::save((int) $invoice->id(), $this->request->request->all(), $this->user->nick);
                Tools::log()->notice('record-updated-correctly');
                $this->redirect($invoice->url() . '&activetab=BeplyCertification');
                return false;
            } catch (\InvalidArgumentException $error) {
                Tools::log()->warning($error->getMessage());
            } catch (\Throwable $error) {
                Tools::log()->error('No se ha podido confirmar el guardado. Recarga y revisa la factura y sus recibos antes de reintentar.');
            }
            return true;
        };
    }
}
