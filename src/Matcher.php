<?php

namespace GlpiPlugin\Contractlifecycle;

use Contract;
use ITILCategory;

/**
 * Encontra o contrato que mais se encaixa num chamado: vigente, da entidade do chamado (ou recursivo de uma
 * entidade acima) e que cobre a categoria — pelo ID exato listado nos serviços ou pela raiz (eligible_roots).
 * Empate resolvido pela estratégia global (match_strategy).
 */
class Matcher
{
    public static function rootName(int $itilcategories_id): ?string
    {
        if ($itilcategories_id <= 0) {
            return null;
        }
        $category = ITILCategory::getById($itilcategories_id);
        if (!$category) {
            return null;
        }
        $parts = explode(' > ', (string) $category->fields['completename']);
        return trim($parts[0]);
    }

    /** Raiz da categoria consta na lista de nomes (comparação sem maiúsculas/minúsculas). */
    public static function rootMatches(?string $root, string $lines): bool
    {
        if ($root === null) {
            return false;
        }
        foreach (explode("\n", $lines) as $name) {
            if ($name !== '' && mb_strtolower(trim($name)) === mb_strtolower($root)) {
                return true;
            }
        }
        return false;
    }

    /** @return array[] contratos candidatos (linhas de glpi_contracts) já ordenados */
    public static function candidates(int $entities_id, int $itilcategories_id, ?string $date = null): array
    {
        global $DB;
        if ($itilcategories_id <= 0) {
            return [];
        }
        $date ??= Helpers::today();
        $ancestors = array_map('intval', array_keys(getAncestorsOf('glpi_entities', $entities_id)));

        $where = [
            'is_deleted'  => 0,
            'is_template' => 0,
            'OR' => [
                ['entities_id' => $entities_id],
            ],
        ];
        if ($ancestors !== []) {
            $where['OR'][] = ['entities_id' => $ancestors, 'is_recursive' => 1];
        }

        $root = self::rootName($itilcategories_id);
        $listed = array_flip(Service::contractsWithCategory($itilcategories_id));
        $found = [];
        foreach ($DB->request(['FROM' => Contract::getTable(), 'WHERE' => $where]) as $contract) {
            $data = ContractData::forContract((int) $contract['id']);
            if (!ContractData::isActiveOn($contract, $date, $data)) {
                continue;
            }
            $settings = Settings::forContract((int) $contract['id']);
            $covers = isset($listed[(int) $contract['id']]) || self::rootMatches($root, (string) $settings['eligible_roots']);
            if (!$covers) {
                continue;
            }
            $contract['_limited'] = $settings['franchise_mode'] === 'limited';
            $contract['_end'] = ContractData::endDate($contract, $data) ?? '9999-12-31';
            $found[] = $contract;
        }

        $strategy = Settings::global()['match_strategy'];
        usort($found, function ($a, $b) use ($strategy) {
            $byMode = match ($strategy) {
                'unlimited_first' => (int) $a['_limited'] <=> (int) $b['_limited'],
                'limited_first'   => (int) $b['_limited'] <=> (int) $a['_limited'],
                default           => 0,
            };
            return $byMode ?: ([$a['_end'], (int) $a['id']] <=> [$b['_end'], (int) $b['id']]);
        });
        return $found;
    }

    public static function find(int $entities_id, int $itilcategories_id): int
    {
        $candidates = self::candidates($entities_id, $itilcategories_id);
        return $candidates === [] ? 0 : (int) $candidates[0]['id'];
    }

    /** Sem contrato e a categoria é atendida por Per-Event (raiz elegível no tipo Per-Event com aprovação). */
    public static function needsPerEvent(int $itilcategories_id): bool
    {
        $type = (int) Settings::global()['perevent_contracttypes_id'];
        if ($type <= 0) {
            return false;
        }
        $settings = Settings::forType($type);
        return !empty($settings['requires_approval'])
            && self::rootMatches(self::rootName($itilcategories_id), (string) $settings['eligible_roots']);
    }
}
