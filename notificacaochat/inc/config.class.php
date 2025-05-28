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
 * Classe para gerenciar configurações do plugin
 */
class PluginNotificacaochatConfig extends CommonDBTM {
   
   static $rightname = 'config';
   
   /**
    * Retorna o nome do plugin
    *
    * @param $nb  inteiro número de itens (padrão 0)
    */
   static function getTypeName($nb = 0) {
      return 'Configuração do Notificação Chat';
   }
   
   /**
    * Obtém as configurações do plugin
    *
    * @return array
    */
   static function getConfig() {
      $config = new self();
      $config->getFromDB(1);
      return $config->fields;
   }
   
   /**
    * Exibe o formulário de configuração
    *
    * @param $ID        inteiro ID do item
    * @param $options   array de opções
    */
   function showForm($ID, $options = []) {
      global $CFG_GLPI;
      
      $this->getFromDB($ID);
      
      $options['colspan'] = 2;
      $this->showFormHeader($options);
      
      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Exibir automaticamente', 'notificacaochat') . "</td>";
      echo "<td>";
      Dropdown::showYesNo('auto_show', $this->fields['auto_show']);
      echo "</td>";
      echo "</tr>";
      
      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Intervalo de atualização (segundos)', 'notificacaochat') . "</td>";
      echo "<td>";
      echo Html::input('refresh_interval', ['value' => $this->fields['refresh_interval'], 'size' => 5]);
      echo "</td>";
      echo "</tr>";
      
      $this->showFormButtons($options);
      
      return true;
   }
}