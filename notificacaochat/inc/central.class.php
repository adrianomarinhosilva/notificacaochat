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
 * Classe para exibir o chat em todas as páginas
 */
class PluginNotificacaochatCentral extends CommonGLPI {
   
   /**
    * Função para exibir o chat
    * 
    * @return void
    */
   static function displayChat() {
      // Perfis permitidos
      $allowed_profiles = [4, 24, 28, 30, 31, 33, 34, 35, 36, 37, 38, 39, 172, 176, 180];
      
      if (isset($_SESSION['glpiactiveprofile']['id']) 
          && in_array($_SESSION['glpiactiveprofile']['id'], $allowed_profiles)) {
         echo '<div id="notificacaochat-container" class="notificacaochat-container"></div>';
      }
   }
}