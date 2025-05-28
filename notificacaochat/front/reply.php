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

if ($user_id <= 0) {
    Html::displayErrorAndDie(__("Usuário inválido"));
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && isset($_POST['to_id'])) {
    // Verifica token CSRF
    Session::checkCSRF($_POST);
    
    $message = trim($_POST['message']);
    $to_id = intval($_POST['to_id']);
    
    if (empty($message)) {
        Session::addMessageAfterRedirect(__("A mensagem não pode estar vazia"), false, ERROR);
    } else if ($to_id <= 0) {
        Session::addMessageAfterRedirect(__("Destinatário inválido"), false, ERROR);
    } else {
        // Dados para a mensagem
        global $DB;
        $from_id = $_SESSION['glpiID'];
        $message_escaped = $DB->escape($message);
        
        // Insere a mensagem
        $query = "INSERT INTO `glpi_plugin_notificacaochat_messages` 
                 (`from_id`, `to_id`, `content`, `date_creation`, `is_read`) 
                 VALUES ($from_id, $to_id, '$message_escaped', NOW(), 0)";
        
        if ($DB->query($query)) {
            Session::addMessageAfterRedirect(__("Mensagem enviada com sucesso"));
            
            // Obtém o nome do remetente
            $from_name = "";
            $current_user = new User();
            if ($current_user->getFromDB($_SESSION['glpiID'])) {
                $from_name = $current_user->getFriendlyName();
            }
            
            // Tenta criar notificação nativa no GLPI
            if (method_exists('Alert', 'addAlert') || method_exists('Notification', 'addAlert')) {
                try {
                    $title = "Nova mensagem de $from_name";
                    if (method_exists('Alert', 'addAlert')) {
                        Alert::addAlert($to_id, $title, $message);
                    } else if (method_exists('Notification', 'addAlert')) {
                        Notification::addAlert($to_id, $title, $message);
                    }
                } catch (Exception $e) {
                    // Erro ao enviar notificação nativa
                }
            } else if ($DB->tableExists('glpi_notifications_alerts')) {
                // Para GLPI 10.x usando a tabela diretamente
                $title = "Nova mensagem de $from_name";
                $link = $CFG_GLPI['root_doc'] . '/plugins/notificacaochat/front/message.php?user_id='.$from_id;
                $date = date('Y-m-d H:i:s');
                
                $query = "INSERT INTO `glpi_notifications_alerts` 
                        (`entities_id`, `users_id`, `date`, `name`, `message`, `url`, `date_creation`) 
                        VALUES (0, $to_id, '$date', '$title', '$message_escaped', '$link', '$date')";
                
                $DB->query($query);
            }
            
            // Redireciona para a página anterior
            Html::back();
            exit;
        } else {
            Session::addMessageAfterRedirect(__("Erro ao enviar a mensagem"), false, ERROR);
        }
    }
}

// Obtém o nome do usuário
$user = new User();
$userName = "Usuário #$user_id";
$userGroup = "";

if ($user->getFromDB($user_id)) {
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

Html::header("Responder para $userName", $_SERVER['PHP_SELF']);

// Formulário de resposta
echo "<div class='center'>";
echo "<form method='post' action='".$_SERVER['PHP_SELF']."'>";
echo "<input type='hidden' name='to_id' value='$user_id'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<table class='tab_cadre_fixe'>";
echo "<tr class='tab_bg_2'><th colspan='2'>Responder para $userName";
if (!empty($userGroup)) {
    echo " <span style='font-size: 13px; color: #666;'>($userGroup)</span>";
}
echo "</th></tr>";

echo "<tr class='tab_bg_1'><td width='200px'>Mensagem:</td>";
echo "<td><textarea name='message' rows='6' cols='80' required='required'></textarea></td></tr>";

echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
echo "<input type='submit' name='submit' value='".__("Enviar")."' class='submit'> ";
echo "<a href='javascript:void(0)' onclick='history.back()' class='vsubmit'>".__("Cancelar")."</a>";
echo "</td></tr>";

echo "</table>";
echo "</form>";
echo "</div>";

Html::footer();