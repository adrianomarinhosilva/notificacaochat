<?php
/*
 -------------------------------------------------------------------------
 Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

define('PLUGIN_NOTIFICACAOCHAT_VERSION', '1.0.0');
define('PLUGIN_NOTIFICACAOCHAT_MIN_GLPI', '9.5.0');
define('PLUGIN_NOTIFICACAOCHAT_MAX_GLPI', '10.1.0');

/**
 * Inicialização do plugin
 *
 * @return boolean
 */
function plugin_init_notificacaochat() {
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // CSRF compliance
    $PLUGIN_HOOKS['csrf_compliant']['notificacaochat'] = true;
    
    // Adiciona JavaScript e CSS
    $PLUGIN_HOOKS['add_javascript']['notificacaochat'] = [
        'js/resourceTimeline.js',
        'js/notificacaochat.js'
    ];
    $PLUGIN_HOOKS['add_css']['notificacaochat'] = 'css/notificacaochat.css';
    
    // Hooks para injetar o HTML do chat
    $PLUGIN_HOOKS['display_central']['notificacaochat'] = 'plugin_notificacaochat_display_central';
    $PLUGIN_HOOKS['display_login']['notificacaochat'] = 'plugin_notificacaochat_display_login';
    $PLUGIN_HOOKS['post_show_item']['notificacaochat'] = 'plugin_notificacaochat_display_central';
    $PLUGIN_HOOKS['post_show_tab']['notificacaochat'] = 'plugin_notificacaochat_display_central';
    $PLUGIN_HOOKS['add_html']['notificacaochat'] = 'plugin_notificacaochat_add_html';
    
    // Hooks para login e logout
    $PLUGIN_HOOKS['post_login']['notificacaochat'] = ['PluginNotificacaochatUser', 'userLogin'];
    $PLUGIN_HOOKS['post_logout']['notificacaochat'] = ['PluginNotificacaochatUser', 'userLogout'];
    
    // HOOKS CORRIGIDOS - cada evento em uma linha separada
    $PLUGIN_HOOKS['item_add']['notificacaochat']['Ticket'] = ['PluginNotificacaochatSystem', 'onTicketAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['TicketValidation'] = ['PluginNotificacaochatSystem', 'onTicketValidationAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['ITILFollowup'] = ['PluginNotificacaochatSystem', 'onITILFollowupAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['TicketTask'] = ['PluginNotificacaochatSystem', 'onTaskAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['ITILSolution'] = ['PluginNotificacaochatSystem', 'onSolutionAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['Ticket_User'] = ['PluginNotificacaochatSystem', 'onTicketUserAdd'];
    $PLUGIN_HOOKS['item_add']['notificacaochat']['Group_Ticket'] = ['PluginNotificacaochatSystem', 'onGroupTicketAdd'];
    
    $PLUGIN_HOOKS['item_update']['notificacaochat']['Ticket'] = ['PluginNotificacaochatSystem', 'onTicketUpdate'];
    $PLUGIN_HOOKS['item_update']['notificacaochat']['TicketValidation'] = ['PluginNotificacaochatSystem', 'onTicketValidationUpdate'];
    $PLUGIN_HOOKS['item_update']['notificacaochat']['TicketTask'] = ['PluginNotificacaochatSystem', 'onTaskUpdate'];
    $PLUGIN_HOOKS['item_update']['notificacaochat']['ITILSolution'] = ['PluginNotificacaochatSystem', 'onSolutionUpdate'];
    
    $PLUGIN_HOOKS['item_delete']['notificacaochat']['Ticket'] = ['PluginNotificacaochatSystem', 'onTicketDelete'];
    $PLUGIN_HOOKS['item_delete']['notificacaochat']['Ticket_User'] = ['PluginNotificacaochatSystem', 'onTicketUserDelete'];
    $PLUGIN_HOOKS['item_delete']['notificacaochat']['Group_Ticket'] = ['PluginNotificacaochatSystem', 'onGroupTicketDelete'];
    
    // Registra tarefas cron
    $PLUGIN_HOOKS['cron']['notificacaochat'] = 60; // Executa a cada minuto
    
    return true;
}

/**
 * Função para exibir o chat
 * 
 * @return void
 */
function plugin_notificacaochat_display_central() {
    if (isset($_SESSION['glpiID'])) {
        echo '<div id="usuariosonline-container"></div>';
    }
}

/**
 * Função para exibir o chat após login
 * 
 * @return void
 */
function plugin_notificacaochat_display_login() {
    if (isset($_SESSION['glpiID'])) {
        echo '<div id="usuariosonline-container"></div>';
    }
}

/**
 * Função para adicionar HTML diretamente
 * 
 * @return string
 */
function plugin_notificacaochat_add_html() {
    if (isset($_SESSION['glpiID'])) {
        return '<div id="usuariosonline-container"></div>';
    }
    return '';
}

/**
 * Função cron principal
 */
function cron_notificacaochat($task) {
    // Limpa usuários online antigos
    PluginNotificacaochatUser::cleanupOnlineUsers($task);
    
    // Verifica tickets não atribuídos
    PluginNotificacaochatSystem::checkUnassignedTickets($task);
    
    // Verifica SLA
    PluginNotificacaochatSystem::checkSLAAlerts($task);
    
    // Verifica aprovações pendentes
    PluginNotificacaochatSystem::checkPendingApprovals($task);
    
    // Verifica escalações
    PluginNotificacaochatSystem::checkEscalations($task);
    
    // Verifica tarefas em atraso
    PluginNotificacaochatSystem::checkOverdueTasks($task);
    
    return 1;
}

/**
 * Verifica os requisitos do plugin
 *
 * @return boolean
 */
function plugin_notificacaochat_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_NOTIFICACAOCHAT_MIN_GLPI, 'lt') || 
        version_compare(GLPI_VERSION, PLUGIN_NOTIFICACAOCHAT_MAX_GLPI, 'gt')) {
        echo "Este plugin requer GLPI >= " . PLUGIN_NOTIFICACAOCHAT_MIN_GLPI . " e < " . PLUGIN_NOTIFICACAOCHAT_MAX_GLPI;
        return false;
    }
    return true;
}

/**
 * Verifica se o plugin pode ser desinstalado
 *
 * @return boolean
 */
function plugin_notificacaochat_check_config() {
    return true;
}

/**
 * Retorna informações sobre o plugin
 * 
 * @return array
 */
function plugin_version_notificacaochat() {
    return [
        'name'           => 'Chat GLPI',
        'version'        => PLUGIN_NOTIFICACAOCHAT_VERSION,
        'author'         => 'Adriano Marinho',
        'license'        => 'GPLv2+',
        'homepage'       => '',
        'minGlpiVersion' => PLUGIN_NOTIFICACAOCHAT_MIN_GLPI
    ];
}
?>