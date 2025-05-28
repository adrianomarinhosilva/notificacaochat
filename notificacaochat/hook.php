<?php
/*
 -------------------------------------------------------------------------
 Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

/**
 * Função chamada na instalação do plugin
 *
 * @return boolean
 */
function plugin_notificacaochat_install() {
    global $DB;
    
    $success = true;
    
    // Tabela para rastrear usuários online
    if (!$DB->tableExists("glpi_plugin_notificacaochat_online_users")) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_online_users` (
                 `id` int(11) NOT NULL AUTO_INCREMENT,
                 `users_id` int(11) NOT NULL,
                 `last_active` datetime NOT NULL,
                 PRIMARY KEY (`id`),
                 UNIQUE KEY `users_id` (`users_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        
        $success = $DB->query($query);
        
        if (!$success) {
            return false;
        }
    }
    
    // Tabela para mensagens
    if (!$DB->tableExists("glpi_plugin_notificacaochat_messages")) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_messages` (
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
                 KEY `is_system_message` (`is_system_message`),
                 KEY `system_message_type` (`system_message_type`)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        
        $success = $DB->query($query);
        
        if (!$success) {
            return false;
        }
    }
    
    // Tabela para configurações de notificações do sistema
    if (!$DB->tableExists("glpi_plugin_notificacaochat_system_config")) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notificacaochat_system_config` (
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
        
        $success = $DB->query($query);
        
        if (!$success) {
            return false;
        }
    }
    
    // Adiciona usuários iniciais
    if ($success && isset($_SESSION['glpiID'])) {
        $query = "REPLACE INTO `glpi_plugin_notificacaochat_online_users` 
                  (`users_id`, `last_active`) 
                  VALUES ({$_SESSION['glpiID']}, NOW())";
        $DB->query($query);
    }
    
    return $success;
}

/**
 * Função chamada na desinstalação do plugin
 *
 * @return boolean
 */
function plugin_notificacaochat_uninstall() {
    global $DB;
    
    $success = true;
    
    $tables = [
        'glpi_plugin_notificacaochat_online_users',
        'glpi_plugin_notificacaochat_messages',
        'glpi_plugin_notificacaochat_system_config'
    ];
    
    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $query = "DROP TABLE `$table`";
            $success = $DB->query($query) && $success;
        }
    }
    
    return $success;
}