<?php
// Permitir acesso independente de autenticação
define('GLPI_USE_CSRF_CHECK', false);

// Inclui os arquivos do GLPI
include ("../../../inc/includes.php");

// Define o cabeçalho para JSON
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Verifica se o usuário está autenticado
if (!isset($_SESSION['glpiID'])) {
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
    exit;
}

$current_user_id = $_SESSION['glpiID'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

global $DB, $CFG_GLPI;

switch ($action) {
    case 'getUserId':
        echo json_encode(['success' => true, 'user_id' => $current_user_id]);
        break;

    case 'getOnlineUsers':
        // Verifica se a tabela de usuários online existe
        if (!$DB->tableExists('glpi_plugin_notificacaochat_online_users')) {
            $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_online_users` (
                     `id` int(11) NOT NULL AUTO_INCREMENT,
                     `users_id` int(11) NOT NULL,
                     `last_active` datetime NOT NULL,
                     PRIMARY KEY (`id`),
                     UNIQUE KEY `users_id` (`users_id`)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
            $DB->query($query);
        }

        // Atualiza o status online do usuário atual
        $query = "REPLACE INTO `glpi_plugin_notificacaochat_online_users` 
                  (`users_id`, `last_active`) 
                  VALUES ($current_user_id, NOW())";
        $DB->query($query);

        // Limpa usuários inativos
        $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                  WHERE `last_active` < DATE_SUB(NOW(), INTERVAL 1 MINUTE)";
        $DB->query($query);

        // Consulta para obter usuários online
        $query = "SELECT u.id, CONCAT(u.firstname, ' ', u.realname) as name, u.name as username
                  FROM glpi_users u 
                  JOIN glpi_plugin_notificacaochat_online_users ou ON u.id = ou.users_id
                  WHERE u.is_active = 1
                  AND ou.last_active > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                  ORDER BY name";

        $result = $DB->query($query);
        $users = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $display_name = trim($data['name']);
                if (empty($display_name)) {
                    $display_name = $data['username'] ?: "Usuário #{$data['id']}";
                }
                
                $user = [
                    'id' => $data['id'],
                    'name' => $display_name,
                    'group_name' => '',
                    'unread_count' => 0,
                    'tickets' => [
                        'pending' => 0,
                        'processing' => 0
                    ]
                ];
                
                // Obtém o grupo principal do usuário
                $group_query = "SELECT g.name 
                               FROM glpi_groups g
                               JOIN glpi_groups_users gu ON g.id = gu.groups_id
                               WHERE gu.users_id = {$data['id']}
                               ORDER BY g.name
                               LIMIT 1";
                
                $group_result = $DB->query($group_query);
                if ($group_result && $DB->numrows($group_result) > 0) {
                    $group_data = $DB->fetchAssoc($group_result);
                    $user['group_name'] = $group_data['name'];
                }
                
                // Obtém tickets pendentes
                $pending_query = "SELECT COUNT(*) as count 
                                 FROM glpi_tickets t
                                 JOIN glpi_tickets_users tu ON t.id = tu.tickets_id
                                 WHERE tu.users_id = {$data['id']}
                                 AND tu.type = 2
                                 AND t.status = 4
                                 AND t.is_deleted = 0";
                
                $pending_result = $DB->query($pending_query);
                if ($pending_result && $DB->numrows($pending_result) > 0) {
                    $pending_data = $DB->fetchAssoc($pending_result);
                    $user['tickets']['pending'] = (int)$pending_data['count'];
                }
                
                // Obtém tickets em processamento
                $processing_query = "SELECT COUNT(*) as count 
                                    FROM glpi_tickets t
                                    JOIN glpi_tickets_users tu ON t.id = tu.tickets_id
                                    WHERE tu.users_id = {$data['id']}
                                    AND tu.type = 2
                                    AND t.status = 2
                                    AND t.is_deleted = 0";
               
                $processing_result = $DB->query($processing_query);
                if ($processing_result && $DB->numrows($processing_result) > 0) {
                    $processing_data = $DB->fetchAssoc($processing_result);
                    $user['tickets']['processing'] = (int)$processing_data['count'];
                }
               
                $users[] = $user;
            }
        }

        echo json_encode(['success' => true, 'users' => $users]);
        break;

    case 'getAllGroups':
        $query = "SELECT DISTINCT g.id, g.name
                  FROM glpi_groups g
                  JOIN glpi_groups_users gu ON gu.groups_id = g.id
                  JOIN glpi_users u ON u.id = gu.users_id AND u.is_active = 1
                  ORDER BY g.name";

        $result = $DB->query($query);
        $groups = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $groups[] = [
                    'id' => $data['id'],
                    'name' => $data['name']
                ];
            }
        }

        echo json_encode(['groups' => $groups]);
        break;

    case 'getGroups':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        $groups = PluginNotificacaochatUser::getUserGroups($current_user_id);
        echo json_encode(['groups' => $groups]);
        break;

    case 'getEntities':
    // Para debug - mostra todas as entidades com level
    $query = "SELECT e.id, e.name, e.completename, e.level, e.entities_id
              FROM glpi_entities e
              ORDER BY e.level ASC, e.name ASC";

    $result = $DB->query($query);
    $entities = [];

    if ($result && $DB->numrows($result) > 0) {
        while ($data = $DB->fetchAssoc($result)) {
            $display_name = !empty($data['completename']) ? $data['completename'] : $data['name'];
            
            // Adiciona indicadores visuais baseado no level
            $prefix = str_repeat("  ", $data['level']); // Indentação por level
            if ($data['level'] == 0) {
                $prefix .= "🏢 ";
            } elseif ($data['level'] == 1) {
                $prefix .= "🏪 ";
            } else {
                $prefix .= "🏬 ";
            }
            
            $display_name = $prefix . $display_name . " (Level: " . $data['level'] . ")";
            
            $entities[] = [
                'id' => $data['id'],
                'name' => $display_name,
                'level' => $data['level'],
                'parent_id' => $data['entities_id']
            ];
        }
    }

    echo json_encode(['success' => true, 'entities' => $entities]);
    break;

case 'getCategories':
    // Busca categorias de ticket disponíveis
    $query = "SELECT id, name, completename
              FROM glpi_itilcategories
              WHERE (is_helpdeskvisible = 1 OR is_helpdeskvisible IS NULL)
              AND (is_incident = 1 OR is_request = 1)
              ORDER BY completename, name";

    $result = $DB->query($query);
    $categories = [];

    if ($result && $DB->numrows($result) > 0) {
        while ($data = $DB->fetchAssoc($result)) {
            $display_name = !empty($data['completename']) ? $data['completename'] : $data['name'];
            $categories[] = [
                'id' => $data['id'],
                'name' => $display_name
            ];
        }
    }

    echo json_encode(['success' => true, 'categories' => $categories]);
    break;

    case 'userLogout':
        $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                  WHERE `users_id` = $current_user_id";
        $DB->query($query);
        
        echo json_encode(['success' => true]);
        break;

    case 'getUpdates':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        PluginNotificacaochatUser::updateOnlineStatus($current_user_id);

        $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                  WHERE `last_active` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
        $DB->query($query);

        echo json_encode([
            'changes' => true,
            'timestamp' => time() * 1000,
        ]);
        break;

    case 'getConfig':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        $config = PluginNotificacaochatConfig::getConfig();
        echo json_encode(['config' => $config]);
        break;

    case 'getUserTickets':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        $user_id = intval($_GET['user_id'] ?? 0);
        $status = $_GET['status'] ?? 'all';

        if ($user_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de usuário não fornecido']);
            break;
        }

        $user = new User();
        if ($user->getFromDB($user_id)) {
            $user_name = $user->getFriendlyName();
        } else {
            $user_name = 'Usuário #' . $user_id;
        }

        $tickets = PluginNotificacaochatTicket::getUserTickets($user_id, $status);

        echo json_encode([
            'user_name' => $user_name,
            'tickets' => $tickets
        ]);
        break;

    case 'getTicketsInfo':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        $users_id = intval($_GET['users_id'] ?? 0);

        if ($users_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de usuário não fornecido']);
            break;
        }

        $tickets_info = PluginNotificacaochatNotificacao::getTicketsInfo([$users_id]);

        echo json_encode(['tickets_info' => $tickets_info[$users_id] ?? []]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação não encontrada']);
        break;
}
?>