<?php
/*
 -------------------------------------------------------------------------
 Chat plugin para GLPI - Logger
 -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Acesso direto não permitido");
}

/**
 * Classe para logging do plugin
 */
class PluginNotificacaochatLogger {
   
   /**
    * Escreve log no arquivo do plugin
    */
   static function log($message, $level = 'INFO') {
      $logFile = GLPI_LOG_DIR . '/notificacaochat.log';
      $timestamp = date('Y-m-d H:i:s');
      $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
      
      file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
      
      // Também escreve no error_log do PHP para debug
      error_log("[NotificacaoChat] [$level] $message");
   }
   
   /**
    * Log de debug
    */
   static function debug($message) {
      self::log($message, 'DEBUG');
   }
   
   /**
    * Log de erro
    */
   static function error($message) {
      self::log($message, 'ERROR');
   }
   
   /**
    * Log de info
    */
   static function info($message) {
      self::log($message, 'INFO');
   }
}
?>