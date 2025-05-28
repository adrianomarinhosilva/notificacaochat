<?php
/*
 -------------------------------------------------------------------------
 Notificação Chat plugin para GLPI
 -------------------------------------------------------------------------
 */

include ("../../../inc/includes.php");

Session::checkLoginUser();

// Recebe os parâmetros
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$message_id = isset($_GET['message_id']) ? intval($_GET['message_id']) : 0;

// Obtém os dados do usuário remetente
$user = new User();
$userName = "Usuário #$user_id";
$userGroup = "";

if ($user_id > 0 && $user->getFromDB($user_id)) {
    $userName = $user->getFriendlyName();
    
    // Busca o grupo principal do usuário
    global $DB;
    $group_query = "SELECT g.name 
                   FROM glpi_groups g
                   JOIN glpi_groups_users gu ON g.id = gu.groups_id
                   WHERE gu.users_id = $user_id
                   ORDER BY g.name
                   LIMIT 1";
    
    $group_result = $DB->query($group_query);
    if ($group_result && $DB->numrows($group_result) > 0) {
        $group_data = $DB->fetchAssoc($group_result);
        $userGroup = $group_data['name'];
    }
}

Html::header("Mensagem de $userName", $_SERVER['PHP_SELF']);

echo "<div class='center'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr class='tab_bg_2'><th colspan='2'>Mensagem de $userName";
if (!empty($userGroup)) {
    echo " <span style='font-size: 13px; color: #666;'>($userGroup)</span>";
}
echo "</th></tr>";

// Se temos um ID de mensagem específico
if ($message_id > 0) {
    $query = "SELECT m.content, m.date_creation
              FROM glpi_plugin_notificacaochat_messages m
              WHERE m.id = $message_id
              AND (m.from_id = $user_id OR m.to_id = $user_id)
              LIMIT 1";
} else {
    // Se não temos, buscamos a última mensagem desse usuário
    $query = "SELECT m.content, m.date_creation
              FROM glpi_plugin_notificacaochat_messages m
              WHERE m.from_id = $user_id
              AND m.to_id = ".$_SESSION['glpiID']."
              ORDER BY m.date_creation DESC
              LIMIT 1";
}

global $DB;
$result = $DB->query($query);

if ($result && $DB->numrows($result) > 0) {
    $data = $DB->fetchAssoc($result);
    
    // Formata a data
    $date = new DateTime($data['date_creation']);
    $formatted_date = $date->format('d/m/Y H:i:s');
    
    echo "<tr class='tab_bg_1'><td width='200px'>Data:</td><td>".$formatted_date."</td></tr>";
    echo "<tr class='tab_bg_1'><td>Mensagem:</td><td>".nl2br(Html::clean($data['content']))."</td></tr>";
    
    // Botão para responder
    echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
    echo "<a href='javascript:void(0)' onclick='history.back()' class='vsubmit'>Voltar</a> ";
    echo "<a href='".$CFG_GLPI["root_doc"]."/plugins/notificacaochat/front/reply.php?user_id=$user_id' class='vsubmit'>Responder</a>";
    echo "</td></tr>";
} else {
    echo "<tr class='tab_bg_1'><td colspan='2'>Mensagem não encontrada ou já foi removida.</td></tr>";
    echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
    echo "<a href='javascript:void(0)' onclick='history.back()' class='vsubmit'>Voltar</a>";
    echo "</td></tr>";
}

echo "</table>";
echo "</div>";

Html::footer();