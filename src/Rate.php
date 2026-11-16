<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDropdown;
use Document_Item;
use Log;

/**
 * Tabela de valores Per-Event, versionada: cada mudança de valor é uma nova linha (versão) com "válida a partir de".
 * O contrato Per-Event fica preso à versão vigente quando foi criado; a proposta (PDF) vai na aba Documentos.
 */
class Rate extends CommonDropdown
{
    public static $rightname = Settings::RIGHT;

    public $can_be_translated = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Tabela Per-Event', 'Tabelas Per-Event', $nb, 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-receipt-2';
    }

    public static function canCreate(): bool
    {
        return \Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canDelete(): bool
    {
        return \Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return \Session::haveRight(self::$rightname, UPDATE);
    }

    public function getAdditionalFields()
    {
        return [
            ['name' => 'valid_from', 'label' => __('Válida a partir de', 'contractlifecycle'), 'type' => 'date'],
            ['name' => 'hourly_value', 'label' => __('Valor da hora (R$)', 'contractlifecycle'), 'type' => 'integer', 'min' => 0, 'step' => '0.01'],
            ['name' => 'mult_business', 'label' => __('Multiplicador: horário comercial', 'contractlifecycle'), 'type' => 'integer', 'min' => 0, 'step' => '0.01'],
            ['name' => 'mult_offhours', 'label' => __('Multiplicador: fora do horário comercial', 'contractlifecycle'), 'type' => 'integer', 'min' => 0, 'step' => '0.01'],
            ['name' => 'mult_weekend', 'label' => __('Multiplicador: fins de semana e feriados', 'contractlifecycle'), 'type' => 'integer', 'min' => 0, 'step' => '0.01'],
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Document_Item::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);
        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalize(parent::prepareInputForAdd($input));
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->normalize(parent::prepareInputForUpdate($input));
        if ($input !== false && $this->isUsed() && $this->changesValues($input)) {
            \Session::addMessageAfterRedirect(
                __('Esta versão já é usada por contratos: para mudar valores, crie uma nova versão. Só o nome e o comentário foram salvos.', 'contractlifecycle'),
                false,
                WARNING
            );
            foreach (['valid_from', 'hourly_value', 'mult_business', 'mult_offhours', 'mult_weekend'] as $key) {
                unset($input[$key]);
            }
        }
        return $input;
    }

    private function normalize($input)
    {
        if ($input === false) {
            return false;
        }
        foreach (['hourly_value', 'mult_business', 'mult_offhours', 'mult_weekend'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = max(0, (float) str_replace(',', '.', (string) $input[$key]));
            }
        }
        if (array_key_exists('valid_from', $input) && empty($input['valid_from'])) {
            $input['valid_from'] = Helpers::today();
        }
        return $input;
    }

    private function changesValues(array $input): bool
    {
        foreach (['valid_from', 'hourly_value', 'mult_business', 'mult_offhours', 'mult_weekend'] as $key) {
            if (isset($input[$key]) && (string) (float) $input[$key] !== (string) (float) $this->fields[$key] && $key !== 'valid_from') {
                return true;
            }
            if ($key === 'valid_from' && isset($input[$key]) && $input[$key] !== $this->fields[$key]) {
                return true;
            }
        }
        return false;
    }

    public function isUsed(): bool
    {
        global $DB;
        return $DB->request([
            'COUNT' => 'c',
            'FROM'  => ContractData::getTable(),
            'WHERE' => ['plugin_contractlifecycle_rates_id' => $this->getID()],
        ])->current()['c'] > 0;
    }

    /** Versão vigente numa data (a mais recente com valid_from <= data). */
    public static function currentId(?string $date = null): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['valid_from' => ['<=', $date ?? Helpers::today()]],
            'ORDER'  => ['valid_from DESC', 'id DESC'],
            'LIMIT'  => 1,
        ])->current();
        return (int) ($row['id'] ?? 0);
    }

    public static function multiplierFor(array $rate, string $band): float
    {
        return (float) match ($band) {
            Calculator::BAND_OFFHOURS => $rate['mult_offhours'],
            Calculator::BAND_WEEKEND  => $rate['mult_weekend'],
            default                   => $rate['mult_business'],
        };
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => 101, 'table' => self::getTable(), 'field' => 'valid_from', 'name' => __('Válida a partir de', 'contractlifecycle'), 'datatype' => 'date'];
        $tab[] = ['id' => 102, 'table' => self::getTable(), 'field' => 'hourly_value', 'name' => __('Valor da hora (R$)', 'contractlifecycle'), 'datatype' => 'decimal'];
        $tab[] = ['id' => 103, 'table' => self::getTable(), 'field' => 'mult_business', 'name' => __('Multiplicador: horário comercial', 'contractlifecycle'), 'datatype' => 'decimal'];
        $tab[] = ['id' => 104, 'table' => self::getTable(), 'field' => 'mult_offhours', 'name' => __('Multiplicador: fora do horário comercial', 'contractlifecycle'), 'datatype' => 'decimal'];
        $tab[] = ['id' => 105, 'table' => self::getTable(), 'field' => 'mult_weekend', 'name' => __('Multiplicador: fins de semana e feriados', 'contractlifecycle'), 'datatype' => 'decimal'];
        return $tab;
    }
}
