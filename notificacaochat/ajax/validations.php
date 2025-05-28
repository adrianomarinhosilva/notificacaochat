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
    case 'getPendingValidations':
        error_log("[Chat] Verificando validações pendentes para usuário $current_user_id");

        $query = "SELECT tv.id as validation_id, tv.tickets_id, tv.comment_submission, 
                  tv.submission_date, tv.users_id as requester_id, tv.status as validation_status,
                  t.name as ticket_title, t.content as ticket_content, t.priority,
                  t.status as ticket_status, t.urgency, t.impact,
                  u.firstname, u.realname, u.name as username
                  FROM glpi_ticketvalidations tv
                  JOIN glpi_tickets t ON t.id = tv.tickets_id
                  LEFT JOIN glpi_users u ON u.id = tv.users_id
                  WHERE tv.users_id_validate = $current_user_id
                  AND tv.status = " . TicketValidation::WAITING . "
                  AND t.is_deleted = 0
                  ORDER BY tv.submission_date ASC";

        $result = $DB->query($query);
        $validations = [];

        if ($result && $DB->numrows($result) > 0) {
            error_log("[Chat] Encontradas " . $DB->numrows($result) . " validações pendentes");
            
            while ($data = $DB->fetchAssoc($result)) {
                $requester_name = trim($data['firstname'] . ' ' . $data['realname']);
                if (empty($requester_name)) {
                    $requester_name = $data['username'] ?: "Usuário #{$data['requester_id']}";
                }
                
                // Remove limitador de caracteres e formata HTML corretamente
// Formata HTML corretamente sem limitador de caracteres
$description = $data['ticket_content'];

// Lista de tags HTML permitidas (mais completa)
$allowed_tags = '<p><br><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><span><div><a>';

// Remove tags perigosas mas mantém formatação
$description = strip_tags($description, $allowed_tags);

// Limpa estilos inline excessivos mas mantém o conteúdo
$description = preg_replace('/style="[^"]*"/i', '', $description);

// Remove atributos desnecessários mas mantém href para links
$description = preg_replace('/(?:class|id|title|data-[^=]*)="[^"]*"/i', '', $description);

// Limpa espaços múltiplos mas preserva quebras de linha importantes
$description = preg_replace('/\s{2,}/', ' ', $description);
$description = trim($description);

// Garante que não há HTML entities mal formados
$description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
               
               $ticket_title = $data['ticket_title'];
               if (empty($ticket_title)) {
                   $ticket_title = "Ticket sem título";
               }
               
               $comment_submission = $data['comment_submission'];
               if (empty($comment_submission)) {
                   $comment_submission = "Sem comentário adicional";
               }
               
               $validation = [
                   'validation_id' => intval($data['validation_id']),
                   'ticket_id' => intval($data['tickets_id']),
                   'ticket_title' => $ticket_title,
                   'ticket_description' => $description,
                   'comment_submission' => $comment_submission,
                   'submission_date' => $data['submission_date'],
                   'requester_id' => intval($data['requester_id']),
                   'requester_name' => $requester_name,
                   'priority' => intval($data['priority']),
                   'priority_name' => Ticket::getPriorityName($data['priority']),
                   'status' => intval($data['ticket_status']),
                   'status_name' => Ticket::getStatus($data['ticket_status']),
                   'urgency' => intval($data['urgency']),
                   'impact' => intval($data['impact']),
                   'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['tickets_id'],
                   'validation_status' => intval($data['validation_status'])
               ];
               
               $validations[] = $validation;
           }
       } else {
           error_log("[Chat] Nenhuma validação pendente encontrada para usuário $current_user_id");
       }

       $response = [
           'success' => true, 
           'validations' => $validations,
           'count' => count($validations),
           'user_id' => $current_user_id,
           'timestamp' => date('Y-m-d H:i:s')
       ];

       echo json_encode($response);
       break;

   case 'checkValidationStatus':
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           echo json_encode(['success' => false, 'error' => 'Método não permitido']);
           break;
       }

       $input = json_decode(file_get_contents('php://input'), true);

       if (!$input || !isset($input['validation_ids']) || !is_array($input['validation_ids'])) {
           echo json_encode(['success' => true, 'processed_ids' => []]);
           break;
       }

       $processed_ids = [];

       foreach ($input['validation_ids'] as $validation_id) {
           $validation_id = intval($validation_id);
           if ($validation_id > 0) {
               $check_query = "SELECT status FROM glpi_ticketvalidations 
                              WHERE id = $validation_id 
                              AND users_id_validate = $current_user_id";
               
               $check_result = $DB->query($check_query);
               
               if ($check_result && $DB->numrows($check_result) > 0) {
                   $validation_data = $DB->fetchAssoc($check_result);
                   
                   if ($validation_data['status'] != TicketValidation::WAITING) {
                       $processed_ids[] = $validation_id;
                       error_log("[Chat] Validação $validation_id já foi processada - Status: {$validation_data['status']}");
                   }
               } else {
                   $processed_ids[] = $validation_id;
                   error_log("[Chat] Validação $validation_id não encontrada - removendo da interface");
               }
           }
       }

       echo json_encode([
           'success' => true, 
           'processed_ids' => $processed_ids,
           'total_checked' => count($input['validation_ids'])
       ]);
       break;

   case 'markValidationsAsSeen':
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           echo json_encode(['success' => false, 'error' => 'Método não permitido']);
           break;
       }

       $input = json_decode(file_get_contents('php://input'), true);

       if (!$input || !isset($input['validation_ids']) || !is_array($input['validation_ids'])) {
           echo json_encode(['success' => true, 'message' => 'Nenhuma validação para marcar']);
           break;
       }

       foreach ($input['validation_ids'] as $validation_id) {
           $validation_id = intval($validation_id);
           if ($validation_id > 0) {
               $check_query = "SELECT id FROM glpi_ticketvalidations 
                              WHERE id = $validation_id 
                              AND users_id_validate = $current_user_id";
               
               $check_result = $DB->query($check_query);
               
               if ($check_result && $DB->numrows($check_result) > 0) {
                   $create_table_query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_validation_views` (
                                         `id` int(11) NOT NULL AUTO_INCREMENT,
                                         `users_id` int(11) NOT NULL,
                                         `validation_id` int(11) NOT NULL,
                                         `viewed_date` datetime NOT NULL,
                                         PRIMARY KEY (`id`),
                                         UNIQUE KEY `user_validation` (`users_id`, `validation_id`)
                                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
                   
                   $DB->query($create_table_query);
                   
                   $insert_query = "INSERT IGNORE INTO `glpi_plugin_notificacaochat_validation_views`
                                  (`users_id`, `validation_id`, `viewed_date`)
                                  VALUES ($current_user_id, $validation_id, NOW())";
                   
                   $DB->query($insert_query);
               }
           }
       }

       echo json_encode(['success' => true, 'message' => 'Validações marcadas como vistas']);
       break;

   case 'processValidationFromChat':
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           echo json_encode(['success' => false, 'error' => 'Método não permitido']);
           break;
       }

       $required_params = ['validation_id', 'action'];
       foreach ($required_params as $param) {
           if (!isset($_POST[$param])) {
               echo json_encode(['success' => false, 'error' => "Parâmetro '$param' é obrigatório"]);
               exit;
           }
       }

       $validation_id = intval($_POST['validation_id']);
       $action = $_POST['action'];
       $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
       $validator_id = $current_user_id;

       if ($validation_id <= 0) {
           echo json_encode(['success' => false, 'error' => 'ID de validação inválido']);
           break;
       }

       if (!in_array($action, ['approve', 'reject'])) {
           echo json_encode(['success' => false, 'error' => 'Ação inválida']);
           break;
       }

       $status = ($action === 'approve') ? TicketValidation::ACCEPTED : TicketValidation::REFUSED;

       $check_query = "SELECT tv.*, t.name as ticket_title 
                       FROM glpi_ticketvalidations tv
                       JOIN glpi_tickets t ON t.id = tv.tickets_id
                       WHERE tv.id = $validation_id 
                       AND tv.users_id_validate = $validator_id
                       AND tv.status = " . TicketValidation::WAITING;

       $check_result = $DB->query($check_query);

       if (!$check_result || $DB->numrows($check_result) == 0) {
           echo json_encode(['success' => false, 'error' => 'Validação não encontrada ou não autorizada']);
           break;
       }

       $validation_data = $DB->fetchAssoc($check_result);

       $update_query = "UPDATE glpi_ticketvalidations 
                       SET status = $status,
                           comment_validation = '" . $DB->escape($comment) . "',
                           validation_date = NOW()
                       WHERE id = $validation_id";

       if ($DB->query($update_query)) {
           $status_text = ($action === 'approve') ? 'APROVADA' : 'REJEITADA';
           $icon = ($action === 'approve') ? '✅' : '❌';
           
           $content = "{$icon} <strong>Validação {$status_text}</strong>\n\n";
           $content .= "<strong>Ticket:</strong> #{$validation_data['tickets_id']} - " . htmlspecialchars($validation_data['ticket_title']) . "\n\n";
           
           if ($action === 'approve') {
               $content .= "✅ <strong>Sua solicitação de validação foi APROVADA!</strong>\n";
           } else {
               $content .= "❌ <strong>Sua solicitação de validação foi REJEITADA.</strong>\n";
           }
           
           if (!empty($comment)) {
               $content .= "\n<strong>Comentário do Validador:</strong>\n" . htmlspecialchars($comment);
           }
           
           PluginNotificacaochatSystem::sendSystemMessage(
               $validation_data['users_id'],
               PluginNotificacaochatSystem::MSG_VALIDATION_CHAT_RESPONSE,
               $content,
               'TicketValidation',
               $validation_id,
               [
                   'ticket_id' => $validation_data['tickets_id'],
                   'validation_id' => $validation_id,
                   'status' => $status,
                   'comment' => $comment
               ]
           );
           
           echo json_encode(['success' => true, 'message' => "Validação {$status_text} com sucesso!"]);
       } else {
           echo json_encode(['success' => false, 'error' => 'Erro ao processar validação']);
       }
       break;

   case 'processValidation':
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           echo json_encode(['success' => false, 'error' => 'Método não permitido']);
           break;
       }

       $required_params = ['validation_id', 'status'];
       foreach ($required_params as $param) {
           if (!isset($_POST[$param])) {
               echo json_encode(['success' => false, 'error' => "Parâmetro '$param' é obrigatório"]);
               exit;
           }
       }

       $validation_id = intval($_POST['validation_id']);
       $status = $_POST['status'];
       $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
       $validator_id = $current_user_id;

       if ($validation_id <= 0) {
           echo json_encode(['success' => false, 'error' => 'ID de validação inválido']);
           break;
       }

       if ($status === 'approve') {
           $status = TicketValidation::ACCEPTED;
       } elseif ($status === 'reject') {
           $status = TicketValidation::REFUSED;
       } else {
           echo json_encode(['success' => false, 'error' => 'Status de validação inválido']);
           break;
       }

       $result = PluginNotificacaochatSystem::processValidationResponse(
           $validation_id,
           $validator_id,
           $status,
           $comment
       );

       echo json_encode($result);
       break;

   case 'requestValidation':
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           echo json_encode(['success' => false, 'error' => 'Método não permitido']);
           break;
       }

       $required_params = ['ticket_id', 'validator_id', 'comment'];
       foreach ($required_params as $param) {
           if (!isset($_POST[$param]) || empty(trim($_POST[$param]))) {
               echo json_encode(['success' => false, 'error' => "Parâmetro '$param' é obrigatório"]);
               exit;
           }
       }

       $ticket_id = intval($_POST['ticket_id']);
       $validator_id = intval($_POST['validator_id']);
       $comment = trim($_POST['comment']);
       $requester_id = $current_user_id;

       if ($ticket_id <= 0) {
           echo json_encode(['success' => false, 'error' => 'Número do ticket inválido']);
           break;
       }

       if ($validator_id <= 0) {
           echo json_encode(['success' => false, 'error' => 'Validador inválido']);
           break;
       }

       if (strlen($comment) < 10) {
           echo json_encode(['success' => false, 'error' => 'Comentário deve ter pelo menos 10 caracteres']);
           break;
       }

       $result = PluginNotificacaochatSystem::requestTicketValidation(
           $ticket_id,
           $validator_id,
           $requester_id,
           $comment
       );

       echo json_encode($result);
       break;

   case 'getUserValidationStatus':
       $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

       if ($user_id <= 0) {
           echo json_encode(['success' => false, 'error' => 'ID de usuário inválido']);
           break;
       }

       $canValidate = PluginNotificacaochatSystem::canUserValidateTickets($user_id);

       echo json_encode([
           'success' => true,
           'can_validate' => $canValidate,
           'user_id' => $user_id
       ]);
       break;

   default:
       echo json_encode(['success' => false, 'error' => 'Ação não encontrada']);
       break;
}
?>