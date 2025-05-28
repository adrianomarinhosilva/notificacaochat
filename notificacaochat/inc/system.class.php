<?php
/*
 -------------------------------------------------------------------------
 Chat plugin para GLPI - Sistema de Mensagens do Sistema
 -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Acesso direto não permitido");
}

/**
 * Classe para gerenciar mensagens do sistema
 */
class PluginNotificacaochatSystem extends CommonDBTM {
   
   // Tipos de mensagens do sistema - EXPANDIDOS
   const MSG_NEW_TICKET = 'new_ticket';
   const MSG_ASSIGNED_TICKET = 'assigned_ticket';
   const MSG_PENDING_APPROVAL = 'pending_approval';
   const MSG_UNASSIGNED_TICKET = 'unassigned_ticket';
   const MSG_TICKET_UPDATE = 'ticket_update';
   const MSG_TICKET_SOLUTION = 'ticket_solution';
   const MSG_TICKET_REOPENED = 'ticket_reopened';
   const MSG_TICKET_CLOSED = 'ticket_closed';
   const MSG_FOLLOWUP_ADDED = 'followup_added';
   const MSG_TASK_ADDED = 'task_added';
   const MSG_TASK_UPDATED = 'task_updated';
   const MSG_TASK_COMPLETED = 'task_completed';
   const MSG_TASK_OVERDUE = 'task_overdue';
   const MSG_VALIDATION_REQUEST = 'validation_request';
   const MSG_VALIDATION_RESPONSE = 'validation_response';
   const MSG_VALIDATION_CHAT_REQUEST = 'validation_chat_request';
   const MSG_VALIDATION_CHAT_RESPONSE = 'validation_chat_response';
   const MSG_ITILFOLLOWUP_ADDED = 'itilfollowup_added';
   const MSG_SOLUTION_PROPOSED = 'solution_proposed';
   const MSG_SOLUTION_APPROVED = 'solution_approved';
   const MSG_SOLUTION_REJECTED = 'solution_rejected';
   const MSG_SLA_ALERT = 'sla_alert';
   const MSG_SLA_BREACH = 'sla_breach';
   const MSG_ESCALATION = 'escalation';
   const MSG_APPROVAL_REQUEST = 'approval_request';
   const MSG_APPROVAL_GRANTED = 'approval_granted';
   const MSG_APPROVAL_DENIED = 'approval_denied';
   const MSG_REMINDER_DUE = 'reminder_due';
   
   /**
    * HOOK: Quando um ITILFollowup é adicionado
    */
   static function onITILFollowupAdd(CommonDBTM $followup) {
      global $CFG_GLPI, $DB;
      
      error_log("[NotificacaoChat] ===== INICIANDO PROCESSAMENTO DE ITILFOLLOWUP =====");
      error_log("[NotificacaoChat] ITILFollowup ID: " . $followup->getID());
      
      try {
         $followup_data = $followup->fields;
         $item_id = $followup_data['items_id'];
         $itemtype = $followup_data['itemtype'];
         $author_id = $followup_data['users_id'];
         $content = $followup_data['content'];
         
         error_log("[NotificacaoChat] Item ID: $item_id, ItemType: $itemtype, Author: $author_id");
         
         // Apenas processa se for um Ticket
         if ($itemtype != 'Ticket') {
            error_log("[NotificacaoChat] ItemType não é Ticket - finalizando");
            return;
         }
         
         // Obtém dados do ticket
         $ticket = new Ticket();
         if (!$ticket->getFromDB($item_id)) {
            error_log("[NotificacaoChat] ERRO: Ticket $item_id não encontrado");
            return;
         }
         
         error_log("[NotificacaoChat] Ticket carregado: " . $ticket->fields['name']);
         
         // Obtém nome do autor do followup
         $author_name = "Sistema";
         if ($author_id > 0) {
            $user = new User();
            if ($user->getFromDB($author_id)) {
               $author_name = $user->getFriendlyName();
            }
         }
         
         error_log("[NotificacaoChat] Autor do followup: $author_name (ID: $author_id)");
         
         // Busca TODOS os usuários envolvidos no ticket
         $users_to_notify = self::getTicketInvolvedUsersNew($item_id, [$author_id]);
         
         error_log("[NotificacaoChat] Usuários encontrados para notificar: " . count($users_to_notify));
         
         if (empty($users_to_notify)) {
            error_log("[NotificacaoChat] NENHUM usuário para notificar - finalizando");
            return;
         }
         
         // Prepara o conteúdo da notificação
         $followup_text = strip_tags($content);
         if (strlen($followup_text) > 200) {
            $followup_text = substr($followup_text, 0, 200) . "...";
         }
         
         $ticket_title = $ticket->fields['name'];
         if (empty($ticket_title)) {
            $ticket_title = "Ticket sem título";
         }
         
         // Envia notificação para cada usuário envolvido
         foreach ($users_to_notify as $user_data) {
            $user_id = $user_data['user_id'];
            $user_type = $user_data['type'];
            $source = $user_data['source'];
            
            error_log("[NotificacaoChat] Processando usuário $user_id (tipo: $user_type, fonte: $source)");
            
            // Monta a mensagem personalizada
            $content_message = "💬 <strong>Novo Acompanhamento - Ticket #{$item_id}</strong>\n\n";
            $content_message .= "<strong>Título:</strong> " . htmlspecialchars($ticket_title) . "\n";
            $content_message .= "<strong>Adicionado por:</strong> {$author_name}\n";
            
            // Adiciona informação sobre o tipo de envolvimento
            switch ($user_type) {
               case 'requester':
                  $content_message .= "<strong>Você é o solicitante deste ticket</strong>\n";
                  break;
               case 'observer':
                  $content_message .= "<strong>Você é observador deste ticket</strong>\n";
                  break;
               case 'assigned':
                  $content_message .= "<strong>Você é responsável por este ticket</strong>\n";
                  break;
            }
            
            $content_message .= "\n<strong>Comentário:</strong>\n" . htmlspecialchars($followup_text);
            
            $system_data = [
               'ticket_id' => $item_id,
               'ticket_title' => $ticket_title,
               'author' => $author_name,
               'author_id' => $author_id,
               'followup_id' => $followup->getID(),
               'user_role' => $user_type,
               'user_source' => $source,
               'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $item_id
            ];
            
            error_log("[NotificacaoChat] Enviando notificação para usuário $user_id...");
            
            $message_id = self::sendSystemMessage(
               $user_id,
               self::MSG_ITILFOLLOWUP_ADDED,
               $content_message,
               'ITILFollowup',
               $followup->getID(),
               $system_data
            );
            
            if ($message_id) {
               error_log("[NotificacaoChat] ✅ Notificação enviada com sucesso para usuário $user_id (ID da mensagem: $message_id)");
            } else {
               error_log("[NotificacaoChat] ❌ FALHA ao enviar notificação para usuário $user_id");
            }
         }
         
         error_log("[NotificacaoChat] ===== PROCESSAMENTO DE ITILFOLLOWUP CONCLUÍDO =====");
         
      } catch (Exception $e) {
         error_log("[NotificacaoChat] ERRO CRÍTICO no processamento de ITILFollowup: " . $e->getMessage());
         error_log("[NotificacaoChat] Stack trace: " . $e->getTraceAsString());
      }
   }
   
   /**
    * HOOK: Quando um ticket é criado
    */
   static function onTicketAdd(CommonDBTM $ticket) {
      global $DB, $CFG_GLPI;
      
      $ticket_id = $ticket->getID();
      error_log("[NotificacaoChat] PROCESSANDO NOVO TICKET ID: $ticket_id");
      
      // Aguarda um pouco para garantir que as relações foram criadas
      sleep(1);
      
      // Obtém informações do ticket
      $ticket_data = $ticket->fields;
      
      // Obtém nome do requisitante
      $requester_name = "Sistema";
      if (!empty($ticket_data['users_id_recipient'])) {
         $user = new User();
         if ($user->getFromDB($ticket_data['users_id_recipient'])) {
            $requester_name = $user->getFriendlyName();
         }
      }
      
      // Busca usuários para notificar
      $users_to_notify = self::getTicketInvolvedUsers($ticket_id);
      
      error_log("[NotificacaoChat] Usuários encontrados para notificar: " . count($users_to_notify));
      
      // Se não encontrou usuários específicos, notifica admins
      if (empty($users_to_notify)) {
         $users_to_notify = self::getAdminUsers();
         error_log("[NotificacaoChat] Notificando administradores: " . count($users_to_notify));
      }
      
      // Envia notificações
      foreach ($users_to_notify as $user_id) {
         error_log("[NotificacaoChat] Enviando notificação para usuário: $user_id");
         
         $content = "🎫 <strong>Novo Ticket #{$ticket_id}</strong>\n\n";
         $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket_data['name']) . "\n";
         $content .= "<strong>Requisitante:</strong> {$requester_name}\n";
         $content .= "<strong>Prioridade:</strong> " . Ticket::getPriorityName($ticket_data['priority']) . "\n";
         $content .= "<strong>Status:</strong> " . Ticket::getStatus($ticket_data['status']) . "\n\n";
         
         $description = strip_tags($ticket_data['content']);
         if (strlen($description) > 150) {
            $description = substr($description, 0, 150) . "...";
         }
         $content .= "<strong>Descrição:</strong>\n" . $description;
         
         $system_data = [
            'ticket_id' => $ticket_id,
            'ticket_title' => $ticket_data['name'],
            'requester' => $requester_name,
            'priority' => $ticket_data['priority'],
            'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
         ];
         
         self::sendSystemMessage(
            $user_id,
            self::MSG_NEW_TICKET,
            $content,
            'Ticket',
            $ticket_id,
            $system_data
         );
      }
   }
   
   /**
    * HOOK: Quando um ticket é atualizado
    */
   static function onTicketUpdate(CommonDBTM $ticket) {
      $ticket_id = $ticket->getID();
      $old_values = $ticket->oldvalues;
      $new_values = $ticket->fields;
      
      error_log("[NotificacaoChat] TICKET ATUALIZADO ID: $ticket_id");
      error_log("[NotificacaoChat] Campos alterados: " . json_encode($old_values));
      
      // Verifica mudança de status
      if (isset($old_values['status']) && $old_values['status'] != $new_values['status']) {
         self::handleStatusChange($ticket, $old_values['status'], $new_values['status']);
      }
      
      // Verifica outras mudanças importantes
      $important_changes = [];
      $important_fields = [
         'priority' => 'Prioridade',
         'urgency' => 'Urgência', 
         'impact' => 'Impacto'
      ];
      
      foreach ($important_fields as $field => $label) {
         if (isset($old_values[$field]) && $old_values[$field] != $new_values[$field]) {
            $important_changes[$field] = $label;
         }
      }
      
      if (!empty($important_changes)) {
         self::notifyTicketChanges($ticket, $important_changes);
      }
   }
   
   /**
    * HOOK: Quando uma tarefa é adicionada
    */
   static function onTaskAdd(CommonDBTM $task) {
      global $CFG_GLPI;
      
      error_log("[NotificacaoChat] TASK ADICIONADA ID: " . $task->getID());
      
      $task_data = $task->fields;
      $ticket_id = $task_data['tickets_id'];
      $author_id = $task_data['users_id'];
      $assigned_id = $task_data['users_id_tech'] ?? 0;
      
      // Obtém dados do ticket
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) {
         return;
      }
      
      // Obtém nome do autor
      $author_name = "Sistema";
      if ($author_id > 0) {
         $user = new User();
         if ($user->getFromDB($author_id)) {
            $author_name = $user->getFriendlyName();
         }
      }
      
      // Determina usuários para notificar
      $users_to_notify = [];
      if ($assigned_id > 0 && $assigned_id != $author_id) {
         $users_to_notify[] = $assigned_id;
      } else {
         $users_to_notify = self::getTicketInvolvedUsers($ticket_id, [$author_id]);
      }
      
      foreach ($users_to_notify as $user_id) {
         $content = "📋 <strong>Nova Tarefa - Ticket #{$ticket_id}</strong>\n\n";
         $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
         $content .= "<strong>Criada por:</strong> {$author_name}\n\n";
         
         $task_text = strip_tags($task_data['content']);
         if (strlen($task_text) > 150) {
            $task_text = substr($task_text, 0, 150) . "...";
         }
         $content .= "<strong>Descrição:</strong>\n" . $task_text;
         
         $system_data = [
            'ticket_id' => $ticket_id,
            'ticket_title' => $ticket->fields['name'],
            'author' => $author_name,
            'task_id' => $task->getID(),
            'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
         ];
         
         self::sendSystemMessage(
            $user_id,
            self::MSG_TASK_ADDED,
            $content,
            'TicketTask',
            $task->getID(),
            $system_data
         );
      }
   }
   
   /**
    * HOOK: Quando uma tarefa é atualizada
    */
   static function onTaskUpdate(CommonDBTM $task) {
      global $CFG_GLPI;
      
      $task_data = $task->fields;
      $old_values = $task->oldvalues;
      $ticket_id = $task_data['tickets_id'];
      
      // Verifica se a tarefa foi concluída
      if (isset($old_values['state']) && $old_values['state'] != $task_data['state']) {
         if ($task_data['state'] == Planning::DONE) {
            // Tarefa concluída
            $ticket = new Ticket();
            if ($ticket->getFromDB($ticket_id)) {
               $users_to_notify = self::getTicketInvolvedUsers($ticket_id);
               
               foreach ($users_to_notify as $user_id) {
                  $content = "✅ <strong>Tarefa Concluída - Ticket #{$ticket_id}</strong>\n\n";
                  $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
                  $content .= "<strong>Tarefa:</strong> " . strip_tags($task_data['content']);
                  
                  $system_data = [
                     'ticket_id' => $ticket_id,
                     'ticket_title' => $ticket->fields['name'],
                     'task_id' => $task->getID(),
                     'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
                  ];
                  
                  self::sendSystemMessage(
                     $user_id,
                     self::MSG_TASK_COMPLETED,
                     $content,
                     'TicketTask',
                     $task->getID(),
                     $system_data
                  );
               }
            }
         }
      }
   }
   
   /**
    * HOOK: Quando uma solução é adicionada
    */
   static function onSolutionAdd(CommonDBTM $solution) {
      global $CFG_GLPI;
      
      error_log("[NotificacaoChat] SOLUÇÃO ADICIONADA ID: " . $solution->getID());
      
      $solution_data = $solution->fields;
      $ticket_id = $solution_data['items_id'];
      $author_id = $solution_data['users_id'];
      
      if ($solution_data['itemtype'] != 'Ticket') {
        return;
     }
     
     // Obtém dados do ticket
     $ticket = new Ticket();
     if (!$ticket->getFromDB($ticket_id)) {
        return;
     }
     
     // Obtém nome do autor
     $author_name = "Sistema";
     if ($author_id > 0) {
        $user = new User();
        if ($user->getFromDB($author_id)) {
           $author_name = $user->getFriendlyName();
        }
     }
     
     // Obtém usuários para notificar (exceto o autor)
     $users_to_notify = self::getTicketInvolvedUsers($ticket_id, [$author_id]);
     
     foreach ($users_to_notify as $user_id) {
        $content = "✅ <strong>Solução Proposta - Ticket #{$ticket_id}</strong>\n\n";
        $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
        $content .= "<strong>Solucionado por:</strong> {$author_name}\n\n";
        
        $solution_text = strip_tags($solution_data['content']);
        if (strlen($solution_text) > 150) {
           $solution_text = substr($solution_text, 0, 150) . "...";
        }
        $content .= "<strong>Solução:</strong>\n" . $solution_text;
        $content .= "\n\n📝 <strong>Por favor, verifique se a solução resolve seu problema.</strong>";
        
        $system_data = [
           'ticket_id' => $ticket_id,
           'ticket_title' => $ticket->fields['name'],
           'author' => $author_name,
           'solution_id' => $solution->getID(),
           'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
        ];
        
        self::sendSystemMessage(
           $user_id,
           self::MSG_SOLUTION_PROPOSED,
           $content,
           'ITILSolution',
           $solution->getID(),
           $system_data
        );
     }
  }
  
  /**
   * HOOK: Quando uma solução é atualizada
   */
  static function onSolutionUpdate(CommonDBTM $solution) {
     global $CFG_GLPI;
     
     $solution_data = $solution->fields;
     $old_values = $solution->oldvalues;
     $ticket_id = $solution_data['items_id'];
     
     if ($solution_data['itemtype'] != 'Ticket') {
        return;
     }
     
     // Verifica mudança de status da solução
     if (isset($old_values['status']) && $old_values['status'] != $solution_data['status']) {
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticket_id)) {
           $users_to_notify = self::getTicketInvolvedUsers($ticket_id);
           
           $status_text = '';
           $message_type = '';
           $icon = '';
           
           if ($solution_data['status'] == CommonITILValidation::ACCEPTED) {
              $status_text = 'APROVADA';
              $message_type = self::MSG_SOLUTION_APPROVED;
              $icon = '✅';
           } elseif ($solution_data['status'] == CommonITILValidation::REFUSED) {
              $status_text = 'REJEITADA';
              $message_type = self::MSG_SOLUTION_REJECTED;
              $icon = '❌';
           }
           
           if ($status_text) {
              foreach ($users_to_notify as $user_id) {
                 $content = "{$icon} <strong>Solução {$status_text} - Ticket #{$ticket_id}</strong>\n\n";
                 $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n\n";
                 $content .= "<strong>A solução proposta foi {$status_text}.</strong>";
                 
                 $system_data = [
                    'ticket_id' => $ticket_id,
                    'ticket_title' => $ticket->fields['name'],
                    'solution_id' => $solution->getID(),
                    'solution_status' => $solution_data['status'],
                    'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
                 ];
                 
                 self::sendSystemMessage(
                    $user_id,
                    $message_type,
                    $content,
                    'ITILSolution',
                    $solution->getID(),
                    $system_data
                 );
              }
           }
        }
     }
  }
  
  /**
   * HOOK: Quando uma validação é adicionada
   */
  static function onTicketValidationAdd(CommonDBTM $validation) {
     global $CFG_GLPI;
     
     error_log("[NotificacaoChat] VALIDAÇÃO ADICIONADA ID: " . $validation->getID());
     
     $validation_data = $validation->fields;
     $ticket_id = $validation_data['tickets_id'];
     $validator_id = $validation_data['users_id_validate'];
     $requester_id = $validation_data['users_id'];
     
     if (!$ticket_id || !$validator_id) {
        return;
     }
     
     // Obtém dados do ticket
     $ticket = new Ticket();
     if (!$ticket->getFromDB($ticket_id)) {
        return;
     }
     
     // Obtém nome do solicitante
     $requester_name = "Sistema";
     if ($requester_id > 0) {
        $user = new User();
        if ($user->getFromDB($requester_id)) {
           $requester_name = $user->getFriendlyName();
        }
     }
     
     $content = "⏳ <strong>Solicitação de Validação - Ticket #{$ticket_id}</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
     $content .= "<strong>Solicitado por:</strong> {$requester_name}\n\n";
     $content .= "📝 <strong>Você foi solicitado para validar este ticket.</strong>\n";
     $content .= "Por favor, acesse o ticket para aprovar ou reprovar.";
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket->fields['name'],
        'requester' => $requester_name,
        'validation_id' => $validation->getID(),
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     self::sendSystemMessage(
        $validator_id,
        self::MSG_VALIDATION_REQUEST,
        $content,
        'TicketValidation',
        $validation->getID(),
        $system_data
     );
  }
  
  /**
   * HOOK: Quando uma validação é atualizada
   */
  static function onTicketValidationUpdate(CommonDBTM $validation) {
     global $CFG_GLPI;
     
     error_log("[NotificacaoChat] VALIDAÇÃO ATUALIZADA ID: " . $validation->getID());
     
     $validation_data = $validation->fields;
     $ticket_id = $validation_data['tickets_id'];
     $validator_id = $validation_data['users_id_validate'];
     $requester_id = $validation_data['users_id'];
     $status = $validation_data['status'];
     
     // Só notifica se foi aprovada ou rejeitada
     if ($status != TicketValidation::ACCEPTED && $status != TicketValidation::REFUSED) {
        return;
     }
     
     // Obtém dados do ticket
     $ticket = new Ticket();
     if (!$ticket->getFromDB($ticket_id)) {
        return;
     }
     
     // Obtém nome do validador
     $validator_name = "Validador";
     if ($validator_id > 0) {
        $user = new User();
        if ($user->getFromDB($validator_id)) {
           $validator_name = $user->getFriendlyName();
        }
     }
     
     $status_text = ($status == TicketValidation::ACCEPTED) ? "✅ APROVADA" : "❌ REJEITADA";
     $icon = ($status == TicketValidation::ACCEPTED) ? "✅" : "❌";
     
     $content = "{$icon} <strong>Validação {$status_text} - Ticket #{$ticket_id}</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
     $content .= "<strong>Validado por:</strong> {$validator_name}\n\n";
     
     if ($status == TicketValidation::ACCEPTED) {
        $content .= "✅ <strong>Sua solicitação de validação foi aprovada!</strong>";
     } else {
        $content .= "❌ <strong>Sua solicitação de validação foi rejeitada.</strong>";
     }
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket->fields['name'],
        'validator' => $validator_name,
        'validation_status' => $status,
        'validation_id' => $validation->getID(),
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     // Notifica o solicitante da validação
     if ($requester_id > 0) {
        self::sendSystemMessage(
           $requester_id,
           self::MSG_VALIDATION_RESPONSE,
           $content,
           'TicketValidation',
           $validation->getID(),
           $system_data
        );
     }
  }
  
  /**
   * HOOK: Quando um usuário é atribuído ao ticket
   */
  static function onTicketUserAdd(CommonDBTM $ticket_user) {
     global $CFG_GLPI;
     
     error_log("[NotificacaoChat] USUÁRIO ATRIBUÍDO AO TICKET");
     
     $ticket_user_data = $ticket_user->fields;
     $ticket_id = $ticket_user_data['tickets_id'];
     $user_id = $ticket_user_data['users_id'];
     $type = $ticket_user_data['type'];
     
     // Só notifica para atribuições (tipo 2)
     if ($type != 2) {
        return;
     }
     
     // Obtém dados do ticket
     $ticket = new Ticket();
     if (!$ticket->getFromDB($ticket_id)) {
        return;
     }
     
     // Obtém nome do requisitante
     $requester_name = "Sistema";
     if ($ticket->fields['users_id_recipient'] > 0) {
        $user = new User();
        if ($user->getFromDB($ticket->fields['users_id_recipient'])) {
           $requester_name = $user->getFriendlyName();
        }
     }
     
     $content = "👤 <strong>Ticket Atribuído #{$ticket_id}</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
     $content .= "<strong>Requisitante:</strong> {$requester_name}\n";
     $content .= "<strong>Prioridade:</strong> " . Ticket::getPriorityName($ticket->fields['priority']) . "\n";
     $content .= "<strong>Status:</strong> " . Ticket::getStatus($ticket->fields['status']) . "\n\n";
     $content .= "📝 <strong>Você foi designado para este ticket.</strong>";
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket->fields['name'],
        'requester' => $requester_name,
        'priority' => $ticket->fields['priority'],
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     self::sendSystemMessage(
        $user_id,
        self::MSG_ASSIGNED_TICKET,
        $content,
        'Ticket',
        $ticket_id,
        $system_data
     );
  }
  
  /**
   * HOOK: Quando um grupo é atribuído ao ticket
   */
  static function onGroupTicketAdd(CommonDBTM $group_ticket) {
     global $CFG_GLPI;
     
     error_log("[NotificacaoChat] GRUPO ATRIBUÍDO AO TICKET");
     
     $group_ticket_data = $group_ticket->fields;
     $ticket_id = $group_ticket_data['tickets_id'];
     $group_id = $group_ticket_data['groups_id'];
     $type = $group_ticket_data['type'];
     
     // Só notifica para atribuições (tipo 2)
     if ($type != 2) {
        return;
     }
     
     // Obtém dados do ticket
     $ticket = new Ticket();
     if (!$ticket->getFromDB($ticket_id)) {
        return;
     }
     
     // Obtém usuários do grupo
     $users_to_notify = self::getGroupUsers($group_id);
     
     if (empty($users_to_notify)) {
        return;
     }
     
     // Obtém nome do grupo
     $group_name = "Grupo #$group_id";
     $group = new Group();
     if ($group->getFromDB($group_id)) {
        $group_name = $group->fields['name'];
     }
     
     // Obtém nome do requisitante
     $requester_name = "Sistema";
     if ($ticket->fields['users_id_recipient'] > 0) {
        $user = new User();
        if ($user->getFromDB($ticket->fields['users_id_recipient'])) {
           $requester_name = $user->getFriendlyName();
        }
     }
     
     $content = "👥 <strong>Ticket Atribuído ao Grupo - #{$ticket_id}</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket->fields['name']) . "\n";
     $content .= "<strong>Grupo:</strong> {$group_name}\n";
     $content .= "<strong>Requisitante:</strong> {$requester_name}\n";
     $content .= "<strong>Prioridade:</strong> " . Ticket::getPriorityName($ticket->fields['priority']) . "\n";
     $content .= "<strong>Status:</strong> " . Ticket::getStatus($ticket->fields['status']) . "\n\n";
     $content .= "📝 <strong>Novo ticket atribuído ao seu grupo.</strong>";
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket->fields['name'],
        'group_name' => $group_name,
        'requester' => $requester_name,
        'priority' => $ticket->fields['priority'],
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     // Notifica todos os usuários do grupo
     foreach ($users_to_notify as $user_id) {
        self::sendSystemMessage(
           $user_id,
           self::MSG_NEW_TICKET,
           $content,
           'Ticket',
           $ticket_id,
           $system_data
        );
     }
  }
  
  /**
   * Manipula mudança de status do ticket
   */
  static function handleStatusChange(Ticket $ticket, $old_status, $new_status) {
     global $CFG_GLPI;
     
     $ticket_id = $ticket->getID();
     $ticket_data = $ticket->fields;
     
     // Obtém todos os usuários envolvidos no ticket
     $users_to_notify = self::getTicketInvolvedUsers($ticket_id);
     
     $status_names = Ticket::getAllStatusArray();
     $old_status_name = $status_names[$old_status] ?? "Status $old_status";
     $new_status_name = $status_names[$new_status] ?? "Status $new_status";
     
     $content = "📋 <strong>Ticket #{$ticket_id} - Mudança de Status</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket_data['name']) . "\n";
     $content .= "<strong>Status anterior:</strong> {$old_status_name}\n";
     $content .= "<strong>Novo status:</strong> {$new_status_name}\n";
     
     $message_type = self::MSG_TICKET_UPDATE;
     
     if ($new_status == Ticket::SOLVED) {
        $message_type = self::MSG_TICKET_SOLUTION;
        $content .= "\n✅ <strong>Ticket foi solucionado!</strong>";
     } elseif ($new_status == Ticket::CLOSED) {
        $message_type = self::MSG_TICKET_CLOSED;
        $content .= "\n🔒 <strong>Ticket foi fechado.</strong>";
     } elseif ($old_status == Ticket::SOLVED && $new_status != Ticket::CLOSED) {
        $message_type = self::MSG_TICKET_REOPENED;
        $content .= "\n🔄 <strong>Ticket foi reaberto.</strong>";
     }
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket_data['name'],
        'old_status' => $old_status,
        'new_status' => $new_status,
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     foreach ($users_to_notify as $user_id) {
        self::sendSystemMessage(
           $user_id,
           $message_type,
           $content,
           'Ticket',
           $ticket_id,
           $system_data
        );
     }
  }
  
  /**
   * Notifica sobre mudanças gerais no ticket
   */
  static function notifyTicketChanges(Ticket $ticket, $changes) {
     global $CFG_GLPI;
     
     $ticket_id = $ticket->getID();
     $ticket_data = $ticket->fields;
     
     $users_to_notify = self::getTicketInvolvedUsers($ticket_id);
     
     $content = "📝 <strong>Ticket Atualizado #{$ticket_id}</strong>\n\n";
     $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket_data['name']) . "\n\n";
     $content .= "<strong>Campos alterados:</strong>\n";
     
     foreach ($changes as $field => $label) {
        $content .= "• {$label}\n";
     }
     
     $system_data = [
        'ticket_id' => $ticket_id,
        'ticket_title' => $ticket_data['name'],
        'changes' => $changes,
        'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id
     ];
     
     foreach ($users_to_notify as $user_id) {
        self::sendSystemMessage(
           $user_id,
           self::MSG_TICKET_UPDATE,
           $content,
           'Ticket',
           $ticket_id,
           $system_data
        );
     }
  }
  
  /**
   * Cron para verificar tarefas em atraso
   */
  static function checkOverdueTasks($task) {
     global $DB, $CFG_GLPI;
     
     // Busca tarefas em atraso
     $query = "SELECT tt.id as task_id, tt.tickets_id, tt.name as task_name, 
               tt.plan_end_date, tt.users_id_tech, t.name as ticket_title
               FROM glpi_tickettasks tt
               JOIN glpi_tickets t ON t.id = tt.tickets_id
               WHERE tt.plan_end_date < NOW()
               AND tt.state != " . Planning::DONE . "
               AND t.is_deleted = 0
               AND tt.users_id_tech > 0";
     
     $result = $DB->query($query);
     $count = 0;
     
     if ($result && $DB->numrows($result) > 0) {
        while ($data = $DB->fetchAssoc($result)) {
           $overdue_days = floor((time() - strtotime($data['plan_end_date'])) / (24 * 3600));
           
           $content = "⏰ <strong>Tarefa em Atraso - #{$data['tickets_id']}</strong>\n\n";
           $content .= "<strong>Ticket:</strong> " . htmlspecialchars($data['ticket_title']) . "\n";
           $content .= "<strong>Tarefa:</strong> " . htmlspecialchars($data['task_name']) . "\n";
           $content .= "<strong>Vencimento:</strong> " . date('d/m/Y H:i', strtotime($data['plan_end_date'])) . "\n";
           $content .= "<strong>Atraso:</strong> {$overdue_days} dia(s)\n\n";
           $content .= "🚨 <strong>Esta tarefa precisa de atenção urgente!</strong>";
           
           $system_data = [
              'ticket_id' => $data['tickets_id'],
              'ticket_title' => $data['ticket_title'],
              'task_id' => $data['task_id'],
              'task_name' => $data['task_name'],
              'overdue_days' => $overdue_days,
              'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['tickets_id']
           ];
           
           self::sendSystemMessage(
              $data['users_id_tech'],
              self::MSG_TASK_OVERDUE,
              $content,
              'TicketTask',
              $data['task_id'],
              $system_data
           );
           
           $count++;
        }
     }
     
     if ($count > 0) {
        $task->addVolume($count);
        return 1;
     }
     
     return 0;
  }
  
  /**
   * Cron para verificar alertas de SLA
   */
  static function checkSLAAlerts($task) {
     global $DB, $CFG_GLPI;
     
     // Busca tickets próximos do vencimento do SLA (1 hora antes)
     $query = "SELECT t.id as ticket_id, t.name as ticket_title, 
               tu.users_id, t.time_to_resolve, t.internal_time_to_resolve
               FROM glpi_tickets t
               JOIN glpi_tickets_users tu ON t.id = tu.tickets_id
               WHERE tu.type = 2
               AND t.status NOT IN (" . Ticket::SOLVED . ", " . Ticket::CLOSED . ")
               AND (
                  (t.time_to_resolve IS NOT NULL AND t.time_to_resolve <= DATE_ADD(NOW(), INTERVAL 1 HOUR))
                  OR 
                  (t.internal_time_to_resolve IS NOT NULL AND t.internal_time_to_resolve <= DATE_ADD(NOW(), INTERVAL 1 HOUR))
               )";
     
     $result = $DB->query($query);
     $count = 0;
     
     if ($result && $DB->numrows($result) > 0) {
        while ($data = $DB->fetchAssoc($result)) {
           $resolve_time = $data['time_to_resolve'] ?: $data['internal_time_to_resolve'];
           $time_left = strtotime($resolve_time) - time();
           $hours_left = round($time_left / 3600, 1);
           
           if ($time_left <= 0) {
              // SLA violado
              $content = "🚨 <strong>SLA VIOLADO - Ticket #{$data['ticket_id']}</strong>\n\n";
              $content .= "<strong>Título:</strong> " . htmlspecialchars($data['ticket_title']) . "\n";
              $content .= "<strong>Prazo era:</strong> " . date('d/m/Y H:i', strtotime($resolve_time)) . "\n";
              $content .= "⚠️ <strong>O SLA foi violado! Ação imediata necessária.</strong>";
              
              $message_type = self::MSG_SLA_BREACH;
           } else {
              // SLA próximo do vencimento
              $content = "⏰ <strong>Alerta de SLA - Ticket #{$data['ticket_id']}</strong>\n\n";
              $content .= "<strong>Título:</strong> " . htmlspecialchars($data['ticket_title']) . "\n";
              $content .= "<strong>Prazo:</strong> " . date('d/m/Y H:i', strtotime($resolve_time)) . "\n";
              $content .= "<strong>Tempo restante:</strong> {$hours_left} hora(s)\n\n";
              $content .= "⚠️ <strong>SLA próximo do vencimento!</strong>";
              
              $message_type = self::MSG_SLA_ALERT;
           }
           
           $system_data = [
              'ticket_id' => $data['ticket_id'],
              'ticket_title' => $data['ticket_title'],
              'resolve_time' => $resolve_time,
              'hours_left' => $hours_left,
              'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['ticket_id']
           ];
           
           self::sendSystemMessage(
              $data['users_id'],
              $message_type,
              $content,
              'Ticket',
              $data['ticket_id'],
              $system_data
           );
           
           $count++;
        }
     }
     
     if ($count > 0) {
        $task->addVolume($count);
        return 1;
     }
     
     return 0;
  }
  
  /**
   * Cron para verificar aprovações pendentes
   */
  static function checkPendingApprovals($task) {
     global $DB, $CFG_GLPI;
     
     // Busca validações pendentes há mais de 24 horas
     $query = "SELECT tv.id as validation_id, tv.tickets_id, tv.users_id_validate,
               tv.submission_date, t.name as ticket_title
               FROM glpi_ticketvalidations tv
               JOIN glpi_tickets t ON t.id = tv.tickets_id
               WHERE tv.status = " . TicketValidation::WAITING . "
               AND tv.submission_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND t.is_deleted = 0";
     
     $result = $DB->query($query);
     $count = 0;
     
     if ($result && $DB->numrows($result) > 0) {
        while ($data = $DB->fetchAssoc($result)) {
           $days_pending = floor((time() - strtotime($data['submission_date'])) / (24 * 3600));
           
           $content = "📅 <strong>Validação Pendente - #{$data['tickets_id']}</strong>\n\n";
           $content .= "<strong>Ticket:</strong> " . htmlspecialchars($data['ticket_title']) . "\n";
           $content .= "<strong>Solicitado em:</strong> " . date('d/m/Y H:i', strtotime($data['submission_date'])) . "\n";
           $content .= "<strong>Pendente há:</strong> {$days_pending} dia(s)\n\n";
           $content .= "⏳ <strong>Lembrete: Esta validação está aguardando sua aprovação.</strong>";
           
           $system_data = [
              'ticket_id' => $data['tickets_id'],
              'ticket_title' => $data['ticket_title'],
              'validation_id' => $data['validation_id'],
              'days_pending' => $days_pending,
              'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['tickets_id']
           ];
           
           self::sendSystemMessage(
              $data['users_id_validate'],
              self::MSG_REMINDER_DUE,
              $content,
              'TicketValidation',
              $data['validation_id'],
              $system_data
           );
           
           $count++;
        }
     }
     
     if ($count > 0) {
        $task->addVolume($count);
        return 1;
     }
     
     return 0;
  }
  
  /**
   * Cron para verificar escalações automáticas
   */
  static function checkEscalations($task) {
     global $DB, $CFG_GLPI;
     
     // Busca tickets que devem ser escalados (exemplo: prioridade alta sem atribuição há mais de 2 horas)
     $query = "SELECT t.id as ticket_id, t.name as ticket_title, t.priority,
               t.date_creation, tu.users_id as requester_id
               FROM glpi_tickets t
               LEFT JOIN glpi_tickets_users tu ON (t.id = tu.tickets_id AND tu.type = 1)
               WHERE t.priority >= 4
               AND t.status = " . Ticket::INCOMING . "
               AND t.date_creation < DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND t.is_deleted = 0
               AND NOT EXISTS (
                  SELECT 1 FROM glpi_tickets_users tu2 
                  WHERE tu2.tickets_id = t.id AND tu2.type = 2
               )";
     
     $result = $DB->query($query);
     $count = 0;
     
     if ($result && $DB->numrows($result) > 0) {
        $admin_users = self::getAdminUsers();
        
        while ($data = $DB->fetchAssoc($result)) {
           $hours_unassigned = floor((time() - strtotime($data['date_creation'])) / 3600);
           
           $content = "🔔 <strong>Escalação Automática - Ticket #{$data['ticket_id']}</strong>\n\n";
           $content .= "<strong>Título:</strong> " . htmlspecialchars($data['ticket_title']) . "\n";
           $content .= "<strong>Prioridade:</strong> " . Ticket::getPriorityName($data['priority']) . "\n";
           $content .= "<strong>Criado há:</strong> {$hours_unassigned} hora(s)\n\n";
           $content .= "⚡ <strong>Ticket de alta prioridade sem atribuição!</strong>";
           
           $system_data = [
              'ticket_id' => $data['ticket_id'],
              'ticket_title' => $data['ticket_title'],
              'priority' => $data['priority'],
              'hours_unassigned' => $hours_unassigned,
              'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['ticket_id']
           ];
           
           // Notifica administradores
           foreach ($admin_users as $admin_id) {
              self::sendSystemMessage(
                 $admin_id,
                 self::MSG_ESCALATION,
                 $content,
                 'Ticket',
                 $data['ticket_id'],
                 $system_data
              );
           }
           
           $count++;
        }
     }
     
     if ($count > 0) {
        $task->addVolume($count);
        return 1;
     }
     
     return 0;
  }
   
   /**
    * Nova função para buscar usuários envolvidos no ticket de forma mais robusta
    */
   static function getTicketInvolvedUsersNew($ticket_id, $exclude_users = []) {
      global $DB;
      
      $users = [];
      $exclude_users_str = empty($exclude_users) ? '0' : implode(',', array_map('intval', $exclude_users));
      
      error_log("[NotificacaoChat] Buscando usuários envolvidos no ticket $ticket_id (excluindo: $exclude_users_str)");
      
      try {
         // 1. Busca usuários DIRETAMENTE associados ao ticket na tabela glpi_tickets_users
         $query = "SELECT DISTINCT tu.users_id, tu.type
                   FROM glpi_tickets_users tu
                   INNER JOIN glpi_users u ON u.id = tu.users_id
                   WHERE tu.tickets_id = $ticket_id
                   AND tu.users_id > 0
                   AND tu.users_id NOT IN ($exclude_users_str)
                   AND u.is_active = 1
                   AND u.is_deleted = 0";
         
         error_log("[NotificacaoChat] Query usuários diretos: $query");
         
         $result = $DB->query($query);
         if ($result && $DB->numrows($result) > 0) {
            while ($row = $DB->fetchAssoc($result)) {
               $user_type = '';
               switch ($row['type']) {
                  case 1: // Requester
                     $user_type = 'requester';
                     break;
                  case 2: // Assigned
                     $user_type = 'assigned';
                     break;
                  case 3: // Observer
                     $user_type = 'observer';
                     break;
                  default:
                     $user_type = 'other';
               }
               
               $users[] = [
                  'user_id' => intval($row['users_id']),
                  'type' => $user_type,
                  'source' => 'direct'
               ];
               
               error_log("[NotificacaoChat] ✅ Usuário direto: {$row['users_id']} (tipo: $user_type)");
            }
         } else {
            error_log("[NotificacaoChat] Nenhum usuário direto encontrado");
         }
         
         // 2. Busca usuários de GRUPOS associados ao ticket na tabela glpi_groups_tickets
         $query = "SELECT DISTINCT gu.users_id, gt.type
                   FROM glpi_groups_tickets gt
                   INNER JOIN glpi_groups_users gu ON gu.groups_id = gt.groups_id
                   INNER JOIN glpi_users u ON u.id = gu.users_id
                   WHERE gt.tickets_id = $ticket_id
                   AND gu.users_id > 0
                   AND gu.users_id NOT IN ($exclude_users_str)
                   AND u.is_active = 1
                   AND u.is_deleted = 0";
         
         error_log("[NotificacaoChat] Query usuários de grupos: $query");
         
         $result = $DB->query($query);
         if ($result && $DB->numrows($result) > 0) {
            while ($row = $DB->fetchAssoc($result)) {
               // Verifica se o usuário já foi adicionado
               $user_exists = false;
               foreach ($users as $existing_user) {
                  if ($existing_user['user_id'] == $row['users_id']) {
                     $user_exists = true;
                     break;
                  }
               }
               
               if (!$user_exists) {
                  $user_type = '';
                  switch ($row['type']) {
                     case 1: // Requester group
                        $user_type = 'requester';
                        break;
                     case 2: // Assigned group
                        $user_type = 'assigned';
                        break;
                     case 3: // Observer group
                        $user_type = 'observer';
                        break;
                     default:
                        $user_type = 'other';
                  }
                  
                  $users[] = [
                     'user_id' => intval($row['users_id']),
                     'type' => $user_type,
                     'source' => 'group'
                  ];
                  
                  error_log("[NotificacaoChat] ✅ Usuário do grupo: {$row['users_id']} (tipo: $user_type)");
               } else {
                  error_log("[NotificacaoChat] Usuário {$row['users_id']} já adicionado - pulando");
               }
            }
         } else {
            error_log("[NotificacaoChat] Nenhum usuário de grupo encontrado");
         }
         
         // 3. Busca o usuário recipient do ticket (se não estiver já incluído)
         $query = "SELECT users_id_recipient FROM glpi_tickets WHERE id = $ticket_id AND users_id_recipient > 0";
         $result = $DB->query($query);
         
         if ($result && $DB->numrows($result) > 0) {
            $row = $DB->fetchAssoc($result);
            $recipient_id = intval($row['users_id_recipient']);
            
            if ($recipient_id > 0 && !in_array($recipient_id, $exclude_users)) {
               // Verifica se já foi adicionado
               $user_exists = false;
               foreach ($users as $existing_user) {
                  if ($existing_user['user_id'] == $recipient_id) {
                     $user_exists = true;
                     break;
                  }
               }
               
               if (!$user_exists) {
                  $users[] = [
                     'user_id' => $recipient_id,
                     'type' => 'requester',
                     'source' => 'recipient'
                  ];
                  
                  error_log("[NotificacaoChat] ✅ Usuário recipient: $recipient_id");
               }
            }
         }
         
      } catch (Exception $e) {
         error_log("[NotificacaoChat] ERRO na busca de usuários: " . $e->getMessage());
      }
      
      error_log("[NotificacaoChat] Total de usuários únicos encontrados: " . count($users));
      
      // Log final de todos os usuários
      foreach ($users as $user) {
         error_log("[NotificacaoChat] Usuário final: {$user['user_id']} - {$user['type']} - {$user['source']}");
      }
      
      return $users;
   }
   
   /**
    * Verifica se o usuário tem permissão para validar tickets
    */
   static function canUserValidateTickets($users_id) {
      global $DB;
      
      // Verifica se o usuário tem direito de validação
      $query = "SELECT COUNT(*) as can_validate
                FROM glpi_profilerights pr
                JOIN glpi_profiles_users pu ON pr.profiles_id = pu.profiles_id
                WHERE pu.users_id = $users_id
                AND pr.name = 'ticketvalidation'
                AND pr.rights & " . TicketValidation::VALIDATEINCIDENT . " > 0";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         $data = $DB->fetchAssoc($result);
         return $data['can_validate'] > 0;
      }
      
      return false;
   }
   
   /**
    * Solicita validação de ticket via chat
    */
   static function requestTicketValidation($ticket_id, $validator_id, $requester_id, $comment) {
      global $DB, $CFG_GLPI;
      
      // Verifica se o ticket existe
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) {
         return ['success' => false, 'error' => 'Ticket não encontrado'];
      }
      
      // Verifica se o validador tem permissão
      if (!self::canUserValidateTickets($validator_id)) {
         return ['success' => false, 'error' => 'Usuário não tem permissão para validar tickets'];
      }
      
      // Cria o registro de validação
      $validation = new TicketValidation();
      $validation_data = [
         'tickets_id' => $ticket_id,
         'users_id_validate' => $validator_id,
         'users_id' => $requester_id,
         'comment_submission' => $comment,
         'status' => TicketValidation::WAITING,
         'submission_date' => date('Y-m-d H:i:s'),
         'entities_id' => $ticket->fields['entities_id']
      ];
      
      if ($validation->add($validation_data)) {
         // Obtém informações do solicitante
         $requester = new User();
         $requester_name = "Usuário #$requester_id";
         if ($requester->getFromDB($requester_id)) {
            $requester_name = $requester->getFriendlyName();
         }
         
         // Monta o conteúdo da mensagem
         $content = "🔍 <strong>Solicitação de Validação de Ticket</strong>\n\n";
         $content .= "<strong>Ticket:</strong> #{$ticket_id} - " . htmlspecialchars($ticket->fields['name']) . "\n";
         $content .= "<strong>Solicitante:</strong> {$requester_name}\n";
         $content .= "<strong>Status do Ticket:</strong> " . Ticket::getStatus($ticket->fields['status']) . "\n";
         $content .= "<strong>Prioridade:</strong> " . Ticket::getPriorityName($ticket->fields['priority']) . "\n\n";
         $content .= "<strong>Comentário da Solicitação:</strong>\n" . htmlspecialchars($comment) . "\n\n";
         
         $description = strip_tags($ticket->fields['content']);
         if (strlen($description) > 200) {
            $description = substr($description, 0, 200) . "...";
         }
         $content .= "<strong>Descrição do Ticket:</strong>\n" . $description;
         
         $system_data = [
            'ticket_id' => $ticket_id,
            'ticket_title' => $ticket->fields['name'],
            'validation_id' => $validation->getID(),
            'requester_id' => $requester_id,
            'requester_name' => $requester_name,
            'comment' => $comment,
            'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_id,
            'validation_type' => 'chat_request'
         ];
         
         // Envia mensagem do sistema para o validador
         $message_id = self::sendSystemMessage(
            $validator_id,
            self::MSG_VALIDATION_CHAT_REQUEST,
            $content,
            'TicketValidation',
            $validation->getID(),
            $system_data
         );
         
         return ['success' => true, 'validation_id' => $validation->getID(), 'message_id' => $message_id];
      }
      
      return ['success' => false, 'error' => 'Erro ao criar solicitação de validação'];
   }
   
   /**
    * Processa resposta de validação via chat
    */
   static function processValidationResponse($validation_id, $validator_id, $status, $comment = '') {
      global $DB, $CFG_GLPI;
      
      // Carrega a validação
      $validation = new TicketValidation();
      if (!$validation->getFromDB($validation_id)) {
         return ['success' => false, 'error' => 'Validação não encontrada'];
      }
      
      // Verifica se o usuário pode validar esta solicitação
      if ($validation->fields['users_id_validate'] != $validator_id) {
         return ['success' => false, 'error' => 'Usuário não autorizado para esta validação'];
      }
      
      // Verifica se a validação ainda está pendente
      if ($validation->fields['status'] != TicketValidation::WAITING) {
         return ['success' => false, 'error' => 'Esta validação já foi processada'];
      }
      
      // Atualiza a validação
      $update_data = [
         'id' => $validation_id,
         'status' => $status,
         'comment_validation' => $comment,
         'validation_date' => date('Y-m-d H:i:s')
      ];
      
      if ($validation->update($update_data)) {
         // Obtém informações do ticket e validador
         $ticket = new Ticket();
         $ticket->getFromDB($validation->fields['tickets_id']);
         
         $validator = new User();
         $validator_name = "Validador #$validator_id";
         if ($validator->getFromDB($validator_id)) {
            $validator_name = $validator->getFriendlyName();
         }
         
         // Determina o status da resposta
         $status_text = ($status == TicketValidation::ACCEPTED) ? "✅ APROVADA" : "❌ REJEITADA";
         $icon = ($status == TicketValidation::ACCEPTED) ? "✅" : "❌";
         
         // Monta mensagem de resposta para o solicitante
         $content = "{$icon} <strong>Validação {$status_text}</strong>\n\n";
         $content .= "<strong>Ticket:</strong> #{$ticket->getID()} - " . htmlspecialchars($ticket->fields['name']) . "\n";
         $content .= "<strong>Validado por:</strong> {$validator_name}\n\n";
         
         if ($status == TicketValidation::ACCEPTED) {
            $content .= "✅ <strong>Sua solicitação de validação foi APROVADA!</strong>\n";
         } else {
            $content .= "❌ <strong>Sua solicitação de validação foi REJEITADA.</strong>\n";
         }
         
         if (!empty($comment)) {
            $content .= "\n<strong>Comentário do Validador:</strong>\n" . htmlspecialchars($comment);
         }
         
         $system_data = [
            'ticket_id' => $ticket->getID(),
            'ticket_title' => $ticket->fields['name'],
            'validation_id' => $validation_id,
            'validator_id' => $validator_id,
            'validator_name' => $validator_name,
            'validation_status' => $status,
            'comment' => $comment,
            'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket->getID(),
            'validation_type' => 'chat_response'
         ];
         
         // Envia resposta para o solicitante
         $message_id = self::sendSystemMessage(
            $validation->fields['users_id'],
            self::MSG_VALIDATION_CHAT_RESPONSE,
            $content,
            'TicketValidation',
            $validation_id,
            $system_data
         );
         
         return ['success' => true, 'message_id' => $message_id];
      }
      
      return ['success' => false, 'error' => 'Erro ao processar validação'];
   }

   /**
    * Envia uma mensagem do sistema para um usuário
    */
   static function sendSystemMessage($to_id, $message_type, $content, $item_type = null, $item_id = null, $system_data = []) {
      global $DB;
      
      // Log detalhado
      error_log("[NotificacaoChat] ENVIANDO MENSAGEM - Usuário: $to_id, Tipo: $message_type, Item: $item_type #$item_id");
      
      // Verifica se o usuário quer receber este tipo de notificação
      if (!self::userWantsNotification($to_id, $message_type)) {
         error_log("[NotificacaoChat] Usuário $to_id não quer receber notificações do tipo $message_type");
         return false;
      }
      
      // Verifica/cria tabelas se necessário
      self::ensureTablesExist();
      
      // Sanitiza o conteúdo
      $content_escaped = $DB->escape($content);
      $system_data_json = $DB->escape(json_encode($system_data));
      
      // Insere a mensagem
      $query = "INSERT INTO `glpi_plugin_notificacaochat_messages` 
                (`from_id`, `to_id`, `content`, `date_creation`, `is_read`, 
                 `is_system_message`, `system_message_type`, `related_item_type`, 
                 `related_item_id`, `system_data`) 
                VALUES (0, $to_id, '$content_escaped', NOW(), 0, 1, 
                        '$message_type', '$item_type', $item_id, '$system_data_json')";
      
      if ($DB->query($query)) {
         $messageId = $DB->insertId();
         error_log("[NotificacaoChat] Mensagem do sistema enviada com sucesso. ID: $messageId");
         return $messageId;
      } else {
         error_log("[NotificacaoChat] ERRO ao inserir mensagem: " . $DB->error());
         error_log("[NotificacaoChat] Query: " . $query);
      }
      
      return false;
   }

   /**
    * Marca uma mensagem do sistema como lida
    */
   static function markSystemMessageAsRead($message_id, $user_id) {
      global $DB;
      
      // Verifica se a mensagem pertence ao usuário
      $query = "SELECT id FROM glpi_plugin_notificacaochat_messages 
                WHERE id = " . intval($message_id) . "
                AND to_id = " . intval($user_id) . "
                AND is_system_message = 1";
      
      $result = $DB->query($query);
      
      if ($result && $DB->numrows($result) > 0) {
         // Marca como lida
         $update_query = "UPDATE glpi_plugin_notificacaochat_messages 
                         SET is_read = 1 
                         WHERE id = " . intval($message_id);
         
         if ($DB->query($update_query)) {
            error_log("[NotificacaoChat] Mensagem {$message_id} marcada como lida pelo usuário {$user_id}");
            return ['success' => true, 'message' => 'Mensagem marcada como lida'];
         } else {
            error_log("[NotificacaoChat] ERRO ao marcar mensagem {$message_id} como lida: " . $DB->error());
            return ['success' => false, 'error' => 'Erro ao marcar como lida'];
         }
      } else {
         return ['success' => false, 'error' => 'Mensagem não encontrada ou não autorizada'];
      }
   }
   
   /**
    * Marca todas as mensagens do sistema como lidas para um usuário
    */
   static function markAllSystemMessagesAsRead($user_id) {
      global $DB;
      
      $query = "UPDATE glpi_plugin_notificacaochat_messages 
                SET is_read = 1 
                WHERE to_id = " . intval($user_id) . "
                AND is_system_message = 1
                AND is_read = 0";
      
      if ($DB->query($query)) {
         $affected_rows = $DB->affectedRows();
         error_log("[NotificacaoChat] {$affected_rows} mensagens do sistema marcadas como lidas para usuário {$user_id}");
         return ['success' => true, 'marked_count' => $affected_rows, 'message' => "{$affected_rows} mensagens marcadas como lidas"];
      } else {
         error_log("[NotificacaoChat] ERRO ao marcar todas as mensagens como lidas: " . $DB->error());
         return ['success' => false, 'error' => 'Erro ao marcar mensagens como lidas'];
      }
   }
   
   /**
    * Garante que as tabelas existem
    */
   static function ensureTablesExist() {
      global $DB;
      
      // Tabela de mensagens
      if (!$DB->tableExists('glpi_plugin_notificacaochat_messages')) {
         $query = "CREATE TABLE `glpi_plugin_notificacaochat_messages` (
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
                  KEY `is_system_message` (`is_system_message`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
         
         $DB->query($query);
         error_log("[NotificacaoChat] Tabela de mensagens criada");
      }
      
      // Tabela de configurações
      if (!$DB->tableExists('glpi_plugin_notificacaochat_system_config')) {
         $query = "CREATE TABLE `glpi_plugin_notificacaochat_system_config` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `users_id` int(11) NOT NULL,
                  `notify_new_tickets` tinyint(1) NOT NULL DEFAULT '1',
                  `notify_assigned_tickets` tinyint(1) NOT NULL DEFAULT '1',
                  `notify_pending_approval` tinyint(1) NOT NULL DEFAULT '1',
                  `notify_unassigned_tickets` tinyint(1) NOT NULL DEFAULT '1',
                  `notify_ticket_updates` tinyint(1) NOT NULL DEFAULT '1',
                  `notify_ticket_solutions` tinyint(1) NOT NULL DEFAULT '1',
                  `date_creation` datetime NOT NULL,
                  `date_mod` datetime NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
         
         $DB->query($query);
         error_log("[NotificacaoChat] Tabela de configurações criada");
      }
   }
   
   /**
    * Verifica se o usuário quer receber determinado tipo de notificação
    */
   static function userWantsNotification($users_id, $message_type) {
      global $DB;
      
      $query = "SELECT * FROM glpi_plugin_notificacaochat_system_config 
                WHERE users_id = $users_id";
      $result = $DB->query($query);
      
      if ($result && $DB->numrows($result) > 0) {
         $config = $DB->fetchAssoc($result);
         
         switch ($message_type) {
            case self::MSG_NEW_TICKET:
               return $config['notify_new_tickets'] == 1;
            case self::MSG_ASSIGNED_TICKET:
               return $config['notify_assigned_tickets'] == 1;
            case self::MSG_PENDING_APPROVAL:
            case self::MSG_VALIDATION_REQUEST:
            case self::MSG_VALIDATION_RESPONSE:
            case self::MSG_VALIDATION_CHAT_REQUEST:
            case self::MSG_VALIDATION_CHAT_RESPONSE:
            case self::MSG_APPROVAL_REQUEST:
            case self::MSG_APPROVAL_GRANTED:
            case self::MSG_APPROVAL_DENIED:
            case self::MSG_REMINDER_DUE:
               return $config['notify_pending_approval'] == 1;
            case self::MSG_UNASSIGNED_TICKET:
            case self::MSG_ESCALATION:
               return $config['notify_unassigned_tickets'] == 1;
            case self::MSG_TICKET_UPDATE:
            case self::MSG_FOLLOWUP_ADDED:
            case self::MSG_TASK_ADDED:
            case self::MSG_TASK_UPDATED:
            case self::MSG_TASK_COMPLETED:
            case self::MSG_TASK_OVERDUE:
            case self::MSG_ITILFOLLOWUP_ADDED:
            case self::MSG_TICKET_REOPENED:
               return $config['notify_ticket_updates'] == 1;
            case self::MSG_TICKET_SOLUTION:
            case self::MSG_SOLUTION_PROPOSED:
            case self::MSG_SOLUTION_APPROVED:
            case self::MSG_SOLUTION_REJECTED:
            case self::MSG_TICKET_CLOSED:
            case self::MSG_SLA_ALERT:
            case self::MSG_SLA_BREACH:
               return $config['notify_ticket_solutions'] == 1;
            default:
               return true;
         }
      }
      
      // Se não tem configuração, cria uma padrão e retorna true
      self::createDefaultUserConfig($users_id);
      return true;
   }
   
   /**
    * Cria configuração padrão para um usuário
    */
   static function createDefaultUserConfig($users_id) {
      global $DB;
      
      $query = "INSERT IGNORE INTO `glpi_plugin_notificacaochat_system_config`
                (`users_id`, `notify_new_tickets`, `notify_assigned_tickets`, 
                 `notify_pending_approval`, `notify_unassigned_tickets`, 
                 `notify_ticket_updates`, `notify_ticket_solutions`, 
                 `date_creation`, `date_mod`)
                VALUES ($users_id, 1, 1, 1, 1, 1, 1, NOW(), NOW())";
      
      return $DB->query($query);
   }
   
   /**
    * Cron para verificar tickets não atribuídos há muito tempo
    */
   static function checkUnassignedTickets($task) {
      global $DB, $CFG_GLPI;
      
      // Busca tickets novos sem atribuição há mais de 30 minutos
      $query = "SELECT t.id, t.name, t.users_id_recipient, t.date_creation
                FROM glpi_tickets t
                WHERE t.status = " . Ticket::INCOMING . "
                AND t.is_deleted = 0
                AND t.date_creation < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                AND NOT EXISTS (
                    SELECT 1 FROM glpi_tickets_users tu
                    WHERE tu.tickets_id = t.id AND tu.type = 2
                )
                AND NOT EXISTS (
                    SELECT 1 FROM glpi_groups_tickets gt
                    WHERE gt.tickets_id = t.id AND gt.type = 2
                )";
      
      $result = $DB->query($query);
      $count = 0;
      
      if ($result && $DB->numrows($result) > 0) {
         $admin_users = self::getAdminUsers();
         
         while ($ticket_row = $DB->fetchAssoc($result)) {
            $age = time() - strtotime($ticket_row['date_creation']);
            $age_hours = round($age / 3600, 1);
            
            $content = "⚠️ <strong>Ticket Não Atribuído #{$ticket_row['id']}</strong>\n\n";
            $content .= "<strong>Título:</strong> " . htmlspecialchars($ticket_row['name']) . "\n";
            $content .= "<strong>Tempo sem atribuição:</strong> {$age_hours} horas\n\n";
            $content .= "🚨 <strong>Este ticket precisa de atenção urgente!</strong>";
            
            $system_data = [
               'ticket_id' => $ticket_row['id'],
               'ticket_title' => $ticket_row['name'],
               'age_hours' => $age_hours,
               'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $ticket_row['id']
            ];
            
            // Notifica administradores
            foreach ($admin_users as $admin_id) {
               self::sendSystemMessage(
                  $admin_id,
                  self::MSG_UNASSIGNED_TICKET,
                  $content,
                  'Ticket',
                  $ticket_row['id'],
                  $system_data
               );
            }
            
            $count++;
         }
      }
      
      if ($count > 0) {
         $task->addVolume($count);
         return 1;
      }
      
      return 0;
   }
   
   /**
    * Obtém todos os usuários envolvidos em um ticket (versão simplificada para compatibilidade)
    */
   static function getTicketInvolvedUsers($ticket_id, $exclude_users = []) {
      // Usa a nova função mais robusta mas retorna apenas os IDs para compatibilidade
      $users_detailed = self::getTicketInvolvedUsersNew($ticket_id, $exclude_users);
      
      $user_ids = [];
      foreach ($users_detailed as $user_data) {
         $user_ids[] = $user_data['user_id'];
      }
      
      return $user_ids;
   }
   
   /**
    * Obtém usuários de um grupo
    */
   static function getGroupUsers($group_id) {
      global $DB;
      
      $users = [];
      
      $query = "SELECT gu.users_id 
                FROM glpi_groups_users gu
                JOIN glpi_users u ON u.id = gu.users_id
                WHERE gu.groups_id = $group_id 
                AND u.is_active = 1";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         while ($row = $DB->fetchAssoc($result)) {
            $users[] = $row['users_id'];
         }
      }
      
      return $users;
   }
   
   /**
    * Obtém usuários administradores
    */
   static function getAdminUsers() {
      global $DB;
      
      $users = [];
      
      $query = "SELECT DISTINCT u.id
                FROM glpi_users u
                JOIN glpi_profiles_users pu ON u.id = pu.users_id
                JOIN glpi_profiles p ON pu.profiles_id = p.id
                WHERE u.is_active = 1
                AND (p.name LIKE '%Admin%' OR p.name LIKE '%Super-Admin%' OR p.name LIKE '%Supervisor%')
                LIMIT 5";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         while ($row = $DB->fetchAssoc($result)) {
            $users[] = $row['id'];
         }
      }
      
      return $users;
   }
   
   /**
    * Obtém configurações de notificação do usuário
    */
   static function getUserNotificationConfig($users_id) {
      global $DB;
      
      self::ensureTablesExist();
      
      $query = "SELECT * FROM glpi_plugin_notificacaochat_system_config 
                WHERE users_id = $users_id";
      $result = $DB->query($query);
      
      if ($result && $DB->numrows($result) > 0) {
         return $DB->fetchAssoc($result);
      }
      
      // Retorna configuração padrão se não existir
      self::createDefaultUserConfig($users_id);
      return [
         'notify_new_tickets' => 1,
         'notify_assigned_tickets' => 1,
         'notify_pending_approval' => 1,
         'notify_unassigned_tickets' => 1,
         'notify_ticket_updates' => 1,
         'notify_ticket_solutions' => 1
      ];
   }
   
   /**
    * Atualiza configurações de notificação do usuário
    */
   static function updateUserNotificationConfig($users_id, $config) {
      global $DB;
      
      self::ensureTablesExist();
      
      $data = [
         'users_id' => $users_id,
         'notify_new_tickets' => isset($config['notify_new_tickets']) ? 1 : 0,
         'notify_assigned_tickets' => isset($config['notify_assigned_tickets']) ? 1 : 0,
         'notify_pending_approval' => isset($config['notify_pending_approval']) ? 1 : 0,
         'notify_unassigned_tickets' => isset($config['notify_unassigned_tickets']) ? 1 : 0,
         'notify_ticket_updates' => isset($config['notify_ticket_updates']) ? 1 : 0,
         'notify_ticket_solutions' => isset($config['notify_ticket_solutions']) ? 1 : 0,
         'date_mod' => date('Y-m-d H:i:s')
      ];
      
      // Verifica se já existe configuração
      $query = "SELECT id FROM glpi_plugin_notificacaochat_system_config 
                WHERE users_id = $users_id";
      $result = $DB->query($query);
      
      if ($result && $DB->numrows($result) > 0) {
         $existing = $DB->fetchAssoc($result);
         return $DB->update('glpi_plugin_notificacaochat_system_config', $data, ['id' => $existing['id']]);
      } else {
         $data['date_creation'] = date('Y-m-d H:i:s');
         return $DB->insert('glpi_plugin_notificacaochat_system_config', $data);
      }
   }
   
   // Métodos vazios para compatibilidade
   static function onTicketDelete(CommonDBTM $ticket) {
      // Implementar se necessário
   }
   
   static function onTicketUserDelete(CommonDBTM $ticket_user) {
      // Implementar se necessário  
   }
   
   static function onGroupTicketDelete(CommonDBTM $group_ticket) {
      // Implementar se necessário
   }
}
?>