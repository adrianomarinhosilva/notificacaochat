<?php
// ajax/createTicket.php

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
    error_log("[CreateTicket] " . $msg);
}

try {
    // Inclui GLPI
    define('GLPI_USE_CSRF_CHECK', false);
    include ("../../../inc/includes.php");
    
    logMsg("=== INÍCIO DA CRIAÇÃO DE TICKET ===");
    
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
    
    // Lê dados JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(false, 'Dados inválidos');
    }
    
    // Verifica ação
    $action = isset($input['action']) ? $input['action'] : '';
    if ($action !== 'create_ticket_from_chat') {
        jsonResponse(false, 'Ação inválida');
    }
    
    // Coleta parâmetros
    $entity_id = isset($input['entity_id']) ? intval($input['entity_id']) : 0;
    $category_id = isset($input['category_id']) ? intval($input['category_id']) : 0;
    $title = isset($input['title']) ? trim($input['title']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $other_user_id = isset($input['other_user_id']) ? intval($input['other_user_id']) : 0;
    $other_user_name = isset($input['other_user_name']) ? trim($input['other_user_name']) : '';
    $messages = isset($input['messages']) ? $input['messages'] : [];
    $total_messages = isset($input['total_messages']) ? intval($input['total_messages']) : 0;
    
    logMsg("Parâmetros: entity=$entity_id, category=$category_id, other_user=$other_user_id, total=$total_messages");
    
    // Validações
    if ($entity_id < 0) { // Permite entity_id = 0 (entidade raiz)
        jsonResponse(false, 'Entidade é obrigatória');
    }
    
    if (empty($title)) {
        jsonResponse(false, 'Título é obrigatório');
    }
    
    if (empty($description)) {
        jsonResponse(false, 'Descrição é obrigatória');
    }
    
    if ($other_user_id <= 0) {
        jsonResponse(false, 'Usuário da conversa inválido');
    }
    
    if (empty($messages)) {
        jsonResponse(false, 'Nenhuma mensagem para incluir no ticket');
    }
    
    // Verifica entidade (permite ID 0)
    $entity = new Entity();
    if (!$entity->getFromDB($entity_id)) {
        jsonResponse(false, "Entidade #$entity_id não encontrada");
    }
    
    logMsg("Entidade encontrada: " . $entity->fields['name']);
    
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
    
    // Monta conteúdo do ticket com formatação HTML correta
$content = '';

// Processa a descrição removendo HTML excessivo mas mantendo formatação básica
$description_processed = $description;

// Lista de tags HTML permitidas
$allowed_tags = '<p><br><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><span><div>';

// Remove tags perigosas mas mantém formatação
$description_processed = strip_tags($description_processed, $allowed_tags);

// Limpa estilos inline excessivos
$description_processed = preg_replace('/style="[^"]*"/i', '', $description_processed);

// Remove atributos desnecessários
$description_processed = preg_replace('/(?:class|id|title|data-[^=]*)="[^"]*"/i', '', $description_processed);

// Garante que não há HTML entities mal formados
$description_processed = html_entity_decode($description_processed, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Adiciona a descrição processada
$content = $description_processed . "<br><br>";

$content .= "<p><strong>🗨️ CONVERSA EXPORTADA DO CHAT</strong></p>";
$content .= "<hr>";

$content .= "<p><strong>👥 PARTICIPANTES:</strong> " . htmlspecialchars($current_user_name) . " e " . htmlspecialchars($other_user_name) . "</p>";
$content .= "<p><strong>📅 DATA:</strong> " . date('d/m/Y H:i:s') . "</p>";
$content .= "<p><strong>📊 MENSAGENS:</strong> " . count($messages) . "</p>";

$content .= "<hr>";
    
    // Adiciona cada mensagem estilo WhatsApp
    foreach ($messages as $i => $msg) {
        $sender = isset($msg['sender']) ? trim($msg['sender']) : 'Usuário';
        $text = isset($msg['content']) ? trim($msg['content']) : '';
        $time = isset($msg['time']) ? trim($msg['time']) : date('H:i');
        
        // Limpa caracteres problemáticos e tags HTML
        $sender = strip_tags($sender);
        $sender = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $sender);
        
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        // Remove múltiplos espaços e quebras de linha
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Remove caracteres especiais do horário
        $time = preg_replace('/[^0-9:\-\s]/', '', $time);
        $time = trim($time);
        
        if (empty($text)) {
            continue;
        }
        
        // Formato WhatsApp: [15:30] João: Olá, tudo bem?
        $content .= "[" . $time . "] " . $sender . ": " . $text . "\n";
    }
    
    $content .= "\n═══════════════════════════════════════\n";
    $content .= "✅ Conversa exportada automaticamente pelo Chat GLPI\n";
    $content .= "🕐 Exportado em: " . date('d/m/Y H:i:s') . " por " . $current_user_name;
    
    logMsg("Conteúdo preparado: " . strlen($content) . " caracteres");
    
    // Cria ticket
    $ticket = new Ticket();
    
    $ticket_data = [
        'name' => $title,
        'content' => $content,
        'entities_id' => $entity_id,
        'users_id_recipient' => $current_user_id,
        'status' => Ticket::INCOMING,
        'urgency' => 3,
        'impact' => 3,
        'priority' => 3,
        'requesttypes_id' => 1, // Incident
        'type' => Ticket::INCIDENT_TYPE,
        'date' => date('Y-m-d H:i:s'),
        'date_creation' => date('Y-m-d H:i:s')
    ];
    
    // Adiciona categoria se especificada
    if ($category_id > 0) {
        $ticket_data['itilcategories_id'] = $category_id;
    }
    
    logMsg("Criando ticket com dados: " . json_encode($ticket_data));
    
    $ticket_id = $ticket->add($ticket_data);
    
    if ($ticket_id && $ticket_id > 0) {
        logMsg("SUCCESS: Ticket criado com ID $ticket_id");
        
        // Adiciona o outro usuário como observador
        try {
            $ticket_user = new Ticket_User();
            $ticket_user_data = [
                'tickets_id' => $ticket_id,
                'users_id' => $other_user_id,
                'type' => CommonITILActor::OBSERVER
            ];
            
            $ticket_user_id = $ticket_user->add($ticket_user_data);
            
            if ($ticket_user_id) {
                logMsg("Usuário $other_user_id adicionado como observador com ID $ticket_user_id");
            } else {
                logMsg("AVISO: Não foi possível adicionar usuário $other_user_id como observador");
            }
        } catch (Exception $e) {
            logMsg("ERRO ao adicionar observador: " . $e->getMessage());
        }
        
        // URL do ticket
        global $CFG_GLPI;
        $ticket_url = $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id;
        
        // Resposta de sucesso
        jsonResponse(true, 'Ticket criado com sucesso!', [
            'ticket_id' => $ticket_id,
            'ticket_url' => $ticket_url,
            'messages_count' => count($messages),
            'entity_name' => $entity->fields['name'],
            'entity_id' => $entity_id,
            'category_id' => $category_id,
            'title' => $title,
            'created_by' => $current_user_name,
            'conversation_with' => $other_user_name
        ]);
        
    } else {
        logMsg("ERRO: Falha ao criar ticket - ticket->add() retornou: " . var_export($ticket_id, true));
        
        // Verifica se há erros específicos
        if (isset($ticket->input) && !empty($ticket->input)) {
            logMsg("Dados de input do ticket: " . json_encode($ticket->input));
        }
        
        jsonResponse(false, 'Erro ao criar ticket no sistema GLPI');
    }
    
} catch (Exception $e) {
    logMsg("EXCEPTION: " . $e->getMessage());
    logMsg("Stack trace: " . $e->getTraceAsString());
    jsonResponse(false, 'Erro interno do servidor: ' . $e->getMessage());
} catch (Error $e) {
    logMsg("FATAL ERROR: " . $e->getMessage());
    logMsg("Stack trace: " . $e->getTraceAsString());
    jsonResponse(false, 'Erro fatal do servidor: ' . $e->getMessage());
}

if (ob_get_level()) ob_end_flush();
?>