<?php
// Configuração inicial
ob_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

// Função para resposta JSON
function jsonResponse($success, $message, $data = []) {
    if (ob_get_level()) ob_clean();
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Função de log
function logMsg($msg) {
    error_log("[SaveChat] " . $msg);
}

try {
    // Inclui GLPI
    define('GLPI_USE_CSRF_CHECK', false);
    include ("../../../inc/includes.php");
    
    logMsg("=== INÍCIO DO SALVAMENTO ===");
    
    // Verifica autenticação
    if (!isset($_SESSION['glpiID'])) {
        jsonResponse(false, 'Usuário não autenticado');
    }
    
    $current_user_id = intval($_SESSION['glpiID']);
    logMsg("Usuário: $current_user_id");
    
    // Verifica método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Método não permitido');
    }
    
    // Verifica ação
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action !== 'save_chat_conversation') {
        jsonResponse(false, 'Ação inválida');
    }
    
    // Coleta parâmetros de forma mais simples
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $other_user_id = isset($_POST['other_user_id']) ? intval($_POST['other_user_id']) : 0;
    $other_user_name = isset($_POST['other_user_name']) ? trim($_POST['other_user_name']) : '';
    $total_messages = isset($_POST['total_messages']) ? intval($_POST['total_messages']) : 0;
    
    logMsg("Parâmetros: ticket=$ticket_id, other_user=$other_user_id, total=$total_messages");
    
    // Validações
    if ($ticket_id <= 0) {
        jsonResponse(false, 'ID do ticket inválido');
    }
    
    if ($other_user_id <= 0) {
        jsonResponse(false, 'ID do usuário inválido');
    }
    
    if ($total_messages <= 0) {
        jsonResponse(false, 'Nenhuma mensagem para salvar');
    }
    
    // Coleta mensagens dos parâmetros POST
    $messages = [];
    if (isset($_POST['messages']) && is_array($_POST['messages'])) {
        foreach ($_POST['messages'] as $index => $msg) {
            if (is_array($msg) && isset($msg['content']) && !empty(trim($msg['content']))) {
                $messages[] = [
                    'sender' => isset($msg['sender']) ? trim($msg['sender']) : 'Usuário',
                    'content' => trim($msg['content']),
                    'time' => isset($msg['time']) ? trim($msg['time']) : 'Sem horário',
                    'is_sent' => isset($msg['is_sent']) && $msg['is_sent'] === '1'
                ];
            }
        }
    }
    
    if (empty($messages)) {
        jsonResponse(false, 'Nenhuma mensagem válida encontrada');
    }
    
    logMsg("Mensagens coletadas: " . count($messages));
    
    // Verifica ticket
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) {
        jsonResponse(false, "Ticket #$ticket_id não encontrado");
    }
    
    logMsg("Ticket encontrado: " . $ticket->fields['name']);
    
    // Obtém usuário atual
    $current_user = new User();
    $current_user->getFromDB($current_user_id);
    $current_user_name = $current_user->getFriendlyName() ?: "Usuário #$current_user_id";
    
    if (empty($other_user_name)) {
        $other_user = new User();
        if ($other_user->getFromDB($other_user_id)) {
            $other_user_name = $other_user->getFriendlyName();
        } else {
            $other_user_name = "Usuário #$other_user_id";
        }
    }
    
    logMsg("Participantes: '$current_user_name' e '$other_user_name'");
    
    // Monta conteúdo do followup
    $content = "📞 CONVERSA DO CHAT SALVA AUTOMATICAMENTE\n\n";
    $content .= "════════════════════════════════════════════\n\n";
    $content .= "📋 INFORMAÇÕES:\n";
    $content .= "• Participante 1: $current_user_name\n";
    $content .= "• Participante 2: $other_user_name\n";
    $content .= "• Data/Hora: " . date('d/m/Y H:i:s') . "\n";
    $content .= "• Total de mensagens: " . count($messages) . "\n\n";
    $content .= "════════════════════════════════════════════\n\n";
    $content .= "💬 CONVERSA:\n\n";
    
    // Adiciona cada mensagem
    foreach ($messages as $i => $msg) {
        $sender = $msg['sender'];
        $text = $msg['content'];
        $time = $msg['time'];
        
        // Remove caracteres problemáticos
        $sender = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $sender);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        $content .= "[$time] $sender:\n";
        $content .= "$text\n\n";
    }
    
    $content .= "════════════════════════════════════════════\n\n";
    $content .= "ℹ️ Conversa salva automaticamente pelo sistema de chat\n";
    $content .= "📅 Salvo em: " . date('d/m/Y H:i:s') . "\n";
    $content .= "👤 Salvo por: $current_user_name";
    
    logMsg("Conteúdo preparado: " . strlen($content) . " caracteres");
    
    // Cria followup
    $followup = new ITILFollowup();
    
    $followup_data = [
        'itemtype' => 'Ticket',
        'items_id' => $ticket_id,
        'users_id' => $current_user_id,
        'content' => $content,
        'is_private' => 0,
        'date' => date('Y-m-d H:i:s')
    ];
    
    logMsg("Criando ITILFollowup...");
    
    $followup_id = $followup->add($followup_data);
    
    if ($followup_id && $followup_id > 0) {
        logMsg("SUCCESS: ITILFollowup criado com ID $followup_id");
        
        jsonResponse(true, 'Conversa salva como acompanhamento com sucesso!', [
            'followup_id' => $followup_id,
            'ticket_id' => $ticket_id,
            'messages_count' => count($messages)
        ]);
        
    } else {
        logMsg("ERRO: Falha ao criar ITILFollowup");
        jsonResponse(false, 'Erro ao criar acompanhamento no ticket');
    }
    
} catch (Exception $e) {
    logMsg("EXCEPTION: " . $e->getMessage());
    jsonResponse(false, 'Erro interno do servidor: ' . $e->getMessage());
}

if (ob_get_level()) ob_end_flush();
?>