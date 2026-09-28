<?php
namespace FacturaScripts\Plugins\BeplyPDFStudio\Extension\Model;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyInvoiceCertification;

class FacturaCliente
{
    public function clear(): Closure
    {
        return function (): void {
            $this->bpf_certification = false;
            $this->bpf_previous = 0;
            $this->bpf_guarantee_base = 0;
            $this->bpf_guarantee_percent = 0;
            $this->bpf_guarantee_due = null;
            $this->bpf_guarantee_receipt = null;
        };
    }

    public function saveInsertBefore(): Closure
    {
        return function (): bool {
            // A copied invoice must never inherit a receipt belonging to its source.
            $this->bpf_certification = false;
            $this->bpf_previous = 0;
            $this->bpf_guarantee_base = 0;
            $this->bpf_guarantee_percent = 0;
            $this->bpf_guarantee_due = null;
            $this->bpf_guarantee_receipt = null;
            return true;
        };
    }

    public function test(): Closure
    {
        return function (): bool {
            if ($this->id()) {
                $class = get_class($this);
                $old = new $class();
                if (!$old->loadFromCode($this->id())) {
                    return false;
                }
                foreach (['bpf_certification', 'bpf_previous', 'bpf_guarantee_base', 'bpf_guarantee_percent', 'bpf_guarantee_due', 'bpf_guarantee_receipt'] as $field) {
                    if (!$old->editable && $old->{$field} != $this->{$field}) {
                        Tools::log()->warning('non-editable-document');
                        return false;
                    }
                }
            }
            if (!$this->bpf_certification) {
                return true;
            }
            try {
                BeplyInvoiceCertification::fromInvoice($this);
                return true;
            } catch (\InvalidArgumentException $error) {
                Tools::log()->warning($error->getMessage());
                return false;
            }
        };
    }
}
