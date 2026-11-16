<?php

namespace GlpiPlugin\Contractlifecycle;

use Calendar;
use CommonDBTM;
use CommonGLPI;
use Contract;
use ContractType;
use Glpi\Application\View\TemplateRenderer;
use Group;
use Html;
use Session;

/**
 * Configuração em três níveis: global → tipo de contrato → contrato.
 * Cada chave NULL num nível herda do nível acima; o global tem sempre valor.
 *
 * Os comportamentos são independentes entre si (franquia, renovação, cobrança, aprovação...), então um tipo novo
 * é só outra combinação deles — ex.: "banco de horas com renovação automática".
 */
class Settings extends CommonDBTM
{
    public const RIGHT = 'plugin_contractlifecycle_config';

    public static $rightname = self::RIGHT;

    public const LEVEL_GLOBAL = 'global';

    public const ROLES = ['contract', 'financial', 'technical'];

    public static function getTypeName($nb = 0)
    {
        return __('Regras do contrato', 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-adjustments-horizontal';
    }

    /** Áreas do catálogo modelo (filhos diretos da raiz), ex.: Cloud, Edge, IT Care, IT Support. */
    public static function catalogAreas(): array
    {
        global $DB;
        $root = (int) (self::getRow('global', 0)['onboard_catalog_root'] ?? 0);
        $out = [];
        if ($root > 0) {
            foreach ($DB->request(['SELECT' => 'name', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['itilcategories_id' => $root], 'ORDER' => 'name']) as $row) {
                $out[$row['name']] = $row['name'];
            }
        }
        return $out;
    }

    public static function roleLabels(): array
    {
        return [
            'contract'  => __('Ponto focal (contrato)', 'contractlifecycle'),
            'financial' => __('Ponto focal (financeiro)', 'contractlifecycle'),
            'technical' => __('Ponto focal (técnico)', 'contractlifecycle'),
        ];
    }

    /**
     * Definição de todas as chaves.
     * type: select | bool | int | time | text | lines | multi | dropdown
     * tab: sub-aba do formulário; scope: all (3 níveis) | global (só configuração geral)
     */
    public static function definitions(): array
    {
        $days = [
            1 => __('Segunda', 'contractlifecycle'), 2 => __('Terça', 'contractlifecycle'), 3 => __('Quarta', 'contractlifecycle'), 4 => __('Quinta', 'contractlifecycle'),
            5 => __('Sexta', 'contractlifecycle'), 6 => __('Sábado', 'contractlifecycle'), 0 => __('Domingo', 'contractlifecycle'),
        ];

        return [
            // ---- Franquia e renovação
            'franchise_mode' => [
                'tab' => 'franchise', 'type' => 'select', 'default' => 'unlimited',
                'label' => __('Franquia', 'contractlifecycle'),
                'options' => [
                    'unlimited' => __('Ilimitada (só mede o tempo)', 'contractlifecycle'),
                    'limited'   => __('Limitada (debita do saldo de horas)', 'contractlifecycle'),
                ],
                'help' => __('Na limitada o saldo pode ficar negativo; o plugin só alerta e sugere recarga.', 'contractlifecycle'),
            ],
            'auto_renewal' => [
                'tab' => 'franchise', 'type' => 'bool', 'default' => 0,
                'label' => __('Renovação automática', 'contractlifecycle'),
            ],
            'renewal_recharge' => [
                'tab' => 'franchise', 'type' => 'bool', 'default' => 1,
                'label' => __('Na renovação, recarregar a franquia', 'contractlifecycle'),
                'help' => __('Só para franquia limitada com renovação automática: lança a franquia contratada como recarga.', 'contractlifecycle'),
            ],

            // ---- Avisos
            'notice_days' => [
                'tab' => 'notices', 'type' => 'text', 'default' => '90,60,30',
                'label' => __('Avisar antes do fim (dias)', 'contractlifecycle'),
                'help' => __('Separados por vírgula. Só para contratos com renovação automática.', 'contractlifecycle'),
            ],
            'notice_roles' => [
                'tab' => 'notices', 'type' => 'multi', 'default' => ['contract'],
                'label' => __('Avisar os pontos focais', 'contractlifecycle'),
                'options' => self::roleLabels(),
            ],
            'notice_groups_id' => [
                'tab' => 'notices', 'type' => 'dropdown', 'itemtype' => Group::class, 'default' => 0,
                'label' => __('Avisar também o grupo', 'contractlifecycle'),
                'help' => __('Ex.: gestores de contratos.', 'contractlifecycle'),
            ],

            // ---- Horário comercial
            'business_days' => [
                'tab' => 'hours', 'type' => 'multi', 'default' => [1, 2, 3, 4, 5],
                'label' => __('Dias úteis', 'contractlifecycle'), 'options' => $days,
            ],
            'business_start' => [
                'tab' => 'hours', 'type' => 'time', 'default' => '08:00',
                'label' => __('Início do horário comercial', 'contractlifecycle'),
            ],
            'business_end' => [
                'tab' => 'hours', 'type' => 'time', 'default' => '18:00',
                'label' => __('Fim do horário comercial', 'contractlifecycle'),
            ],
            'use_holidays' => [
                'tab' => 'hours', 'type' => 'bool', 'default' => 1,
                'label' => __('Feriados contam como fim de semana', 'contractlifecycle'),
            ],
            'calendars_id' => [
                'tab' => 'hours', 'type' => 'dropdown', 'itemtype' => Calendar::class, 'default' => 0,
                'label' => __('Calendário dos feriados', 'contractlifecycle'),
                'help' => __('Vazio = calendário configurado na entidade do contrato.', 'contractlifecycle'),
            ],

            // ---- Apontamento
            'time_sources' => [
                'tab' => 'timing', 'type' => 'multi', 'default' => ['task'],
                'label' => __('Tempo considerado', 'contractlifecycle'),
                'options' => [
                    'task' => __('Tarefas do chamado', 'contractlifecycle'),
                    'cost' => __('Custos lançados manualmente no chamado', 'contractlifecycle'),
                ],
            ],
            'create_ticketcost' => [
                'tab' => 'timing', 'type' => 'bool', 'default' => 1,
                'label' => __('Lançar o tempo debitado em Custos do chamado', 'contractlifecycle'),
            ],
            'min_business' => [
                'tab' => 'timing', 'type' => 'int', 'default' => 15, 'unit' => 'min',
                'label' => __('Cobrança mínima no horário comercial', 'contractlifecycle'),
            ],
            'min_offhours' => [
                'tab' => 'timing', 'type' => 'int', 'default' => 60, 'unit' => 'min',
                'label' => __('Cobrança mínima fora do horário comercial', 'contractlifecycle'),
            ],
            'min_weekend' => [
                'tab' => 'timing', 'type' => 'int', 'default' => 60, 'unit' => 'min',
                'label' => __('Cobrança mínima em fins de semana e feriados', 'contractlifecycle'),
            ],
            'boundary_rule' => [
                'tab' => 'timing', 'type' => 'select', 'default' => 'start',
                'label' => __('Apontamento que cruza faixas: prevalecer', 'contractlifecycle'),
                'options' => [
                    'start'       => __('Início', 'contractlifecycle'),
                    'end'         => __('Fim', 'contractlifecycle'),
                    'business'    => __('Horário comercial', 'contractlifecycle'),
                    'nonbusiness' => __('Horário não comercial', 'contractlifecycle'),
                ],
            ],
            'rounding' => [
                'tab' => 'timing', 'type' => 'bool', 'default' => 0,
                'label' => __('Arredondar para a faixa de maior cobrança', 'contractlifecycle'),
                'help' => __('Ligado: entre as faixas cruzadas vale a que cobra mais; empate segue a regra acima.', 'contractlifecycle'),
            ],

            // ---- Cobrança / Per-Event
            'billing_money' => [
                'tab' => 'billing', 'type' => 'bool', 'default' => 0,
                'label' => __('Cobrar em valor (tabela Per-Event)', 'contractlifecycle'),
            ],
            'requires_approval' => [
                'tab' => 'billing', 'type' => 'bool', 'default' => 0,
                'label' => __('Exige aprovação do ponto focal para atender', 'contractlifecycle'),
            ],
            'eligible_roots' => [
                'tab' => 'billing', 'type' => 'lines', 'default' => '',
                'label' => __('Cobre toda categoria cuja raiz se chama', 'contractlifecycle'),
                'help' => __('Um nome por linha (ex.: IT Support). Além dos serviços listados no contrato.', 'contractlifecycle'),
            ],

            // ---- Chamados (só global)
            'match_strategy' => [
                'tab' => 'tickets', 'scope' => 'global', 'type' => 'select', 'default' => 'unlimited_first',
                'label' => __('Mais de um contrato cobre o serviço: usar', 'contractlifecycle'),
                'options' => [
                    'unlimited_first' => __('Ilimitado → limitado → o que vence primeiro', 'contractlifecycle'),
                    'limited_first'   => __('Limitado → ilimitado → o que vence primeiro', 'contractlifecycle'),
                    'earliest_end'    => __('O que vence primeiro', 'contractlifecycle'),
                ],
            ],
            'show_ticket_bar' => [
                'tab' => 'tickets', 'scope' => 'global', 'type' => 'bool', 'default' => 1,
                'label' => __('Mostrar a barra do contrato no chamado', 'contractlifecycle'),
            ],
            'perevent_contracttypes_id' => [
                'tab' => 'perevent', 'scope' => 'global', 'type' => 'dropdown', 'itemtype' => ContractType::class, 'default' => 0,
                'label' => __('Tipo de contrato Per-Event', 'contractlifecycle'),
                'help' => __('Usado para abrir a aprovação e criar o contrato quando nenhum contrato cobre o chamado.', 'contractlifecycle'),
            ],
            'perevent_duration_days' => [
                'tab' => 'perevent', 'scope' => 'global', 'type' => 'int', 'default' => 30, 'unit' => __('dias', 'contractlifecycle'),
                'label' => __('Vigência base do contrato Per-Event', 'contractlifecycle'),
            ],
            'approval_roles' => [
                'tab' => 'perevent', 'scope' => 'global', 'type' => 'multi', 'default' => ['contract', 'financial'],
                'label' => __('Quem aprova', 'contractlifecycle'), 'options' => self::roleLabels(),
            ],
            'approval_groups_id' => [
                'tab' => 'perevent', 'scope' => 'global', 'type' => 'dropdown', 'itemtype' => Group::class, 'default' => 0,
                'label' => __('Grupo aprovador de reserva', 'contractlifecycle'),
                'help' => __('Usado quando a entidade não tem ponto focal cadastrado.', 'contractlifecycle'),
            ],
            // ---- Formulários (só global)
            'onboard_parent_entity' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'dropdown', 'itemtype' => \Entity::class, 'default' => 0,
                'label' => __('Onboard: entidade pai dos clientes', 'contractlifecycle'),
            ],
            'onboard_catalog_root' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'dropdown', 'itemtype' => \ITILCategory::class, 'default' => 0,
                'label' => __('Onboard: catálogo modelo', 'contractlifecycle'),
                'help' => __('Os ramos marcados no formulário são copiados deste catálogo para a entidade do cliente.', 'contractlifecycle'),
            ],
            'onboard_default_type' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'dropdown', 'itemtype' => ContractType::class, 'default' => 0,
                'label' => __('Onboard: tipo de contrato padrão', 'contractlifecycle'),
            ],
            'onboard_type_map' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'map', 'itemtype' => ContractType::class, 'default' => [],
                'keys' => [self::class, 'catalogAreas'],
                'label' => __('Onboard: tipo de contrato por área', 'contractlifecycle'),
                'help' => __('Área sem tipo = tipo padrão. Áreas de tipos diferentes viram contratos separados.', 'contractlifecycle'),
            ],
            'onboard_profiles' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'map', 'itemtype' => \Profile::class, 'default' => [],
                'keys' => [self::class, 'roleLabels'],
                'label' => __('Onboard: perfil dos usuários criados', 'contractlifecycle'),
                'help' => __('Os usuários também viram pontos focais da entidade no papel correspondente.', 'contractlifecycle'),
            ],
            'cancel_notice_days' => [
                'tab' => 'forms', 'scope' => 'global', 'type' => 'int', 'default' => 30, 'unit' => __('dias', 'contractlifecycle'),
                'label' => __('Cancelamento: encerrar após', 'contractlifecycle'),
                'help' => __('Usado quando o formulário não informa a data de encerramento.', 'contractlifecycle'),
            ],
            'block_until_approved' => [
                'tab' => 'perevent', 'scope' => 'global', 'type' => 'bool', 'default' => 1,
                'label' => __('Bloquear apontamento até a aprovação', 'contractlifecycle'),
            ],
        ];
    }

    public static function tabLabels(bool $global): array
    {
        $tabs = [
            'franchise' => __('Franquia e renovação', 'contractlifecycle'),
            'notices'   => __('Avisos', 'contractlifecycle'),
            'hours'     => __('Horário comercial', 'contractlifecycle'),
            'timing'    => __('Apontamento', 'contractlifecycle'),
            'billing'   => __('Cobrança', 'contractlifecycle'),
        ];
        if ($global) {
            $tabs['tickets']  = __('Chamados', 'contractlifecycle');
            $tabs['perevent'] = __('Per-Event', 'contractlifecycle');
            $tabs['forms']    = __('Formulários', 'contractlifecycle');
        }
        return $tabs;
    }

    // ------------------------------------------------------------------ schema

    public static function columnType(array $def, string $sign): string
    {
        return match ($def['type']) {
            'bool'     => 'tinyint DEFAULT NULL',
            'int'      => 'int DEFAULT NULL',
            'dropdown' => "int {$sign} DEFAULT NULL",
            'time'     => 'varchar(5) DEFAULT NULL',
            'select'   => 'varchar(30) DEFAULT NULL',
            default    => 'text', // text, lines, multi, map
        };
    }

    public static function columnsSql(string $sign): string
    {
        $sql = '';
        foreach (self::definitions() as $key => $def) {
            $sql .= "`{$key}` " . self::columnType($def, $sign) . ",\n";
        }
        return $sql;
    }

    public static function globalDefaults(): array
    {
        return array_map(fn ($d) => $d['default'], self::definitions());
    }

    // ------------------------------------------------------------------ leitura / gravação

    /** Valores gravados de um nível (já decodificados), ou null se o nível não tem linha. */
    public static function getRow(string $itemtype, int $items_id): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current();
        if ($row === null) {
            return null;
        }
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[$key] = self::decode($def, $row[$key] ?? null);
        }
        return $out;
    }

    /** Grava um nível; chaves ausentes/NULL = herdar. */
    public static function saveRow(string $itemtype, int $items_id, array $values): void
    {
        global $DB;
        $data = [];
        foreach (self::definitions() as $key => $def) {
            $data[$key] = self::encode($def, $values[$key] ?? null);
        }
        $data['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $where = ['itemtype' => $itemtype, 'items_id' => $items_id];
        if ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where])->count()) {
            $DB->update(self::getTable(), $data, $where);
        } else {
            $DB->insert(self::getTable(), $data + $where);
        }
        self::$cache = [];
    }

    public static function deleteRow(string $itemtype, int $items_id): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id]);
        self::$cache = [];
    }

    private static function encode(array $def, mixed $value): mixed
    {
        if ($value === null || $value === '' && $def['type'] !== 'text' && $def['type'] !== 'lines') {
            return null;
        }
        return match ($def['type']) {
            'bool'           => $value ? 1 : 0,
            'int', 'dropdown' => (int) $value,
            'multi'          => json_encode(array_values((array) $value)),
            'map'            => json_encode((object) array_filter(array_map('intval', (array) $value))),
            default          => (string) $value,
        };
    }

    private static function decode(array $def, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($def['type']) {
            'bool', 'int', 'dropdown' => (int) $value,
            'multi', 'map'            => json_decode((string) $value, true) ?? [],
            default                   => (string) $value,
        };
    }

    // ------------------------------------------------------------------ herança

    private static array $cache = [];

    /** Configuração efetiva global. */
    public static function global(): array
    {
        return self::$cache['global'] ??= array_merge(
            self::globalDefaults(),
            array_filter(self::getRow('global', 0) ?? [], fn ($v) => $v !== null)
        );
    }

    /** Configuração efetiva de um tipo de contrato (global + tipo). */
    public static function forType(int $contracttypes_id): array
    {
        return self::$cache["type:$contracttypes_id"] ??= array_merge(
            self::global(),
            $contracttypes_id > 0 ? array_filter(self::getRow(ContractType::class, $contracttypes_id) ?? [], fn ($v) => $v !== null) : []
        );
    }

    /** Configuração efetiva de um contrato (global + tipo + contrato). */
    public static function forContract(int $contracts_id): array
    {
        if (isset(self::$cache["contract:$contracts_id"])) {
            return self::$cache["contract:$contracts_id"];
        }
        $contract = new Contract();
        $type = $contract->getFromDB($contracts_id) ? (int) $contract->fields['contracttypes_id'] : 0;
        return self::$cache["contract:$contracts_id"] = array_merge(
            self::forType($type),
            array_filter(self::getRow(Contract::class, $contracts_id) ?? [], fn ($v) => $v !== null)
        );
    }

    /** Valores herdados por um nível (o que vale se ele não sobrescrever). */
    public static function inheritedFor(string $itemtype, int $items_id): array
    {
        if ($itemtype === ContractType::class) {
            return self::global();
        }
        if ($itemtype === Contract::class) {
            $contract = new Contract();
            $type = $contract->getFromDB($items_id) ? (int) $contract->fields['contracttypes_id'] : 0;
            return self::forType($type);
        }
        return self::globalDefaults();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    // ------------------------------------------------------------------ abas

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!Session::haveRight(self::RIGHT, READ) || $withtemplate) {
            return '';
        }
        if ($item instanceof ContractType || ($item instanceof Contract && !$item->isNewItem())) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ContractType || $item instanceof Contract) {
            self::showLevelForm($item::getType(), $item->getID());
        }
        return true;
    }

    /** Formulário do nível (global/tipo/contrato), em sub-abas. */
    public static function showLevelForm(string $itemtype, int $items_id): void
    {
        $global = $itemtype === self::LEVEL_GLOBAL;
        $own = self::getRow($itemtype, $items_id) ?? [];
        $inherited = $global ? self::globalDefaults() : self::inheritedFor($itemtype, $items_id);

        $tabs = [];
        foreach (self::tabLabels($global) as $tab => $label) {
            $tabs[$tab] = ['label' => $label, 'fields' => []];
        }
        foreach (self::definitions() as $key => $def) {
            if (($def['scope'] ?? 'all') === 'global' && !$global) {
                continue;
            }
            $value = $own[$key] ?? null;
            $overridden = $global || $value !== null;
            $tabs[$def['tab']]['fields'][] = [
                'key'        => $key,
                'label'      => $def['label'],
                'help'       => $def['help'] ?? '',
                'unit'       => $def['unit'] ?? '',
                'overridden' => $overridden,
                'inherited'  => self::displayValue($def, $inherited[$key] ?? $def['default']),
                'input'      => self::renderInput($key, $def, $overridden ? $value ?? $def['default'] : ($inherited[$key] ?? $def['default'])),
            ];
        }

        $origin = match ($itemtype) {
            ContractType::class => __('da configuração geral', 'contractlifecycle'),
            Contract::class     => __('do tipo do contrato', 'contractlifecycle'),
            default             => '',
        };

        TemplateRenderer::getInstance()->display('@contractlifecycle/settings.html.twig', [
            'tabs'      => array_filter($tabs, fn ($t) => $t['fields'] !== []),
            'global'    => $global,
            'itemtype'  => $itemtype,
            'items_id'  => $items_id,
            'origin'    => $origin,
            'can_edit'  => Session::haveRight(self::RIGHT, UPDATE),
            'action'    => Helpers::url('front/settings.form.php'),
            'rand'      => mt_rand(),
        ]);
    }

    public static function displayValue(array $def, mixed $value): string
    {
        return match ($def['type']) {
            'bool'     => $value ? __('Yes') : __('No'),
            'select'   => (string) ($def['options'][$value] ?? $value),
            'multi'    => implode(', ', array_map(fn ($v) => (string) ($def['options'][$v] ?? $v), (array) $value)) ?: '—',
            'dropdown' => $value ? \Dropdown::getDropdownName(getTableForItemType($def['itemtype']), $value) : '—',
            'lines'    => str_replace("\n", ', ', trim((string) $value)) ?: '—',
            'map'      => implode(', ', array_map(
                fn ($k, $v) => $k . ' → ' . \Dropdown::getDropdownName(getTableForItemType($def['itemtype']), $v),
                array_keys((array) $value),
                (array) $value
            )) ?: '—',
            'int'      => $value . (isset($def['unit']) ? ' ' . $def['unit'] : ''),
            default    => (string) $value,
        };
    }

    private static function renderInput(string $key, array $def, mixed $value): string
    {
        $name = "values[{$key}]";
        $id = 'clc_' . $key . '_' . mt_rand();
        switch ($def['type']) {
            case 'bool':
                return \Dropdown::showYesNo($name, (int) $value, -1, ['display' => false, 'rand' => mt_rand()]);
            case 'select':
                return \Dropdown::showFromArray($name, $def['options'], ['value' => $value, 'display' => false, 'width' => '100%']);
            case 'multi':
                return \Dropdown::showFromArray($name, $def['options'], [
                    'values' => array_map('strval', (array) $value), 'multiple' => true, 'display' => false, 'width' => '100%',
                ]);
            case 'dropdown':
                return \Dropdown::show($def['itemtype'], [
                    'name' => $name, 'value' => (int) $value, 'display' => false, 'entity' => -1, 'width' => '100%',
                ]);
            case 'map':
                $html = '<table class="table table-sm mb-0"><tbody>';
                foreach (call_user_func($def['keys']) as $k => $label) {
                    $html .= '<tr><td class="align-middle" style="width: 40%">' . htmlescape($label) . '</td><td>'
                        . \Dropdown::show($def['itemtype'], [
                            // chave em hex: o Dropdown troca espaços e outros caracteres do nome do campo
                            'name' => "values[{$key}][" . bin2hex((string) $k) . ']', 'value' => (int) (((array) $value)[$k] ?? 0),
                            'display' => false, 'entity' => -1, 'width' => '100%',
                        ]) . '</td></tr>';
                }
                return $html . '</tbody></table>';
            case 'int':
                return sprintf('<input type="number" min="0" class="form-control" id="%s" name="%s" value="%s">', $id, htmlescape($name), htmlescape((string) $value));
            case 'time':
                return sprintf('<input type="time" class="form-control" id="%s" name="%s" value="%s">', $id, htmlescape($name), htmlescape((string) $value));
            case 'lines':
                return sprintf('<textarea class="form-control" rows="3" id="%s" name="%s">%s</textarea>', $id, htmlescape($name), htmlescape((string) $value));
            default:
                return sprintf('<input type="text" class="form-control" id="%s" name="%s" value="%s">', $id, htmlescape($name), htmlescape((string) $value));
        }
    }

    /** Trata o POST do formulário de qualquer nível. */
    public static function handlePost(array $post): void
    {
        $itemtype = (string) ($post['itemtype'] ?? '');
        $items_id = (int) ($post['items_id'] ?? 0);
        if (!in_array($itemtype, [self::LEVEL_GLOBAL, ContractType::class, Contract::class], true)) {
            throw new \Glpi\Exception\Http\BadRequestHttpException();
        }
        $global = $itemtype === self::LEVEL_GLOBAL;
        $values = [];
        foreach (self::definitions() as $key => $def) {
            if (($def['scope'] ?? 'all') === 'global' && !$global) {
                continue;
            }
            if (!$global && empty($post['override'][$key])) {
                $values[$key] = null; // herda
                continue;
            }
            $raw = $post['values'][$key] ?? ($def['type'] === 'multi' ? [] : null);
            $values[$key] = self::sanitize($def, $raw);
        }
        if ($global) {
            $values = array_merge(self::getRow('global', 0) ?? [], $values);
        }
        self::saveRow($itemtype, $items_id, $values);
        Session::addMessageAfterRedirect(__('Regras salvas.', 'contractlifecycle'));
    }

    /** Chaves do tipo 'map' chegam em hex (ver renderInput). */
    private static function decodeMapKeys(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $k = (string) $k;
            $decoded = ctype_xdigit($k) && strlen($k) % 2 === 0 ? hex2bin($k) : false;
            $out[$decoded !== false ? $decoded : $k] = $v;
        }
        return $out;
    }

    private static function sanitize(array $def, mixed $raw): mixed
    {
        return match ($def['type']) {
            'bool'            => (int) (bool) $raw,
            'int', 'dropdown' => max(0, (int) $raw),
            'select'          => array_key_exists((string) $raw, $def['options']) ? (string) $raw : $def['default'],
            'multi'           => array_values(array_filter(
                array_map('strval', (array) $raw),
                fn ($v) => array_key_exists($v, $def['options'])
            )),
            'time'            => preg_match('/^\d{2}:\d{2}$/', (string) $raw) ? (string) $raw : $def['default'],
            'text'            => preg_match('/^[\d,\s]*$/', (string) $raw) ? preg_replace('/\s+/', '', (string) $raw) : $def['default'],
            'lines'           => trim(preg_replace("/\r\n?/", "\n", (string) $raw)),
            'map'             => array_filter(
                array_map('intval', array_intersect_key(self::decodeMapKeys((array) $raw), call_user_func($def['keys']))),
                fn ($v) => $v > 0
            ),
            default           => (string) $raw,
        };
    }
}
