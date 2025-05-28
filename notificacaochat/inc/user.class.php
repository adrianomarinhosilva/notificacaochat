<?php
/*
 -------------------------------------------------------------------------
 Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Acesso direto não permitido");
}

/**
 * Classe para gerenciar usuários do plugin
 */
class PluginNotificacaochatUser extends CommonDBTM {
   
   /**
    * Atualiza o status online do usuário
    *
    * @param int $users_id ID do usuário
    * @return boolean
    */
   static function updateOnlineStatus($users_id) {
       global $DB;
       
       $query = "REPLACE INTO `glpi_plugin_notificacaochat_online_users` 
                 (`users_id`, `last_active`) 
                 VALUES ($users_id, NOW())";
       return $DB->query($query);
   }
   
   /**
    * Verifica se um usuário está online
    *
    * @param int $users_id ID do usuário
    * @return boolean
    */
   static function isOnline($users_id) {
      global $DB;
      
      $query = "SELECT COUNT(*) as count
                FROM `glpi_plugin_notificacaochat_online_users`
                WHERE `users_id` = $users_id
                AND `last_active` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)";
      
      $result = $DB->query($query);
      if ($result) {
         $data = $DB->fetchAssoc($result);
         return ($data['count'] > 0);
      }
      
      return false;
   }
   
   /**
    * Obtém os grupos do usuário
    *
    * @param int $users_id ID do usuário
    * @return array
    */
   static function getUserGroups($users_id) {
      global $DB;
      
      $groups = [];
      
      $query = "SELECT g.id, g.name
                FROM glpi_groups g
                JOIN glpi_groups_users gu ON (gu.groups_id = g.id)
                WHERE gu.users_id = $users_id
                ORDER BY g.name";
      
      $result = $DB->query($query);
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            $groups[] = [
               'id' => $data['id'],
               'name' => $data['name']
            ];
         }
      }
      
      return $groups;
   }
   
   /**
    * Obtém informações detalhadas de um usuário
    *
    * @param int $users_id ID do usuário
    * @return array Dados do usuário
    */
   static function getUserInfo($users_id) {
      global $DB;
      
      $query = "SELECT u.id, u.name as username, u.firstname, u.realname, 
                u.is_active, u.picture, u.email
                FROM glpi_users u 
                WHERE u.id = $users_id";
      
      $result = $DB->query($query);
      if ($result && $DB->numrows($result) > 0) {
         $data = $DB->fetchAssoc($result);
         
         // Formata o nome completo
         $display_name = trim($data['firstname'] . ' ' . $data['realname']);
         if (empty($display_name)) {
            $display_name = $data['username'];
         }
         
         // Obtém o grupo principal (primeiro grupo)
         $group_name = '';
         $group_query = "SELECT g.name FROM glpi_groups g
                         JOIN glpi_groups_users gu ON (g.id = gu.groups_id)
                         WHERE gu.users_id = $users_id
                         ORDER BY g.name
                         LIMIT 1";
         $group_result = $DB->query($group_query);
         if ($group_result && $DB->numrows($group_result) > 0) {
            $group_data = $DB->fetchAssoc($group_result);
            $group_name = $group_data['name'];
         }
         
         return [
            'id' => $data['id'],
            'username' => $data['username'],
            'name' => $display_name,
            'group_name' => $group_name,
            'email' => $data['email'],
            'is_active' => $data['is_active'],
            'picture' => $data['picture']
         ];
      }
      
      return null;
   }
   
   /**
    * Hook chamado quando um usuário faz login
    *
    * @param array $post_login_data Dados do login
    * @return void 
    */
   static function userLogin($post_login_data) {
      $users_id = isset($_SESSION['glpiID']) ? $_SESSION['glpiID'] : 0;
      
      if ($users_id > 0) {
         self::updateOnlineStatus($users_id);
      }
   }
   
   /**
    * Hook chamado quando um usuário faz logout
    *
    * @param array $post_logout_data Dados do logout
    * @return void
    */
   static function userLogout($post_logout_data) {
      global $DB;
      
      $users_id = isset($post_logout_data['user_id']) ? $post_logout_data['user_id'] : 0;
      
      if ($users_id > 0) {
         $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                   WHERE `users_id` = $users_id";
         $DB->query($query);
      }
   }
   
   /**
    * Tarefa cron para limpar usuários online antigos
    * 
    * @param $task CronTask object
    * @return integer
    */
   static function cleanupOnlineUsers($task) {
       global $DB;
       
       // Remove usuários inativos por mais de 1 minuto (mais rápido)
       $query = "DELETE FROM `glpi_plugin_notificacaochat_online_users` 
                 WHERE `last_active` < DATE_SUB(NOW(), INTERVAL 1 MINUTE)";
       $result = $DB->query($query);
       
       if ($result) {
           $task->addVolume($DB->affectedRows());
           return 1;
       }
       
       return 0;
   }
}
?>