<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use CommonGLPI;
use Entity;
use Session;
use User;

/**
 * Pontos focais da entidade (contrato, financeiro, técnico), cada papel com vários usuários.
 * Quem recebe avisos e aprova Per-Event é definido por papel na configuração geral.
 */
class Focal extends CommonDBTM
{
    public static $rightname = 'entity';

    public static function getTypeName($nb = 0)
    {
        return _n('Ponto focal', 'Pontos focais', $nb, 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-user-star';
    }

    /** @return array<string, int[]> papel => usuários */
    public static function ofEntity(int $entities_id): array
    {
        global $DB;
        $out = array_fill_keys(Settings::ROLES, []);
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['entities_id' => $entities_id]]) as $row) {
            $out[$row['role']][] = (int) $row['users_id'];
        }
        return $out;
    }

    /**
     * Usuários dos papéis pedidos para a entidade; se a entidade não tem ninguém, sobe para a entidade pai
     * (um ponto focal no grupo econômico vale para as filhas).
     *
     * @return int[]
     */
    public static function usersFor(int $entities_id, array $roles): array
    {
        $entity = $entities_id;
        while (true) {
            $focals = self::ofEntity($entity);
            $users = [];
            foreach ($roles as $role) {
                $users = array_merge($users, $focals[$role] ?? []);
            }
            if ($users !== [] || $entity === 0) {
                return array_values(array_unique($users));
            }
            $current = Entity::getById($entity);
            $parent = $current ? max(0, (int) $current->fields['entities_id']) : 0;
            if ($parent === $entity) {
                return [];
            }
            $entity = $parent;
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Entity || !Entity::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs']) {
            $count = count(array_unique(array_merge(...array_values(self::ofEntity((int) $item->getID())))));
        }
        return self::createTabEntry(self::getTypeName(2), $count, $item::getType(), self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Entity) {
            return false;
        }
        $id = (int) $item->getID();
        $focals = self::ofEntity($id);
        $roles = [];
        foreach (Settings::roleLabels() as $role => $label) {
            $roles[$role] = [
                'label' => $label,
                'count' => count($focals[$role]),
                'input' => User::dropdown([
                    'name'     => "users[{$role}][]",
                    'multiple' => true,
                    'value'    => $focals[$role], // no modo multiple o User::dropdown lê 'value' (não 'values')
                    'right'    => 'all',
                    'entity'   => $id,
                    'entity_sons' => true,
                    'display'  => false,
                    'width'    => '100%',
                ]),
            ];
        }
        echo Helpers::render('focals.html.twig', [
            'roles'    => $roles,
            'entities_id' => $id,
            'can_edit' => $item->canUpdateItem(),
            'action'   => Helpers::url('front/focal.form.php'),
            'rand'     => mt_rand(),
        ]);
        return true;
    }

    public static function handlePost(array $post): void
    {
        global $DB;
        $entity = new Entity();
        $id = (int) ($post['entities_id'] ?? -1);
        if (!$entity->getFromDB($id) || !$entity->canUpdateItem()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        foreach (Settings::ROLES as $role) {
            $users = array_unique(array_filter(array_map('intval', (array) ($post['users'][$role] ?? []))));
            $DB->delete(self::getTable(), ['entities_id' => $id, 'role' => $role]);
            foreach ($users as $user) {
                $DB->insert(self::getTable(), ['entities_id' => $id, 'role' => $role, 'users_id' => $user]);
            }
        }
        Session::addMessageAfterRedirect(__('Pontos focais salvos.', 'contractlifecycle'));
    }

    public static function entityPurged(Entity $entity): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['entities_id' => (int) $entity->getID()]);
    }
}
