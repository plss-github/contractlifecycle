<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonITILActor;
use Contract;
use ContractType;
use Document_Item;
use Entity;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AnswersSet_FormDestinationItem;
use ITILCategory;
use ITILFollowup;
use Profile_User;
use Ticket;
use Ticket_User;
use User;

/**
 * Executa a ação do ciclo de vida quando um formulário é enviado (logo que o GLPI cria o chamado de destino).
 *
 * O envio do formulário roda dentro de uma transação do GLPI: nada aqui pode lançar exceção para fora (desfaria
 * o envio). Cada passo é protegido; o que não puder ser feito vai para o relatório, gravado na execução e num
 * acompanhamento privado do chamado. Cada conjunto de respostas é processado uma única vez.
 */
class FormRunner
{
    private array $report = [];
    private string $status = 'ok';
    private array $answers = [];
    private array $questions = [];

    public function __construct(private array $config, private AnswersSet $answers_set, private ?Ticket $ticket)
    {
        foreach (json_decode((string) $answers_set->fields['answers'], true) ?: [] as $answer) {
            $this->answers[(int) $answer['question_id']] = $answer;
        }
        $this->questions = FormAction::questions((int) $answers_set->fields['forms_forms_id']);
    }

    // ------------------------------------------------------------------ hook

    /** item_add de AnswersSet_FormDestinationItem: o chamado do envio acabou de ser criado. */
    public static function destinationLinked(AnswersSet_FormDestinationItem $link): void
    {
        try {
            if (($link->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }
            $answers_set = new AnswersSet();
            if (!$answers_set->getFromDB((int) $link->fields['forms_answerssets_id'])) {
                return;
            }
            $config = FormAction::forForm((int) $answers_set->fields['forms_forms_id']);
            if ($config === null || $config['action'] === '' || empty($config['is_active']) || self::alreadyRan((int) $answers_set->getID())) {
                return;
            }
            $ticket = Ticket::getById((int) $link->fields['items_id']) ?: null;
            (new self($config, $answers_set, $ticket))->run();
        } catch (\Throwable $e) {
            \Glpi\Error\ErrorHandler::logCaughtException($e);
        }
    }

    public static function alreadyRan(int $answerssets_id): bool
    {
        global $DB;
        return $DB->request(['FROM' => 'glpi_plugin_contractlifecycle_formruns', 'WHERE' => ['forms_answerssets_id' => $answerssets_id]])->count() > 0;
    }

    public static function runsOf(int $forms_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_contractlifecycle_formruns', 'WHERE' => ['forms_forms_id' => $forms_id],
            'ORDER' => 'id DESC', 'LIMIT' => 50,
        ]), false);
    }

    /** Acompanhamento privado no chamado, em nome do sistema. */
    public static function note(Ticket $ticket, string $text): void
    {
        (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => (int) $ticket->getID(),
            'content'    => '<p>' . nl2br(htmlescape($text)) . '</p>',
            'is_private' => 1,
            'requesttypes_id' => 0,
            '_disablenotif' => true,
            // registro do sistema: não pode reabrir o chamado (ex.: nota de ativação gravada no fechamento)
            '_no_reopen' => 1,
            '_do_not_compute_takeintoaccount' => true,
        ]);
    }

    public function run(): void
    {
        $action = $this->config['action'];
        $this->log(sprintf(__('Ciclo de vida — %s', 'contractlifecycle'), FormAction::actions()[$action] ?? $action));
        try {
            match ($action) {
                'onboard'     => $this->onboard(),
                'recharge'    => $this->recharge(),
                'renewal'     => $this->renewal(),
                'termination' => $this->termination(),
                default       => null,
            };
        } catch (\Throwable $e) {
            $this->fail(sprintf(__('Erro inesperado: %s', 'contractlifecycle'), $e->getMessage()));
            \Glpi\Error\ErrorHandler::logCaughtException($e);
        }

        global $DB;
        $text = implode("\n", $this->report);
        $DB->insert('glpi_plugin_contractlifecycle_formruns', [
            'forms_answerssets_id' => (int) $this->answers_set->getID(),
            'forms_forms_id'       => (int) $this->answers_set->fields['forms_forms_id'],
            'tickets_id'           => (int) ($this->ticket?->getID() ?? 0),
            'action'               => $action,
            'status'               => $this->status,
            'report'               => $text,
            'date_creation'        => Helpers::now(),
        ]);
        if ($this->ticket) {
            try {
                self::note($this->ticket, $text);
            } catch (\Throwable $e) {
                \Glpi\Error\ErrorHandler::logCaughtException($e);
            }
        }
    }

    // ------------------------------------------------------------------ respostas

    private function log(string $line): void
    {
        $this->report[] = $line;
    }

    private function warn(string $line): void
    {
        $this->report[] = '⚠ ' . $line;
        if ($this->status === 'ok') {
            $this->status = 'partial';
        }
    }

    private function fail(string $line): void
    {
        $this->report[] = '✖ ' . $line;
        $this->status = 'error';
    }

    private function question(string $key): ?array
    {
        [$q] = FormAction::resolve($this->config['mapping'][$key] ?? null, $this->questions);
        return $q;
    }

    /** Resposta crua de um campo (null = sem pergunta ou não respondida). */
    private function raw(string $key): mixed
    {
        $q = $this->question($key);
        if ($q === null) {
            return null;
        }
        if (isset($this->answers[(int) $q['id']])) {
            return $this->answers[(int) $q['id']]['raw_answer'];
        }
        foreach ($this->answers as $answer) { // envio anterior a uma recriação da pergunta
            if (FormAction::norm((string) $answer['question_label']) === FormAction::norm($q['name'])) {
                return $answer['raw_answer'];
            }
        }
        return null;
    }

    private function text(string $key): string
    {
        $raw = $this->raw($key);
        if (is_array($raw)) {
            $raw = implode(', ', array_map('strval', $raw));
        }
        return trim(html_entity_decode(strip_tags((string) $raw)));
    }

    private function date(string $key): ?string
    {
        $text = $this->text($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) ? substr($text, 0, 10) : null;
    }

    private function itemId(string $key): int
    {
        $raw = $this->raw($key);
        return (int) (is_array($raw) ? ($raw['items_id'] ?? 0) : $raw);
    }

    /** Rótulos das opções marcadas (caixas de seleção, rádio, lista). */
    private function labels(array $q, mixed $raw): array
    {
        $options = json_decode((string) $q['extra_data'], true)['options'] ?? [];
        $keys = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
        return array_values(array_filter(array_map(fn ($k) => trim((string) ($options[$k] ?? $k)), $keys), fn ($l) => $l !== ''));
    }

    private function choice(string $key): array
    {
        $q = $this->question($key);
        return $q === null ? [] : $this->labels($q, $this->raw($key));
    }

    private function contract(string $key = 'contract'): ?Contract
    {
        $id = $this->itemId($key);
        $contract = new Contract();
        if ($id <= 0 || !$contract->getFromDB($id)) {
            $this->fail(__('Contrato não informado ou não encontrado.', 'contractlifecycle'));
            return null;
        }
        return $contract;
    }

    // ------------------------------------------------------------------ ações simples

    private function recharge(): void
    {
        if (!$contract = $this->contract()) {
            return;
        }
        $hours = (float) str_replace(',', '.', $this->text('hours'));
        if ($hours <= 0) {
            $this->fail(__('Quantidade de horas inválida.', 'contractlifecycle'));
            return;
        }
        $id = (int) $contract->getID();
        Ledger::credit($id, Ledger::TYPE_RECHARGE, (int) round($hours * 3600),
            sprintf(__('Formulário de recarga (chamado #%d)', 'contractlifecycle'), $this->ticket?->getID() ?? 0),
            (int) ($this->ticket?->getID() ?? 0));
        $this->log(sprintf(__('Recarga de %1$s lançada no contrato %2$s. Saldo atual: %3$s.', 'contractlifecycle'),
            Helpers::hours((int) round($hours * 3600)), $contract->getName(), Helpers::hours(Ledger::totals($id)['balance'])));
    }

    private function renewal(): void
    {
        if (!$contract = $this->contract()) {
            return;
        }
        $id = (int) $contract->getID();
        $result = Cron::renewOnce($contract->fields, Settings::forContract($id),
            sprintf(__('Renovação pelo formulário (chamado #%d)', 'contractlifecycle'), $this->ticket?->getID() ?? 0));
        $this->log(sprintf(__('Contrato %1$s renovado até %2$s.', 'contractlifecycle'), $contract->getName(), Helpers::date($result['end'])));
        if ($result['recharged'] > 0) {
            $this->log(sprintf(__('Franquia recarregada: %s.', 'contractlifecycle'), Helpers::hours($result['recharged'])));
        }
    }

    private function termination(): void
    {
        if (!$contract = $this->contract()) {
            return;
        }
        $id = (int) $contract->getID();
        $options = $this->choice('option');
        $cancel = $options !== [] && str_contains(FormAction::norm($options[0]), 'cancel');
        $reason = $this->text('reason');

        // não renova mais (sobrescrita só neste contrato)
        $row = Settings::getRow(Contract::class, $id) ?? [];
        Settings::saveRow(Contract::class, $id, ['auto_renewal' => 0] + $row);

        $today = substr((string) ($this->answers_set->fields['date_creation'] ?: Helpers::now()), 0, 10);
        if ($cancel) {
            $end = $this->date('date')
                ?? (new \DateTime($today))->modify('+' . (int) Settings::global()['cancel_notice_days'] . ' days')->format('Y-m-d');
            ContractData::upsert($id, ['end_date' => $end]);
            $this->log(sprintf(__('Cancelamento: contrato %1$s encerra em %2$s; renovação automática desligada.', 'contractlifecycle'), $contract->getName(), Helpers::date($end)));
        } else {
            $this->log(sprintf(__('Não renovação: contrato %1$s vale até %2$s e não será renovado.', 'contractlifecycle'),
                $contract->getName(), Helpers::date(ContractData::endDate($contract->fields))));
        }
        $line = sprintf('[%s] %s — %s #%d%s', Helpers::date($today), $cancel ? __('Cancelamento', 'contractlifecycle') : __('Não renovação', 'contractlifecycle'),
            Ticket::getTypeName(1), $this->ticket?->getID() ?? 0, $reason !== '' ? ': ' . $reason : '');
        $contract->update(['id' => $id, 'comment' => trim($contract->fields['comment'] . "\n" . $line)]);
    }

    // ------------------------------------------------------------------ onboard

    private function onboard(): void
    {
        $global = Settings::global();
        $name = $this->text('client');
        if ($name === '') {
            $this->fail(__('Nome do cliente não informado.', 'contractlifecycle'));
            return;
        }

        // 1. entidade
        $parent = (int) $global['onboard_parent_entity'];
        $entity = new Entity();
        if ($entity->getFromDBByCrit(['name' => $name, 'entities_id' => $parent])) {
            $entities_id = (int) $entity->getID();
            $this->log(sprintf(__('Entidade existente reaproveitada: %s.', 'contractlifecycle'), $entity->fields['completename']));
        } else {
            $entities_id = (int) $entity->add([
                'name'                => $name,
                'entities_id'         => $parent,
                'registration_number' => $this->text('cnpj'),
                'address'             => $this->text('street'),
                'postcode'            => $this->text('postcode'),
                'town'                => $this->text('town'),
                'country'             => $this->text('country'),
                'comment'             => $this->text('ie') !== '' ? sprintf(__('Inscrição estadual: %s', 'contractlifecycle'), $this->text('ie')) : '',
            ]);
            if ($entities_id <= 0) {
                $this->fail(sprintf(__('Não foi possível criar a entidade %s.', 'contractlifecycle'), $name));
                return;
            }
            $entity->getFromDB($entities_id);
            $this->log(sprintf(__('Entidade criada: %s.', 'contractlifecycle'), $entity->fields['completename']));
        }
        // a sessão precisa enxergar a entidade nova para os cadastros abaixo
        if (!in_array($entities_id, $_SESSION['glpiactiveentities'] ?? [], true)) {
            $_SESSION['glpiactiveentities'][] = $entities_id;
        }

        // 2. catálogo: copia os ramos marcados
        $byArea = $this->cloneServices($entities_id);

        // 3. contratos (um por tipo)
        $contracts = $this->createContracts($entities_id, $name, $byArea);

        // 4. usuários e pontos focais
        $this->createUsers($entities_id);

        // 5. chamado: quem acompanha
        if ($this->ticket) {
            foreach (array_filter([$this->text('notify_email'), $this->text('sales_email')]) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    (new Ticket_User())->add([
                        'tickets_id' => (int) $this->ticket->getID(), 'users_id' => 0,
                        'type' => CommonITILActor::OBSERVER, 'use_notification' => 1, 'alternative_email' => $email,
                    ]);
                }
            }
        }
        if ($contracts !== []) {
            $this->log(__('Os contratos começam a valer no fechamento deste chamado.', 'contractlifecycle'));
        }
    }

    /**
     * Para cada seção de serviços (mapeada para uma área do catálogo), cada caixa de seleção cujo nome é uma
     * subárea, e cada opção marcada é procurada pelo nome dentro da subárea no catálogo modelo; o caminho é
     * copiado para a entidade (reaproveitando o que já existir).
     *
     * @return array<string, int[]> área → categorias da entidade (folhas e intermediárias)
     */
    private function cloneServices(int $entities_id): array
    {
        $root = (int) Settings::global()['onboard_catalog_root'];
        if ($root <= 0) {
            $this->warn(__('Catálogo modelo não configurado: serviços não copiados.', 'contractlifecycle'));
            return [];
        }
        $sections = $this->config['mapping']['_sections'] ?? [];
        $byArea = [];
        $copied = 0;
        $missing = [];
        foreach ($this->questions as $q) {
            $area = $sections[$q['section_uuid']]['area'] ?? '';
            if ($area === '' || $q['short_type'] !== 'Checkbox') {
                continue;
            }
            $area_cat = $this->childByName($root, $area);
            $sub_cat = $area_cat ? $this->childByName($area_cat, $q['name']) : null;
            if ($sub_cat === null) {
                continue; // caixa de seleção que não é subárea (ex.: "Selecionar os tipos")
            }
            foreach ($this->labels($q, $this->answers[(int) $q['id']]['raw_answer'] ?? null) as $label) {
                $template = $this->descendantByName($sub_cat, $label);
                if ($template === null) {
                    $missing[] = "{$area} › {$q['name']} › {$label}";
                    continue;
                }
                foreach ($this->cloneChain($template, $root, $entities_id) as $id) {
                    $byArea[$area][$id] = $id;
                }
                $copied++;
            }
        }
        $this->log(sprintf(__('Catálogo: %d serviço(s) copiado(s) para a entidade.', 'contractlifecycle'), $copied));
        if ($missing !== []) {
            $this->warn(sprintf(__('Não encontrados no catálogo modelo: %s.', 'contractlifecycle'), implode('; ', $missing)));
        }
        return array_map('array_values', $byArea);
    }

    private function childByName(int $parent, string $name): ?int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => ITILCategory::getTable(), 'WHERE' => ['itilcategories_id' => $parent]]) as $row) {
            if (FormAction::norm($row['name']) === FormAction::norm($name)) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    private function descendantByName(int $parent, string $name): ?int
    {
        $queue = [$parent];
        while ($queue) {
            $current = array_shift($queue);
            global $DB;
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => ITILCategory::getTable(), 'WHERE' => ['itilcategories_id' => $current], 'ORDER' => 'name']) as $row) {
                if (FormAction::norm($row['name']) === FormAction::norm($name)) {
                    return (int) $row['id'];
                }
                $queue[] = (int) $row['id'];
            }
        }
        return null;
    }

    /** Copia o caminho raiz-do-catálogo → categoria para a entidade. @return int[] categorias da entidade no caminho */
    private function cloneChain(int $template_id, int $root, int $entities_id): array
    {
        $chain = [];
        $category = new ITILCategory();
        $id = $template_id;
        while ($id > 0 && $id !== $root && $category->getFromDB($id)) {
            array_unshift($chain, $category->fields);
            $id = (int) $category->fields['itilcategories_id'];
        }
        $parent = 0;
        $created = [];
        $skip = ['id', 'entities_id', 'is_recursive', 'itilcategories_id', 'completename', 'level', 'ancestors_cache', 'sons_cache', 'date_mod', 'date_creation'];
        foreach ($chain as $template) {
            $existing = new ITILCategory();
            if ($existing->getFromDBByCrit(['entities_id' => $entities_id, 'itilcategories_id' => $parent, 'name' => $template['name']])) {
                $parent = (int) $existing->getID();
            } else {
                $input = array_diff_key($template, array_flip($skip)) + [
                    'entities_id' => $entities_id, 'is_recursive' => 0, 'itilcategories_id' => $parent,
                ];
                $parent = (int) (new ITILCategory())->add($input);
            }
            $created[] = $parent;
        }
        return array_filter($created);
    }

    private function createContracts(int $entities_id, string $client, array $byArea): array
    {
        $global = Settings::global();
        if ($byArea === []) {
            $this->warn(__('Nenhum serviço copiado: contrato não criado.', 'contractlifecycle'));
            return [];
        }
        $byType = [];
        foreach ($byArea as $area => $categories) {
            $type = (int) ($global['onboard_type_map'][$area] ?? 0) ?: (int) $global['onboard_default_type'];
            $byType[$type]['areas'][] = $area;
            $byType[$type]['categories'] = array_merge($byType[$type]['categories'] ?? [], $categories);
        }

        $begin = $this->date('begin');
        $end = $this->date('end');
        $opportunity = $this->text('opportunity');
        $comment = implode("\n", array_filter([
            $this->text('sales_name') !== '' ? sprintf(__('Responsável comercial: %s', 'contractlifecycle'), $this->text('sales_name') . ($this->text('sales_email') !== '' ? ' <' . $this->text('sales_email') . '>' : '')) : '',
            $begin || $end ? sprintf(__('Vigência planejada no onboard: %1$s a %2$s', 'contractlifecycle'), Helpers::date($begin), Helpers::date($end)) : '',
            sprintf(__('Criado pelo formulário de onboard (chamado #%d).', 'contractlifecycle'), $this->ticket?->getID() ?? 0),
        ]));
        $documents = array_map('intval', (array) ($this->raw('proposals') ?? []));

        $ids = [];
        foreach ($byType as $type => $data) {
            $type_name = $type ? \Dropdown::getDropdownName(ContractType::getTable(), $type) : '';
            $contract_name = trim($client . ($opportunity !== '' ? ' - ' . $opportunity : '') . (count($byType) > 1 ? ' - ' . $type_name : ''));
            $contract = new Contract();
            $contracts_id = (int) $contract->add([
                'name' => $contract_name, 'num' => $opportunity, 'entities_id' => $entities_id, 'is_recursive' => 1,
                'contracttypes_id' => $type, 'comment' => $comment,
            ]);
            if ($contracts_id <= 0) {
                $this->fail(sprintf(__('Não foi possível criar o contrato %s.', 'contractlifecycle'), $contract_name));
                continue;
            }
            ContractData::upsert($contracts_id, [
                'pending_activation' => 1,
                'planned_begin'      => $begin,
                'planned_end'        => $end,
                'end_date'           => $end,
                'tickets_id_origin'  => (int) ($this->ticket?->getID() ?? 0),
            ]);
            foreach (array_unique($data['categories']) as $category) {
                Service::addCategory($contracts_id, (int) $category);
            }
            foreach ($documents as $documents_id) {
                if ($documents_id > 0) {
                    (new Document_Item())->add(['documents_id' => $documents_id, 'itemtype' => Contract::class, 'items_id' => $contracts_id]);
                }
            }
            $this->log(sprintf(__('Contrato criado: %1$s (%2$s; %3$s; %4$d serviço(s)) — aguardando ativação.', 'contractlifecycle'),
                $contract_name, $type_name ?: '—', implode(', ', $data['areas']), count(array_unique($data['categories']))));
            $ids[] = $contracts_id;
        }
        return $ids;
    }

    private function createUsers(int $entities_id): void
    {
        $profiles = Settings::global()['onboard_profiles'] ?? [];
        $chosen = array_map([FormAction::class, 'norm'], $this->choice('users_choice'));
        $role_words = ['contract' => ['master'], 'technical' => ['tecnico'], 'financial' => ['financeiro']];
        global $DB;

        foreach ($role_words as $role => $words) {
            $email = strtolower($this->text("{$role}_email"));
            $wanted = $chosen === [] ? $email !== '' : array_intersect($words, $chosen) !== [];
            if (!$wanted) {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn(sprintf(__('Usuário %s não criado: e-mail inválido ou vazio.', 'contractlifecycle'), Settings::roleLabels()[$role]));
                continue;
            }
            $user = new User();
            if ($user->getFromDBbyName($email)) {
                $users_id = (int) $user->getID();
                $what = __('existente', 'contractlifecycle');
            } else {
                // sem senha: o acesso é pelo login externo (SSO)
                $users_id = (int) $user->add([
                    'name'      => $email,
                    'firstname' => $this->text("{$role}_firstname"),
                    'realname'  => $this->text("{$role}_lastname"),
                    'phone'     => $this->text("{$role}_phone"),
                    '_useremails' => [-1 => $email],
                    'entities_id' => $entities_id,
                ]);
                if ($users_id <= 0) {
                    $this->warn(sprintf(__('Não foi possível criar o usuário %s.', 'contractlifecycle'), $email));
                    continue;
                }
                $what = __('criado', 'contractlifecycle');
            }
            $profiles_id = (int) ($profiles[$role] ?? 0);
            if ($profiles_id > 0) {
                $pu = new Profile_User();
                if (!$pu->getFromDBByCrit(['users_id' => $users_id, 'profiles_id' => $profiles_id, 'entities_id' => $entities_id])) {
                    $pu->add(['users_id' => $users_id, 'profiles_id' => $profiles_id, 'entities_id' => $entities_id, 'is_recursive' => 1]);
                }
            }
            if (!$DB->request(['FROM' => Focal::getTable(), 'WHERE' => ['entities_id' => $entities_id, 'role' => $role, 'users_id' => $users_id]])->count()) {
                $DB->insert(Focal::getTable(), ['entities_id' => $entities_id, 'role' => $role, 'users_id' => $users_id]);
            }
            $this->log(sprintf(__('Usuário %1$s (%2$s): %3$s, perfil %4$s, ponto focal.', 'contractlifecycle'),
                $email, Settings::roleLabels()[$role], $what,
                $profiles_id ? \Dropdown::getDropdownName('glpi_profiles', $profiles_id) : '—'));
        }
    }
}
