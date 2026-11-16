<?php

namespace GlpiPlugin\Contractlifecycle;

use ContractType;
use CronTask;
use DBConnection;
use Migration;
use Profile as CoreProfile;
use ProfileRight;

/**
 * Criação/atualização e remoção do schema. install() também é o upgrade: tudo é idempotente.
 */
class Install
{
    public const TABLES = [
        'glpi_plugin_contractlifecycle_settings',
        'glpi_plugin_contractlifecycle_contractdatas',
        'glpi_plugin_contractlifecycle_services',
        'glpi_plugin_contractlifecycle_focals',
        'glpi_plugin_contractlifecycle_rates',
        'glpi_plugin_contractlifecycle_ledgers',
        'glpi_plugin_contractlifecycle_ticketstates',
        'glpi_plugin_contractlifecycle_formactions',
        'glpi_plugin_contractlifecycle_formruns',
    ];

    public static function install(Migration $migration): bool
    {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $opts      = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        $schema = [
            // Configuração em 3 níveis: itemtype = 'global' | ContractType | Contract. NULL = herda do nível acima.
            'glpi_plugin_contractlifecycle_settings' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL DEFAULT 'global',
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                " . Settings::columnsSql($sign) . "
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `item` (`itemtype`, `items_id`)",

            // Dados próprios do contrato (não herdáveis)
            'glpi_plugin_contractlifecycle_contractdatas' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `end_date` date DEFAULT NULL,
                `franchise_hours` decimal(10,2) DEFAULT NULL,
                `plugin_contractlifecycle_rates_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id_origin` int {$sign} NOT NULL DEFAULT '0',
                `renewal_count` int NOT NULL DEFAULT '0',
                `notices_sent` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `contracts_id` (`contracts_id`),
                KEY `end_date` (`end_date`),
                KEY `plugin_contractlifecycle_rates_id` (`plugin_contractlifecycle_rates_id`)",

            // Serviços (categorias ITIL) cobertos pelo contrato
            'glpi_plugin_contractlifecycle_services' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `itilcategories_id` int {$sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`contracts_id`, `itilcategories_id`),
                KEY `itilcategories_id` (`itilcategories_id`)",

            // Pontos focais da entidade por papel
            'glpi_plugin_contractlifecycle_focals' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `role` varchar(20) NOT NULL DEFAULT '',
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `role`, `users_id`),
                KEY `users_id` (`users_id`)",

            // Tabela de valores Per-Event (versionada: uma linha por versão)
            'glpi_plugin_contractlifecycle_rates' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '1',
                `valid_from` date DEFAULT NULL,
                `hourly_value` decimal(20,4) NOT NULL DEFAULT '0.0000',
                `mult_business` decimal(6,3) NOT NULL DEFAULT '1.000',
                `mult_offhours` decimal(6,3) NOT NULL DEFAULT '1.500',
                `mult_weekend` decimal(6,3) NOT NULL DEFAULT '2.000',
                `comment` text,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `valid_from` (`valid_from`)",

            // Extrato: toda entrada e saída de horas do contrato. Saldo = SUM(direction * debited_seconds)
            'glpi_plugin_contractlifecycle_ledgers' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `entry_type` varchar(20) NOT NULL DEFAULT '',
                `itemtype` varchar(100) DEFAULT NULL,
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `users_id_editor` int {$sign} NOT NULL DEFAULT '0',
                `date` timestamp NULL DEFAULT NULL,
                `real_seconds` int NOT NULL DEFAULT '0',
                `debited_seconds` int NOT NULL DEFAULT '0',
                `direction` tinyint NOT NULL DEFAULT '-1',
                `band` varchar(20) DEFAULT NULL,
                `unit_value` decimal(20,4) NOT NULL DEFAULT '0.0000',
                `multiplier` decimal(6,3) NOT NULL DEFAULT '1.000',
                `amount` decimal(20,4) NOT NULL DEFAULT '0.0000',
                `ticketcosts_id` int {$sign} NOT NULL DEFAULT '0',
                `reverses_id` int {$sign} NOT NULL DEFAULT '0',
                `is_reversed` tinyint NOT NULL DEFAULT '0',
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `contracts_id` (`contracts_id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `entry_type` (`entry_type`),
                KEY `date` (`date`),
                KEY `is_reversed` (`is_reversed`)",

            // Estado do chamado em relação ao contrato
            'glpi_plugin_contractlifecycle_ticketstates' => "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `contracts_id` int {$sign} NOT NULL DEFAULT '0',
                `link_mode` varchar(20) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT '',
                `needs_realloc` tinyint NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tickets_id` (`tickets_id`),
                KEY `contracts_id` (`contracts_id`),
                KEY `status` (`status`)",
        ];

        // Automação de formulários: ação + mapeamento de perguntas por formulário, e o registro de cada execução
        $schema['glpi_plugin_contractlifecycle_formactions'] = "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `forms_forms_id` int {$sign} NOT NULL DEFAULT '0',
                `action` varchar(30) NOT NULL DEFAULT '',
                `is_active` tinyint NOT NULL DEFAULT '1',
                `mapping` longtext,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `forms_forms_id` (`forms_forms_id`)";
        $schema['glpi_plugin_contractlifecycle_formruns'] = "
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `forms_answerssets_id` int {$sign} NOT NULL DEFAULT '0',
                `forms_forms_id` int {$sign} NOT NULL DEFAULT '0',
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `action` varchar(30) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT '',
                `report` longtext,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `forms_answerssets_id` (`forms_answerssets_id`),
                KEY `forms_forms_id` (`forms_forms_id`),
                KEY `tickets_id` (`tickets_id`)";

        foreach ($schema as $table => $columns) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery("CREATE TABLE `{$table}` ({$columns}) {$opts}");
            }
        }

        // 1.1.0: contrato criado pelo Onboard só começa a valer no fechamento do chamado
        $cd = 'glpi_plugin_contractlifecycle_contractdatas';
        if (!$DB->fieldExists($cd, 'pending_activation')) {
            $migration->addField($cd, 'pending_activation', 'bool', ['value' => 0]);
            $migration->addField($cd, 'planned_begin', 'date');
            $migration->addField($cd, 'planned_end', 'date');
            $migration->addKey($cd, 'pending_activation');
        }

        // Upgrade: colunas de configuração acrescentadas em versões futuras
        foreach (Settings::definitions() as $key => $def) {
            if (!$DB->fieldExists('glpi_plugin_contractlifecycle_settings', $key)) {
                $migration->addField('glpi_plugin_contractlifecycle_settings', $key, Settings::columnType($def, $sign));
            }
        }
        $migration->executeMigration();

        self::installDefaults();
        self::installRights();
        CronTask::register(Cron::class, 'lifecycle', DAY_TIMESTAMP, [
            'comment' => __('Avisos de renovação, renovação automática e encerramento de contratos', 'contractlifecycle'),
            'mode'    => CronTask::MODE_EXTERNAL,
            'hourmin' => 6,
            'hourmax' => 8,
        ]);
        Notifications::install();

        return true;
    }

    /** Configuração global e os três tipos padrão (só cria o que falta). */
    private static function installDefaults(): void
    {
        $global = Settings::getRow('global', 0);
        if ($global === null) {
            Settings::saveRow('global', 0, Settings::globalDefaults());
        }

        $types = [
            'gestao'  => ['name' => 'Gestão', 'settings' => [
                'franchise_mode' => 'unlimited', 'auto_renewal' => 1, 'notice_days' => '90,60,30',
            ]],
            'horas'   => ['name' => 'Banco de Horas', 'settings' => [
                'franchise_mode' => 'limited', 'auto_renewal' => 0,
                'min_business' => 15, 'min_offhours' => 60, 'min_weekend' => 60,
            ]],
            'perevent' => ['name' => 'Per-Event', 'settings' => [
                'franchise_mode' => 'unlimited', 'auto_renewal' => 0, 'billing_money' => 1,
                'requires_approval' => 1, 'eligible_roots' => "IT Support\nIT Support per event",
                'min_business' => 60, 'min_offhours' => 60, 'min_weekend' => 60,
            ]],
        ];

        $type = new ContractType();
        foreach ($types as $code => $def) {
            if ($type->getFromDBByCrit(['name' => $def['name']])) {
                $id = $type->getID();
            } else {
                $id = $type->add(['name' => $def['name'], 'comment' => __('Criado pelo plugin Ciclo de vida de contratos', 'contractlifecycle')]);
            }
            if ($id && Settings::getRow(ContractType::class, $id) === null) {
                Settings::saveRow(ContractType::class, $id, $def['settings']);
            }
            if ($code === 'perevent' && $id) {
                $g = Settings::getRow('global', 0) ?? [];
                if (empty($g['perevent_contracttypes_id'])) {
                    Settings::saveRow('global', 0, ['perevent_contracttypes_id' => $id] + $g);
                }
            }
        }

        // depois dos tipos: os padrões dos formulários apontam para Gestão e Banco de Horas
        self::installFormDefaults();
    }

    /**
     * Padrões da automação de formulários, achados pelo nome no ambiente (só preenche o que está vazio):
     * entidade pai "Clientes", raiz "Catálogo Geral", tipo padrão Gestão (IT Support → Banco de Horas),
     * perfis Master/Técnico/Financeiro.
     */
    private static function installFormDefaults(): void
    {
        global $DB;
        $g = Settings::getRow('global', 0) ?? [];
        $byName = function (string $table, string $name, array $extra = []) use ($DB): int {
            $row = $DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => ['name' => $name] + $extra, 'LIMIT' => 1])->current();
            return (int) ($row['id'] ?? 0);
        };
        $changed = false;
        if (empty($g['onboard_parent_entity'])) {
            $g['onboard_parent_entity'] = $byName('glpi_entities', 'Clientes');
            $changed = true;
        }
        if (empty($g['onboard_catalog_root'])) {
            $g['onboard_catalog_root'] = $byName('glpi_itilcategories', 'Catálogo Geral', ['itilcategories_id' => 0]);
            $changed = true;
        }
        if (empty($g['onboard_default_type'])) {
            $g['onboard_default_type'] = $byName('glpi_contracttypes', 'Gestão');
            $changed = true;
        }
        if (empty($g['onboard_type_map'])) {
            $horas = $byName('glpi_contracttypes', 'Banco de Horas');
            $g['onboard_type_map'] = $horas ? ['IT Support' => $horas] : [];
            $changed = true;
        }
        if (empty($g['onboard_profiles'])) {
            $g['onboard_profiles'] = array_filter([
                'contract'  => $byName('glpi_profiles', 'Master'),
                'technical' => $byName('glpi_profiles', 'Técnico'),
                'financial' => $byName('glpi_profiles', 'Financeiro'),
            ]);
            $changed = true;
        }
        if ($changed) {
            Settings::saveRow('global', 0, $g);
        }
    }

    /** Direitos: tudo para quem administra a configuração; leitura do extrato para quem lê contratos. */
    private static function installRights(): void
    {
        global $DB;

        // só os direitos que ainda não existem (addProfileRights não é idempotente: falha no upgrade)
        $missing = array_filter(
            [Settings::RIGHT, Ledger::RIGHT],
            fn ($right) => countElementsInTable(ProfileRight::getTable(), ['name' => $right]) === 0
        );
        if ($missing !== []) {
            ProfileRight::addProfileRights(array_values($missing));
        }

        foreach ($DB->request(['FROM' => CoreProfile::getTable()]) as $profile) {
            $current = ProfileRight::getProfileRights($profile['id'], ['config', 'contract', Settings::RIGHT, Ledger::RIGHT]);
            $is_admin = ((int) ($current['config'] ?? 0) & UPDATE) === UPDATE;
            $reads_contract = ((int) ($current['contract'] ?? 0) & READ) === READ;
            $new = [];
            if ((int) ($current[Settings::RIGHT] ?? 0) === 0) {
                $new[Settings::RIGHT] = $is_admin ? (READ | UPDATE) : ($reads_contract ? READ : 0);
            }
            if ((int) ($current[Ledger::RIGHT] ?? 0) === 0) {
                $new[Ledger::RIGHT] = $is_admin ? (READ | CREATE | UPDATE) : ($reads_contract ? READ : 0);
            }
            if ($new !== []) {
                ProfileRight::updateProfileRights($profile['id'], $new);
            }
        }
    }

    public static function uninstall(): bool
    {
        global $DB;

        foreach (self::TABLES as $table) {
            if ($DB->tableExists($table)) {
                $DB->dropTable($table);
            }
        }
        ProfileRight::deleteProfileRights([Settings::RIGHT, Ledger::RIGHT]);
        CronTask::unregister('contractlifecycle');
        Notifications::uninstall();
        // Os tipos de contrato criados ficam: podem estar em uso por contratos.
        return true;
    }
}
