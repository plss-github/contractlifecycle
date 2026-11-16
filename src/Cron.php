<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use Contract;
use CronTask;
use NotificationEvent;

/**
 * Ação automática diária:
 *  - avisos de renovação (ex.: 90/60/30 dias) para contratos com renovação automática;
 *  - renovação automática ao passar da data final (estende pela duração do contrato; na franquia limitada
 *    pode recarregar a franquia);
 * Contratos vencidos sem renovação simplesmente deixam de ser encontrados pelo vínculo.
 */
class Cron extends CommonDBTM
{
    public static function cronInfo($name)
    {
        return ['description' => __('Ciclo de vida de contratos: avisos e renovação automática', 'contractlifecycle')];
    }

    public static function cronLifecycle(CronTask $task): int
    {
        $result = self::run(Helpers::today());
        $task->addVolume($result['notices'] + $result['renewals']);
        $task->log(sprintf('%d aviso(s), %d renovação(ões)', $result['notices'], $result['renewals']));
        return ($result['notices'] + $result['renewals']) > 0 ? 1 : 0;
    }

    /**
     * @param int[]|null $only_contracts restringe a contratos específicos (testes)
     * @return array{notices:int, renewals:int}
     */
    public static function run(string $today, ?array $only_contracts = null): array
    {
        global $DB;
        $out = ['notices' => 0, 'renewals' => 0];
        $where = ['is_deleted' => 0, 'is_template' => 0];
        if ($only_contracts !== null) {
            $where['id'] = $only_contracts ?: [0];
        }
        $iterator = $DB->request([
            'FROM'  => Contract::getTable(),
            'WHERE' => $where,
        ]);
        foreach ($iterator as $contract) {
            $id = (int) $contract['id'];
            $settings = Settings::forContract($id);
            if (empty($settings['auto_renewal'])) {
                continue;
            }
            $data = ContractData::forContract($id);
            $end = ContractData::endDate($contract, $data);
            if ($end === null) {
                continue;
            }
            if ($end < $today) {
                $out['renewals'] += self::renew($contract, $data, $settings, $today);
                $data = ContractData::forContract($id);
                $end = ContractData::endDate($contract, $data);
            }
            $out['notices'] += self::notice($contract, $data, $settings, $end, $today);
        }
        return $out;
    }

    /** Envia o aviso do maior limiar já atingido e ainda não enviado nesta vigência. */
    private static function notice(array $contract, array $data, array $settings, string $end, string $today): int
    {
        $days_left = (int) floor((strtotime($end) - strtotime($today)) / 86400);
        if ($days_left < 0) {
            return 0;
        }
        $thresholds = array_filter(array_map('intval', explode(',', (string) $settings['notice_days'])), fn ($d) => $d > 0);
        rsort($thresholds);
        $sent = json_decode((string) ($data['notices_sent'] ?? ''), true) ?: [];
        $sent_now = $sent[$end] ?? [];

        $reached = array_values(array_filter($thresholds, fn ($d) => $days_left <= $d && !in_array($d, $sent_now, true)));
        if ($reached === []) {
            return 0;
        }
        // contrato cadastrado tarde (ex.: faltam 25 dias): envia só o menor limiar atingido e marca os maiores
        $sent[$end] = array_values(array_unique(array_merge($sent_now, $reached)));
        ContractData::upsert((int) $contract['id'], ['notices_sent' => json_encode($sent)]);

        $item = new ContractData();
        $row = ContractData::forContract((int) $contract['id']);
        $item->getFromDB((int) $row['id']);
        NotificationEvent::raiseEvent(NotificationTargetContractData::EVENT_RENEWAL_NOTICE, $item, [
            'entities_id' => (int) $contract['entities_id'],
            'contract'    => $contract,
            'settings'    => $settings,
            'days_left'   => $days_left,
        ]);
        return 1;
    }

    /** Renova até cobrir a data de hoje (renovação automática). */
    private static function renew(array $contract, array $data, array $settings, string $today): int
    {
        $count = 0;
        $end = ContractData::endDate($contract, $data);
        while ($end !== null && $end < $today && $count < 50) {
            $end = self::renewOnce($contract, $settings, '')['end'];
            $count++;
        }
        return $count;
    }

    /**
     * Uma renovação: estende a vigência pela duração do contrato (12 meses se não houver) a partir da data final
     * (ou de hoje, se não houver data final) e, na franquia limitada, recarrega a franquia se configurado.
     *
     * @return array{end: string, recharged: int}
     */
    public static function renewOnce(array $contract, array $settings, string $comment): array
    {
        $id = (int) $contract['id'];
        $data = ContractData::forContract($id);
        $months = (int) $contract['duration'] > 0 ? (int) $contract['duration'] : 12;
        $from = ContractData::endDate($contract, $data) ?? Helpers::today();
        $end = (new \DateTime($from))->modify('+' . $months . ' months')->format('Y-m-d');
        $recharged = 0;
        if ($settings['franchise_mode'] === 'limited' && !empty($settings['renewal_recharge']) && $data['franchise_hours'] !== null) {
            $recharged = (int) round((float) $data['franchise_hours'] * 3600);
            Ledger::credit($id, Ledger::TYPE_RENEWAL, $recharged,
                $comment !== '' ? $comment : sprintf(__('Renovação automática até %s', 'contractlifecycle'), Helpers::date($end)));
        }
        ContractData::upsert($id, [
            'end_date'      => $end,
            'renewal_count' => (int) $data['renewal_count'] + 1,
        ]);
        return ['end' => $end, 'recharged' => $recharged];
    }
}
