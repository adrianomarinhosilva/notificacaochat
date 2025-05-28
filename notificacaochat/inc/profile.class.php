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
 * Classe para gerenciar perfis
 */
class PluginNotificacaochatProfile extends CommonDBTM {
   
   static $rightname = "profile";
   
   /**
    * Verifica se o perfil atual tem permissão para usar o plugin
    *
    * @return boolean
    */
   static function canUse() {
      // Se o usuário for administrador ou super-admin, permite sempre
      if (Session::haveRight('config', UPDATE) || Session::haveRight('profile', UPDATE)) {
         return true;
      }
      
      // Lista de perfis permitidos
      $allowed_profiles = [4, 24, 28, 30, 31, 33, 34, 35, 36, 37, 38, 39, 172, 176, 180, 128];
      
      if (isset($_SESSION['glpiactiveprofile']['id']) 
          && in_array($_SESSION['glpiactiveprofile']['id'], $allowed_profiles)) {
         return true;
      }
      
      // Adiciona compatibilidade com GLPI 10 - verifica pelo nome do perfil
      $allowed_profile_names = ['Super-Admin', 'Admin', 'Technician', 'Supervisor', 'Técnico', 'Suporte', 'Administrador'];
      
      if (isset($_SESSION['glpiactiveprofile']['name']) 
          && in_array($_SESSION['glpiactiveprofile']['name'], $allowed_profile_names)) {
         return true;
      }
      
      return false;
   }
   
   /**
    * Obtém todos os perfis com permissão para usar o plugin
    * 
    * @return array Lista de perfis permitidos
    */
   static function getAllowedProfiles() {
      global $DB;
      
      $profiles = [];
      
      // Busca todos os perfis que têm permissão config ou profile
      $query = "SELECT id, name FROM glpi_profiles 
               WHERE interface = 'central'
               ORDER BY name";
      
      $result = $DB->query($query);
      if ($result) {
         while ($data = $DB->fetchAssoc($result)) {
            // Adiciona à lista
            $profiles[] = [
               'id' => $data['id'],
               'name' => $data['name']
            ];
         }
      }
      
      return $profiles;
   }
}