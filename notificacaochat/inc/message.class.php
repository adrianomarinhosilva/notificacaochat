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
 * Classe para gerenciar mensagens de chat
 */
class PluginNotificacaochatMessage extends CommonDBTM {
   
   /**
    * Envia uma mensagem
    *
    * @param int $from_id ID do remetente
    * @param int $to_id ID do destinatário
    * @param string $content Conteúdo da mensagem
    * @return int|boolean ID da mensagem ou false em caso de falha
    */
   static function sendMessage($from_id, $to_id, $content) {
      global $DB;
      
      if (empty(trim($content))) {
          return false;
      }
      
      // Sanitiza o conteúdo
      $content = $DB->escape(Toolbox::addslashes_deep($content));
      
      // Cria o registro da mensagem
      $message = [
         'from_id' => $from_id,
         'to_id' => $to_id,
         'content' => $content,
         'date_creation' => date('Y-m-d H:i:s'),
         'is_read' => 0
      ];
      
      if ($DB->insert('glpi_plugin_notificacaochat_messages', $message)) {
         // Cria uma notificação no sistema GLPI
         self::createGLPINotification($from_id, $to_id, $content);
         return $DB->insertId();
      }
      
      return false;
   }
   
   /**
    * Obtém o histórico de mensagens entre dois usuários
    *
    * @param int $user1_id ID do primeiro usuário
    * @param int $user2_id ID do segundo usuário
    * @param int $limit Número máximo de mensagens
    * @return array
    */
   static function getChatHistory($user1_id, $user2_id, $limit = 50) {
      global $DB;
      
      $messages = [];
      
      $query = "SELECT id, from_id, to_id, content, date_creation, is_read
                FROM glpi_plugin_notificacaochat_messages
                WHERE (from_id = $user1_id AND to_id = $user2_id)
                   OR (from_id = $user2_id AND to_id = $user1_id)
                ORDER BY date_creation ASC
                LIMIT $limit";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         while ($data = $DB->fetchAssoc($result)) {
            $messages[] = [
               'id' => $data['id'],
               'content' => $data['content'],
               'is_sender' => ($data['from_id'] == $user1_id),
               'date_creation' => $data['date_creation'],
               'is_read' => $data['is_read']
            ];
            
            // Marca como lida se for uma mensagem recebida
            if ($data['to_id'] == $user1_id && $data['is_read'] == 0) {
               $update_query = "UPDATE glpi_plugin_notificacaochat_messages
                                SET is_read = 1
                                WHERE id = " . $data['id'];
               $DB->query($update_query);
            }
         }
      }
      
      return $messages;
   }
   
   /**
    * Obtém novas mensagens para um usuário
    *
    * @param int $user_id ID do usuário
    * @param string $last_check Data da última verificação
    * @return array
    */
   static function getNewMessages($user_id, $last_check) {
      global $DB;
      
      $messages = [];
      
      $query = "SELECT m.id, m.from_id, m.content, m.date_creation, 
                CONCAT(u.firstname, ' ', u.realname) as from_name
                FROM glpi_plugin_notificacaochat_messages m
                JOIN glpi_users u ON (u.id = m.from_id)
                WHERE m.to_id = $user_id
                AND m.is_read = 0
                AND m.date_creation > '$last_check'
                ORDER BY m.date_creation ASC";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         while ($data = $DB->fetchAssoc($result)) {
            // Formata o nome quando vazio
            $from_name = trim($data['from_name']);
            if (empty($from_name)) {
                $from_name = 'Usuário #' . $data['from_id'];
            }
            
            $messages[] = [
               'id' => $data['id'],
               'from_id' => $data['from_id'],
               'from_name' => $from_name,
               'content' => $data['content'],
               'date_creation' => $data['date_creation']
            ];
         }
      }
      
      return $messages;
   }
   
   /**
    * Marca todas as mensagens como lidas
    *
    * @param int $from_id ID do remetente
    * @param int $to_id ID do destinatário
    * @return boolean
    */
   static function markAllAsRead($from_id, $to_id) {
      global $DB;
      
      $query = "UPDATE glpi_plugin_notificacaochat_messages
                SET is_read = 1
                WHERE from_id = $from_id
                AND to_id = $to_id
                AND is_read = 0";
                
      return $DB->query($query);
   }
   
   /**
    * Marca como lida uma mensagem específica
    *
    * @param int $message_id ID da mensagem
    * @return boolean
    */
   static function markAsRead($message_id) {
      global $DB;
      
      $query = "UPDATE glpi_plugin_notificacaochat_messages
                SET is_read = 1
                WHERE id = $message_id";
                
      return $DB->query($query);
   }
   
   /**
    * Cria uma notificação nativa do GLPI
    * 
    * @param int $from_id ID do remetente
    * @param int $to_id ID do destinatário
    * @param string $message Conteúdo da mensagem
    * @return int|bool ID da notificação ou false em caso de falha
    */
   static function createGLPINotification($from_id, $to_id, $message) {
      global $DB, $CFG_GLPI;
      
      // Obtém o nome do remetente
      $from_name = "Usuário #$from_id";
      $current_user = new User();
      if ($current_user->getFromDB($from_id)) {
         $from_name = $current_user->getFriendlyName();
      }
      
      // Título da notificação
      $title = "Nova mensagem de $from_name";
      
      // Verifica qual é a versão do GLPI
      $glpi_version = GLPI_VERSION;
      
      // Para GLPI 10.x usamos Notification_Alert
      if (version_compare($glpi_version, '10.0.0', '>=')) {
         // Verificar se a tabela existe
         if ($DB->tableExists('glpi_notifications_alerts')) {
            // Link para a própria interface do plugin
            $link = $CFG_GLPI['root_doc'] . '/plugins/notificacaochat/front/message.php?user_id='.$from_id;
            
            // Data de criação
            $date = date('Y-m-d H:i:s');
            
            // Cria entrada na tabela de alertas
            $query = "INSERT INTO `glpi_notifications_alerts` 
                     (`entities_id`, `users_id`, `date`, `name`, `message`, `url`, `date_creation`) 
                     VALUES (0, $to_id, '$date', '$title', '$message', '$link', '$date')";
            
            if ($DB->query($query)) {
               return $DB->insertId();
            }
         }
      } 
      // Para GLPI versão 9.x usando Alert ou método antigo
      else {
         // Tentamos usar o sistema de alerta nativo se disponível
         if ($DB->tableExists('glpi_alerts')) {
            $query = "INSERT INTO `glpi_alerts` 
                     (`date`, `name`, `message`, `users_id`) 
                     VALUES (NOW(), '$title', '$message', $to_id)";
            
            if ($DB->query($query)) {
               return $DB->insertId();
            }
         }
      }
      
      // Em caso de falha, tentamos outro método
      // Verificar se existe a tabela de eventos do GLPI
      if ($DB->tableExists('glpi_events')) {
         $query = "INSERT INTO `glpi_events` 
                  (`items_id`, `type`, `date`, `service`, `level`, `message`) 
                  VALUES ($to_id, 'system', NOW(), 'notification', 3, '$title: $message')";
         
         if ($DB->query($query)) {
            return $DB->insertId();
         }
      }
      
      return false;
   }
}