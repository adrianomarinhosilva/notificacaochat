<?php
/*
 -------------------------------------------------------------------------
 Notificação Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Acesso direto não permitido");
}

/**
 * Classe principal do plugin NotificacaoChat
 */
class PluginNotificacaochatNotificacao extends CommonGLPI {
   
   static $rightname = 'plugin_notificacaochat';
   
   /**
    * Retorna o nome do plugin
    *
    * @param $nb  inteiro número de itens (padrão 0)
    */
   static function getTypeName($nb = 0) {
      return 'Notificação Chat';
   }
   
   /**
    * Obtém os usuários online
    *
    * @param int $group_id Filtrar por grupo específico (0 = todos)
    * @return array de usuários online
    */
   static function getOnlineUsers($group_id = 0) {
      global $DB;
      
      // Remove usuários inativos por mais de 2 minutos (reduzido para tempo real)
      $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                WHERE `last_active` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
      $DB->query($query);
      
      // Atualiza o status do usuário atual
      $users_id = $_SESSION['glpiID'];
      $query = "REPLACE INTO `glpi_plugin_notificacaochat_online_users` 
                (`users_id`, `last_active`) 
                VALUES ($users_id, NOW())";
      $DB->query($query);
      
      // Prepara a consulta base
      $query = "SELECT ou.users_id, u.firstname, u.realname, u.name 
                FROM `glpi_plugin_notificacaochat_online_users` ou
                LEFT JOIN `glpi_users` u ON (u.id = ou.users_id)
                WHERE u.is_active = 1";
      
      // Adiciona filtro de grupo se especificado
      if ($group_id > 0) {
         $query .= " AND ou.users_id IN (
                       SELECT glpi_groups_users.users_id 
                       FROM glpi_groups_users 
                       WHERE glpi_groups_users.groups_id = $group_id
                    )";
      }
      
      $query .= " ORDER BY u.realname, u.firstname";
      $result = $DB->query($query);
      
      $users = [];
      if ($result) {
          while ($data = $DB->fetchAssoc($result)) {
             $display_name = $data['firstname'] . ' ' . $data['realname'];
             if (empty(trim($display_name))) {
                $display_name = $data['name'];
             }
             
             $users[] = [
                'id' => $data['users_id'],
                'name' => $display_name
             ];
          }
      }
      
      return $users;
   }
   
   /**
    * Obtém as informações de tickets para os usuários
    *
    * @param array $users_ids IDs dos usuários
    * @return array
    */
   static function getTicketsInfo($users_ids) {
      global $DB;
      
      $tickets_info = [];
      
      if (empty($users_ids)) {
         return $tickets_info;
      }
      
      $users_ids_str = implode(',', $users_ids);
      
      // Consulta para tickets atribuídos e seus estados
      $query = "SELECT tu.users_id, t.status, COUNT(t.id) as count
                FROM `glpi_tickets_users` tu
                JOIN `glpi_tickets` t ON (t.id = tu.tickets_id)
                WHERE tu.type = 2  -- Tipo 2 é atribuído
                AND tu.users_id IN ($users_ids_str)
                AND t.is_deleted = 0
                AND t.status IN (2, 3)  -- 2=Em processamento, 3=Planejado
                GROUP BY tu.users_id, t.status";
      
      $result = $DB->query($query);
      
      // Inicializar array para todos os usuários
      foreach ($users_ids as $user_id) {
         $tickets_info[$user_id] = [
            'processing' => 0,  // Em processamento
            'planned' => 0,     // Planejado/Pausado
            'total' => 0        // Total
         ];
      }
      
      // Preencher com os resultados da consulta
      if ($result) {
          while ($data = $DB->fetchAssoc($result)) {
             $user_id = $data['users_id'];
             $status = $data['status'];
             $count = $data['count'];
             
             if ($status == 2) {  // Em processamento
                $tickets_info[$user_id]['processing'] = $count;
             } else if ($status == 3) {  // Planejado
                $tickets_info[$user_id]['planned'] = $count;
             }
             
             $tickets_info[$user_id]['total'] += $count;
          }
      }
      
      return $tickets_info;
   }
   
   /**
    * Obtém os novos tickets para notificação
    * 
    * @param int $users_id ID do usuário
    * @return array
    */
   static function getNewTickets($users_id) {
      global $DB;
      
      // Verifica na tabela de notificações quais tickets foram vistos
      $seen_tickets = [];
      $query = "SELECT ticket_id FROM glpi_plugin_notificacaochat_notifications WHERE users_id = $users_id";
      $result = $DB->query($query);
      
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            $seen_tickets[] = $data['ticket_id'];
         }
      }
      
      // Prepara a consulta para tickets não vistos
      $query = "SELECT t.id, t.name, t.date_creation, u.id as requester_id, 
                CONCAT(u.firstname, ' ', u.realname) as requester
                FROM glpi_tickets t
                LEFT JOIN glpi_tickets_users tu ON (tu.tickets_id = t.id AND tu.type = 1) -- 1 = Requisitante
                LEFT JOIN glpi_users u ON (u.id = tu.users_id)
                WHERE t.status = 1 -- Novo
                AND t.is_deleted = 0
                AND (
                    -- Atribuído ao usuário
                    t.id IN (SELECT tickets_id FROM glpi_tickets_users WHERE users_id = $users_id AND type = 2)
                    OR 
                    -- Visível para o grupo do usuário
                    t.id IN (
                        SELECT tickets_id FROM glpi_groups_tickets 
                        WHERE groups_id IN (SELECT groups_id FROM glpi_groups_users WHERE users_id = $users_id)
                    )
                )";
      
      // Adiciona filtro para tickets já vistos
      if (!empty($seen_tickets)) {
         $seen_tickets_str = implode(',', $seen_tickets);
         $query .= " AND t.id NOT IN ($seen_tickets_str)";
      }
      
      $query .= " ORDER BY t.date_creation DESC LIMIT 10";
      
      $result = $DB->query($query);
      $tickets = [];
      
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            $tickets[] = [
               'id' => $data['id'],
               'title' => $data['name'],
               'requester' => $data['requester'],
               'requester_id' => $data['requester_id'],
               'date_creation' => $data['date_creation'],
               'url' => $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $data['id']
            ];
            
            // Marca o ticket como visto
            $notification = [
               'users_id' => $users_id,
               'ticket_id' => $data['id'],
               'is_read' => 0,
               'date_creation' => date('Y-m-d H:i:s')
            ];
            
            $DB->insert('glpi_plugin_notificacaochat_notifications', $notification);
         }
      }
      
      return $tickets;
   }
   
   /**
 * Tarefa cron para limpar usuários online antigos
 * 
 * @param $task CronTask object
 * @return integer
 */
static function cleanupOnlineUsers($task) {
    global $DB;
    
    // Remove usuários inativos por mais de 2 minutos (reduzido para tempo real)
    $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
              WHERE `last_active` < DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
    $result = $DB->query($query);
    
    if ($result) {
        $task->addVolume($DB->affectedRows());
        return 1;
    }
    
    return 0;
}
}