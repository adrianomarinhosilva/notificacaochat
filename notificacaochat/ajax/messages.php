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

// Verifica se a tabela de mensagens existe
if (!$DB->tableExists('glpi_plugin_notificacaochat_messages')) {
    $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_messages` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `from_id` int(11) NOT NULL,
             `to_id` int(11) NOT NULL,
             `content` text COLLATE utf8_unicode_ci NOT NULL,
             `date_creation` datetime NOT NULL,
             `is_read` tinyint(1) NOT NULL DEFAULT '0',
             `is_system_message` tinyint(1) NOT NULL DEFAULT '0',
             `system_message_type` varchar(50) DEFAULT NULL,
             `related_item_type` varchar(100) DEFAULT NULL,
             `related_item_id` int(11) DEFAULT NULL,
             `system_data` text DEFAULT NULL,
             PRIMARY KEY (`id`),
             KEY `from_id` (`from_id`),
             KEY `to_id` (`to_id`),
             KEY `is_system_message` (`is_system_message`),
             KEY `system_message_type` (`system_message_type`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
    
    $DB->query($query);
}

switch ($action) {
    case 'sendMessage':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Método não permitido']);
            break;
        }

        if (!isset($_POST['to_id']) || !isset($_POST['message'])) {
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
            break;
        }

        $from_id = $current_user_id;
        $to_id = intval($_POST['to_id']);
        $message = trim($_POST['message']);

        if ($to_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Destinatário inválido']);
            break;
        }

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
            break;
        }

        $messageEscaped = $DB->escape($message);

        $query = "INSERT INTO glpi_plugin_notificacaochat_messages
                  (from_id, to_id, content, date_creation, is_read)
                  VALUES ($from_id, $to_id, '$messageEscaped', NOW(), 0)";

        $result = $DB->query($query);

        if ($result) {
            $messageId = $DB->insertId();
            
            // Atualiza o status online do remetente
            if ($DB->tableExists('glpi_plugin_notificacaochat_online_users')) {
                $onlineQuery = "REPLACE INTO glpi_plugin_notificacaochat_online_users
                          (users_id, last_active)
                          VALUES ($from_id, NOW())";
                $DB->query($onlineQuery);
            }
            
            echo json_encode(['success' => true, 'message_id' => $messageId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar mensagem', 'db_error' => $DB->error()]);
        }
        break;

    case 'getNewMessages':
        $query = "SELECT m.id, m.from_id, m.to_id, m.content, m.date_creation,
                  m.is_system_message, m.system_message_type, m.related_item_type,
                  m.related_item_id, m.system_data,
                  CASE 
                    WHEN m.is_system_message = 1 THEN 'Sistema GLPI'
                    ELSE CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.realname, ''))
                  END as from_name,
                  u.name as username
                  FROM glpi_plugin_notificacaochat_messages m
                  LEFT JOIN glpi_users u ON u.id = m.from_id AND m.is_system_message = 0
                  WHERE m.to_id = $current_user_id
                  AND m.is_read = 0
                  ORDER BY m.date_creation DESC
                  LIMIT 50";

        $result = $DB->query($query);
        $messages = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $message = [
                    'id' => $data['id'],
                    'from_id' => $data['from_id'],
                    'content' => $data['content'],
                    'date_creation' => $data['date_creation'],
                    'is_system_message' => ($data['is_system_message'] == 1),
                    'system_message_type' => $data['system_message_type']
                ];
                
                if ($data['is_system_message'] == 1) {
                    $message['from_name'] = '🤖 Sistema GLPI';
                    $message['from_id'] = 0;
                    
                    if ($data['system_data']) {
                        $decoded_data = json_decode($data['system_data'], true);
                        $message['system_data'] = $decoded_data;
                    }
                } else {
                    $from_name = trim($data['from_name']);
                    if (empty($from_name)) {
                        $from_name = $data['username'] ?: "Usuário #{$data['from_id']}";
                    }
                    $message['from_name'] = $from_name;
                }
                
                $messages[] = $message;
            }
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    case 'getChatHistory':
        $other_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

        if ($other_user_id < 0) {
            echo json_encode(['success' => false, 'error' => 'ID de usuário inválido']);
            break;
        }

        if ($other_user_id == 0) {
            // Apenas mensagens do sistema NÃO LIDAS
            $query = "SELECT id, from_id, to_id, content, date_creation as date, 
                      is_system_message, system_message_type, related_item_type, 
                      related_item_id, system_data
                      FROM glpi_plugin_notificacaochat_messages
                      WHERE to_id = $current_user_id 
                      AND is_system_message = 1
                      AND is_read = 0
                      ORDER BY date_creation ASC
                      LIMIT 100";
        } else {
            // Mensagens entre usuários específicos (código existente continua igual)
            $query = "SELECT id, from_id, to_id, content, date_creation as date, 
                      is_system_message, system_message_type, related_item_type, 
                      related_item_id, system_data
                      FROM glpi_plugin_notificacaochat_messages
                      WHERE ((from_id = $current_user_id AND to_id = $other_user_id)
                         OR (from_id = $other_user_id AND to_id = $current_user_id))
                      ORDER BY date_creation ASC
                      LIMIT 100";
        }

        $result = $DB->query($query);
        $messages = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $message = [
                    'id' => $data['id'],
                    'content' => $data['content'],
                    'date' => $data['date'],
                    'is_sender' => ($data['from_id'] == $current_user_id),
                    'is_system_message' => ($data['is_system_message'] == 1),
                    'system_message_type' => $data['system_message_type'],
                    'related_item_type' => $data['related_item_type'],
                    'related_item_id' => $data['related_item_id']
                ];
                
                if ($data['system_data']) {
                    $decoded_data = json_decode($data['system_data'], true);
                    $message['system_data'] = $decoded_data;
                }
                
                $messages[] = $message;
                
                // Marca como lida se for uma mensagem recebida
                if (($data['from_id'] == $other_user_id && $data['to_id'] == $current_user_id) ||
                    ($data['from_id'] == 0 && $data['to_id'] == $current_user_id && $data['is_system_message'] == 1)) {
                    $updateQuery = "UPDATE glpi_plugin_notificacaochat_messages
                                  SET is_read = 1
                                  WHERE id = {$data['id']}";
                    $DB->query($updateQuery);
                }
            }
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    case 'getSystemConversation':
        $query = "SELECT id, from_id, to_id, content, date_creation as date, 
                  is_system_message, system_message_type, related_item_type, 
                  related_item_id, system_data
                  FROM glpi_plugin_notificacaochat_messages
                  WHERE to_id = $current_user_id 
                  AND is_system_message = 1
                  AND is_read = 0
                  ORDER BY date_creation ASC
                  LIMIT 100";

        $result = $DB->query($query);
        $messages = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $message = [
                    'id' => $data['id'],
                    'content' => $data['content'],
                    'date' => $data['date'],
                    'is_sender' => false,
                    'is_system_message' => true,
                    'system_message_type' => $data['system_message_type'],
                    'related_item_type' => $data['related_item_type'],
                    'related_item_id' => $data['related_item_id']
                ];
                
                if ($data['system_data']) {
                    $decoded_data = json_decode($data['system_data'], true);
                    $message['system_data'] = $decoded_data;
                }
                
                $messages[] = $message;
            }
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    case 'getSystemMessages':
        $query = "SELECT id, content, date_creation, system_message_type, 
                  related_item_type, related_item_id, system_data
                  FROM glpi_plugin_notificacaochat_messages
                  WHERE to_id = $current_user_id
                  AND from_id = 0
                  AND is_system_message = 1
                  AND is_read = 0
                  ORDER BY date_creation DESC
                  LIMIT 20";

        $result = $DB->query($query);
        $messages = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $message = [
                    'id' => $data['id'],
                    'content' => $data['content'],
                    'date_creation' => $data['date_creation'],
                    'system_message_type' => $data['system_message_type'],
                    'related_item_type' => $data['related_item_type'],
                    'related_item_id' => $data['related_item_id']
                ];
                
                if ($data['system_data']) {
                    $message['system_data'] = json_decode($data['system_data'], true);
                }
                
                $messages[] = $message;
            }
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    case 'markMessagesAsRead':
        $from_id = isset($_GET['from_id']) ? intval($_GET['from_id']) : 0;

        if ($from_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID do remetente inválido']);
            break;
        }

        $query = "UPDATE glpi_plugin_notificacaochat_messages 
                  SET is_read = 1 
                  WHERE from_id = $from_id 
                  AND to_id = $current_user_id 
                  AND is_read = 0";

        $result = $DB->query($query);
        $affected_rows = $DB->affectedRows();

        echo json_encode(['success' => true, 'marked' => $affected_rows]);
        break;

    case 'clearConversation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Método não permitido']);
            break;
        }

        if (!isset($_POST['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
            break;
        }

        $other_user_id = intval($_POST['user_id']);

        if ($other_user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de usuário inválido']);
            break;
        }

        $query = "DELETE FROM glpi_plugin_notificacaochat_messages 
                  WHERE (from_id = $current_user_id AND to_id = $other_user_id)
                     OR (from_id = $other_user_id AND to_id = $current_user_id)";

        $result = $DB->query($query);
        $affected_rows = $DB->affectedRows();

        if ($result) {
            echo json_encode(['success' => true, 'deleted' => $affected_rows]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao deletar mensagens']);
        }
        break;

    case 'checkMessages':
        if (!PluginNotificacaochatProfile::canUse()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }

        $last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
        $last_check_date = date('Y-m-d H:i:s', $last_check / 1000);

        PluginNotificacaochatUser::updateOnlineStatus($current_user_id);

        $query = "SELECT m.id, m.from_id, m.content, m.date_creation,
                  CONCAT(u.firstname, ' ', u.realname) as from_name, u.name as username
                  FROM glpi_plugin_notificacaochat_messages m
                  JOIN glpi_users u ON u.id = m.from_id
                  WHERE m.to_id = $current_user_id
                  AND m.is_read = 0
                  AND m.date_creation > '$last_check_date'
                  ORDER BY m.date_creation DESC
                  LIMIT 10";

        $result = $DB->query($query);
        $messages = [];

        if ($result && $DB->numrows($result) > 0) {
            while ($data = $DB->fetchAssoc($result)) {
                $from_name = trim($data['from_name']);
                if (empty($from_name)) {
                    $from_name = $data['username'] ?: "Usuário #{$data['from_id']}";
                }
                
                $messages[] = [
                    'id' => $data['id'],
                    'from_id' => $data['from_id'],
                    'from_name' => $from_name,
                    'content' => $data['content'],
                    'date_creation' => $data['date_creation']
                ];
                
                $update_query = "UPDATE glpi_plugin_notificacaochat_messages 
                                SET is_read = 1 
                                WHERE id = " . $data['id'];
                $DB->query($update_query);
            }
        }

        echo json_encode([
            'messages' => $messages,
            'timestamp' => time() * 1000
        ]);
        break;

     case 'markSystemMessageAsRead':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Método não permitido']);
            break;
        }

        if (!isset($_POST['message_id'])) {
            echo json_encode(['success' => false, 'error' => 'ID da mensagem não fornecido']);
            break;
        }

        $message_id = intval($_POST['message_id']);
        $result = PluginNotificacaochatSystem::markSystemMessageAsRead($message_id, $current_user_id);
        
        echo json_encode($result);
        break;

    case 'markAllSystemMessagesAsRead':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Método não permitido']);
            break;
        }

        $result = PluginNotificacaochatSystem::markAllSystemMessagesAsRead($current_user_id);
        
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação não encontrada']);
        break;
}
?>