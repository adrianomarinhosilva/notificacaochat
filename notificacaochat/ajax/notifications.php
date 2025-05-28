<?php
// Permitir acesso independente de autenticação
define('GLPI_USE_CSRF_CHECK', false);

// Inclui os arquivos do GLPI
include ("../../../inc/includes.php");

// Define o cabeçalho para JSON
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$current_user_id = $_SESSION['glpiID'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

global $DB, $CFG_GLPI;

switch ($action) {
    case 'checkNotifications':
        // Inicializa a resposta
        $response = [
            'success' => false,
            'error' => null,
            'notifications' => [],
            'timestamp' => time() * 1000,
            'debug' => []
        ];

        $response['debug']['time'] = date('Y-m-d H:i:s');
        $response['debug']['php_version'] = PHP_VERSION;
        $response['debug']['glpi_version'] = GLPI_VERSION;

        try {
            if (!$current_user_id) {
                $response['error'] = 'Usuário não autenticado';
                echo json_encode($response);
                exit;
            }
            
            $response['debug']['session_user_id'] = $current_user_id;
            
            $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user_id;
            $last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
            $last_check_date = date('Y-m-d H:i:s', $last_check / 1000);
            
            $response['debug']['user_id'] = $user_id;
            $response['debug']['last_check'] = $last_check_date;
            
            // Verifica se as tabelas existem
            $tables_check = [
                'glpi_plugin_notificacaochat_messages' => $DB->tableExists('glpi_plugin_notificacaochat_messages'),
                'glpi_plugin_notificacaochat_online_users' => $DB->tableExists('glpi_plugin_notificacaochat_online_users')
            ];
            
            $response['debug']['tables_check'] = $tables_check;
            
            // Se a tabela de mensagens não existir, cria-a
            if (!$tables_check['glpi_plugin_notificacaochat_messages']) {
                $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_messages` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `from_id` int(11) NOT NULL,
                         `to_id` int(11) NOT NULL,
                         `content` text COLLATE utf8_unicode_ci NOT NULL,
                         `date_creation` datetime NOT NULL,
                         `is_read` tinyint(1) NOT NULL DEFAULT '0',
                         PRIMARY KEY (`id`),
                         KEY `from_id` (`from_id`),
                         KEY `to_id` (`to_id`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
                
                $DB->query($query);
                $response['debug']['created_messages_table'] = $DB->tableExists('glpi_plugin_notificacaochat_messages');
            }
            
            // Atualiza o status online do usuário
            if ($tables_check['glpi_plugin_notificacaochat_online_users']) {
                $query = "REPLACE INTO `glpi_plugin_notificacaochat_online_users` 
                          (`users_id`, `last_active`) 
                          VALUES ($user_id, NOW())";
                $DB->query($query);
            }
            
            // Busca novas mensagens desde a última verificação
            if ($tables_check['glpi_plugin_notificacaochat_messages']) {
                $query = "SELECT m.id, m.from_id, m.content, m.date_creation,
                          u.firstname, u.realname, u.name as username
                          FROM glpi_plugin_notificacaochat_messages m
                          JOIN glpi_users u ON u.id = m.from_id
                          WHERE m.to_id = $user_id
                          AND m.is_read = 0
                          AND m.date_creation > '$last_check_date'
                          ORDER BY m.date_creation DESC";
                
                $result = $DB->query($query);
                $response['debug']['query'] = $query;
                $response['debug']['query_results'] = $DB->numrows($result);
                
                if ($result && $DB->numrows($result) > 0) {
                    while ($data = $DB->fetchAssoc($result)) {
                        $from_name = trim($data['firstname'] . ' ' . $data['realname']);
                        if (empty($from_name)) {
                            $from_name = $data['username'] ?: "Usuário #{$data['from_id']}";
                        }
                        
                        $url = $CFG_GLPI['root_doc'] . '/plugins/notificacaochat/front/message.php?user_id=' . $data['from_id'];
                        
                        $response['notifications'][] = [
                            'id' => $data['id'],
                            'title' => "Nova mensagem de $from_name",
                            'message' => $data['content'],
                            'url' => $url,
                            'from_id' => $data['from_id'],
                            'from_name' => $from_name,
                            'date_creation' => $data['date_creation']
                        ];
                        
                        $update_query = "UPDATE glpi_plugin_notificacaochat_messages 
                                        SET is_read = 1 
                                        WHERE id = " . $data['id'];
                        $DB->query($update_query);
                    }
                }
            }
            
            $response['success'] = true;
        } catch (Exception $e) {
            $response['error'] = $e->getMessage();
            $response['debug']['exception'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }

        echo json_encode($response);
        break;

    case 'getUserNotificationConfig':
        if (!$current_user_id) {
            echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
            break;
        }

        $config = PluginNotificacaochatSystem::getUserNotificationConfig($current_user_id);
        echo json_encode(['success' => true, 'config' => $config]);
        break;

    case 'updateNotificationSettings':
        if (!$current_user_id) {
            echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Método não permitido']);
            break;
        }

        $config = [
            'notify_new_tickets' => isset($_POST['notify_new_tickets']),
            'notify_assigned_tickets' => isset($_POST['notify_assigned_tickets']),
            'notify_pending_approval' => isset($_POST['notify_pending_approval']),
            'notify_unassigned_tickets' => isset($_POST['notify_unassigned_tickets']),
            'notify_ticket_updates' => isset($_POST['notify_ticket_updates']),
            'notify_ticket_solutions' => isset($_POST['notify_ticket_solutions'])
        ];

        $result = PluginNotificacaochatSystem::updateUserNotificationConfig($current_user_id, $config);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Configurações atualizadas com sucesso']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao atualizar configurações']);
        }
        break;

    case 'notificationServer':
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        if (!$current_user_id) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Não autenticado']) . "\n\n";
            exit;
        }

        if (!PluginNotificacaochatProfile::canUse()) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Acesso negado']) . "\n\n";
            exit;
        }

        PluginNotificacaochatUser::updateOnlineStatus($current_user_id);

        echo "event: keepalive\n";
        echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";

        ob_flush();
        flush();

        $startTime = time();
        $timeout = 30;

        while ((time() - $startTime) < $timeout) {
            $query = "SELECT m.id, m.from_id, m.content, m.date_creation,
                      CONCAT(u.firstname, ' ', u.realname) as from_name
                      FROM glpi_plugin_notificacaochat_messages m
                      JOIN glpi_users u ON u.id = m.from_id
                      WHERE m.to_id = $current_user_id
                      AND m.is_read = 0
                      ORDER BY m.date_creation DESC
                      LIMIT 5";
            
            $result = $DB->query($query);
            
            if ($result && $DB->numrows($result) > 0) {
                $messages = [];
                
                while ($data = $DB->fetchAssoc($result)) {
                    $messages[] = [
                        'id' => $data['id'],
                        'from_id' => $data['from_id'],
                        'from_name' => $data['from_name'],
                        'content' => $data['content'],
                        'date_creation' => $data['date_creation']
                    ];
                }
                
                if (!empty($messages)) {
                    echo "event: message\n";
                    echo "data: " . json_encode(['messages' => $messages]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }
            
            sleep(5);
        }

        echo "event: close\n";
        echo "data: " . json_encode(['message' => 'Timeout']) . "\n\n";
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação não encontrada']);
        break;
}
?>