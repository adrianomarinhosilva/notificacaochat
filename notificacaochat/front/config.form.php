<?php
/*
 -------------------------------------------------------------------------
 Notificação Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

include ("../../../inc/includes.php");

Session::checkRight("config", UPDATE);

// Inicialização da classe de configuração
$config = new PluginNotificacaochatConfig();

if (isset($_POST['update'])) {
   // Verificação CSRF
   Session::checkCSRF($_POST);
   
   // Atualiza as configurações
   $input = [
      'id' => 1,
      'auto_show' => $_POST['auto_show'],
      'refresh_interval' => $_POST['refresh_interval']
   ];
   
   if ($config->update($input)) {
      Session::addMessageAfterRedirect(__('Configurações atualizadas com sucesso', 'notificacaochat'), true, INFO);
   }
   
   Html::back();
} else {
   Html::header('Notificação Chat', $_SERVER['PHP_SELF'], 'config', 'plugins');
   $config->showForm(1);
   Html::footer();
}