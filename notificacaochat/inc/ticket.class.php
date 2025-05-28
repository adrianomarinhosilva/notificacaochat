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
 * Classe para gerenciar tickets no plugin
 */
class PluginNotificacaochatTicket extends CommonDBTM {
   
   /**
    * Obtém os tickets de um usuário
    *
    * @param int $users_id ID do usuário
    * @param string $status Status dos tickets ('all', '1', '2', etc.)
    * @return array
    */
   static function getUserTickets($users_id, $status = 'all') {
      global $DB;
      
      $tickets = [];
      
      // Constrói a consulta base
      $query = "SELECT t.id, t.name, t.date_mod, t.status, t.priority,
                CONCAT(u.firstname, ' ', u.realname) as requester
                FROM glpi_tickets t
                JOIN glpi_tickets_users tu ON (tu.tickets_id = t.id AND tu.type = 2) -- 2 = Atribuído
                LEFT JOIN glpi_tickets_users tu2 ON (tu2.tickets_id = t.id AND tu2.type = 1) -- 1 = Requisitante
                LEFT JOIN glpi_users u ON (u.id = tu2.users_id)
                WHERE tu.users_id = $users_id
                AND t.is_deleted = 0";
      
      // Adiciona filtro de status
      if ($status != 'all') {
         if (is_numeric($status)) {
            $query .= " AND t.status = " . intval($status);
         } else {
            // Status pode ser 'new', 'process', etc.
            switch ($status) {
               case 'pending':
                  $query .= " AND t.status = 1"; // Novo/Pendente
                  break;
               case 'processing':
                  $query .= " AND t.status = 2"; // Em processamento
                  break;
               case 'waiting':
                  $query .= " AND t.status = 4"; // Pendente
                  break;
               case 'solved':
                  $query .= " AND t.status = 5"; // Solucionado
                  break;
               case 'closed':
                  $query .= " AND t.status = 6"; // Fechado
                  break;
               default:
                  break;
            }
         }
      }
      
      $query .= " ORDER BY t.date_mod DESC LIMIT 100";
      
      $result = $DB->query($query);
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            // Obtém o nome do status
            $status_name = Ticket::getStatus($data['status']);
            
            $tickets[] = [
               'id' => $data['id'],
               'name' => $data['name'],
               'requester' => $data['requester'],
               'date_mod' => Html::convDateTime($data['date_mod']),
               'status' => $data['status'],
               'status_name' => $status_name,
               'priority' => $data['priority'],
               'priority_name' => Ticket::getPriorityName($data['priority'])
            ];
         }
      }
      
      return $tickets;
   }
   
   /**
    * Obtém o status dos tickets de um usuário
    *
    * @param int $users_id ID do usuário
    * @return array Contagem de tickets por status
    */
   static function getTicketStatusCount($users_id) {
      global $DB;
      
      $counts = [
         'pending' => 0,    // Status 1 (New/Novo)
         'processing' => 0, // Status 2 (Processing/Em processamento)
         'total' => 0
      ];
      
      // Consulta para obter contagem por status
      $query = "SELECT t.status, COUNT(*) as count
                FROM glpi_tickets t
                JOIN glpi_tickets_users tu ON (t.id = tu.tickets_id)
                WHERE tu.users_id = $users_id
                AND tu.type = 2 -- 2 = Atribuído
                AND t.is_deleted = 0
                AND t.status IN (1, 2) -- Status: 1 = Novo, 2 = Em processamento
                GROUP BY t.status";
      
      $result = $DB->query($query);
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            $status = intval($data['status']);
            $count = intval($data['count']);
            
            if ($status === 1) {
               $counts['pending'] = $count;
            } elseif ($status === 2) {
               $counts['processing'] = $count;
            }
            
            $counts['total'] += $count;
         }
      }
      
      return $counts;
   }
}