/**
 * Sistema de notificações push para GLPI
 * Verifica notificações em tempo real
 */
(function() {
    'use strict';
    
    // Variáveis 
    let checkInterval = null;
    let lastCheckTime = new Date().getTime();
    let currentUserId = null; 
    
    /**
     * Inicializa o sistema de notificações
     */
    function init() {
        console.log('[Notificação Chat] Iniciando sistema de notificações push...');
        
        try {
            // Obtém ID do usuário atual com várias tentativas
            currentUserId = getCurrentUserId();
            
            // Se não conseguiu obter o ID pelo método normal, tenta obter da URL ou de forma síncrona
            if (!currentUserId) {
                currentUserId = getIdFromUrl() || getIdFromHeader() || getIdFromGlobals();
            }
            
            if (!currentUserId) {
                console.error('[Notificação Chat] Não foi possível obter o ID do usuário atual.');
                
                // Mesmo sem ID, criamos o container para notificações para o caso de um ID ser obtido posteriormente
                ensureNotificationContainer();
                addNotificationStyles();
                
                // Tenta novamente após um tempo maior (5 segundos)
                setTimeout(function() {
                    currentUserId = getCurrentUserId() || getIdFromUrl() || getIdFromHeader() || getIdFromGlobals();
                    
                    if (currentUserId) {
                        console.log('[Notificação Chat] ID do usuário obtido no segundo tentativa: ' + currentUserId);
                        startNotificationSystem();
                    } else {
                        console.error('[Notificação Chat] Falha ao obter ID do usuário mesmo após segunda tentativa.');
                    }
                }, 5000);
                
                return;
            }
            
            console.log('[Notificação Chat] ID do usuário atual: ' + currentUserId);
            startNotificationSystem();
            
        } catch (error) {
            console.error('[Notificação Chat] Erro ao inicializar o sistema de notificações:', error);
        }
    }
    
    /**
     * Inicia o sistema de notificações após obter o ID do usuário
     */
    function startNotificationSystem() {
        // Adiciona estilos CSS para notificações
        addNotificationStyles();
        
        // Cria contêiner para notificações se não existir
        ensureNotificationContainer();
        
        // Inicia verificação periódica (a cada 15 segundos)
        checkInterval = setInterval(checkForNotifications, 15000);
        
        // Verifica imediatamente na inicialização
        setTimeout(checkForNotifications, 2000);
        
        console.log('[Notificação Chat] Sistema de notificações iniciado com sucesso.');
    }
    
    /**
     * Obtém o ID do usuário a partir da URL
     */
    function getIdFromUrl() {
        try {
            const url = window.location.href;
            
            // Busca padrões como user_id=123 ou users_id=123
            const userIdMatch = url.match(/[?&](user_id|users_id|id_user|id)=(\d+)/i);
            if (userIdMatch && userIdMatch[2]) {
                return parseInt(userIdMatch[2]);
            }
            
            return null;
        } catch (error) {
            console.error('[Notificação Chat] Erro ao obter ID da URL:', error);
            return null;
        }
    }
    
    /**
     * Obtém o ID do usuário do cabeçalho/header da página
     */
    function getIdFromHeader() {
        try {
            // Busca elementos no cabeçalho que possam conter o ID do usuário
            const userElements = document.querySelectorAll('.user-info, .user-menu, .usermenu, .username, header .user');
            
            for (const element of userElements) {
                // Tenta extrair ID de atributos de dados
                if (element.dataset && element.dataset.userId) {
                    return parseInt(element.dataset.userId);
                }
                
                // Tenta extrair ID de links dentro do elemento
                const links = element.querySelectorAll('a');
                for (const link of links) {
                    if (link.href) {
                        const match = link.href.match(/id=(\d+)/);
                        if (match && match[1]) {
                            return parseInt(match[1]);
                        }
                    }
                }
                
                // Tenta extrair ID do texto do elemento (geralmente em formato "Nome (ID)")
                const text = element.textContent;
                const idMatch = text.match(/\((\d+)\)/);
                if (idMatch && idMatch[1]) {
                    return parseInt(idMatch[1]);
                }
            }
            
            return null;
        } catch (error) {
            console.error('[Notificação Chat] Erro ao obter ID do cabeçalho:', error);
            return null;
        }
    }
    
    /**
     * Tenta obter o ID do usuário de variáveis globais específicas do GLPI
     */
    function getIdFromGlobals() {
        try {
            // Verifica se há uma variável global SESSION com o ID do usuário
            if (typeof window.SESSION !== 'undefined' && window.SESSION.glpiID) {
                return parseInt(window.SESSION.glpiID);
            }
            
            // Verifica se há uma variável global CFG_GLPI com o ID do usuário
            if (typeof window.CFG_GLPI !== 'undefined') {
                if (window.CFG_GLPI.session && window.CFG_GLPI.session.glpiID) {
                    return parseInt(window.CFG_GLPI.session.glpiID);
                }
                
                if (window.CFG_GLPI.currentUserID) {
                    return parseInt(window.CFG_GLPI.currentUserID);
                }
            }
            
            // Verifica se há uma variável global GLPI com o ID do usuário
            if (typeof window.GLPI !== 'undefined' && window.GLPI.User && window.GLPI.User.id) {
                return parseInt(window.GLPI.User.id);
            }
            
            // Método alternativo: procura qualquer variável global que contenha o ID do usuário
            for (const key in window) {
                try {
                    const value = window[key];
                    if (typeof value === 'object' && value !== null) {
                        if (value.glpiID) {
                            return parseInt(value.glpiID);
                        }
                        if (value.session && value.session.glpiID) {
                            return parseInt(value.session.glpiID);
                        }
                        if (value.user && value.user.id) {
                            return parseInt(value.user.id);
                        }
                    }
                } catch (e) {
                    // Ignora erros ao acessar propriedades
                }
            }
            
            return null;
        } catch (error) {
            console.error('[Notificação Chat] Erro ao obter ID de variáveis globais:', error);
            return null;
        }
    }
    
    /**
     * Obtém o ID do usuário atual
     */
    function getCurrentUserId() {
        try {
            // GLPI global
            if (typeof(SESSION) !== 'undefined' && SESSION.glpiID) {
                return parseInt(SESSION.glpiID);
            }
            
            // Variável global CFG_GLPI
            if (typeof(CFG_GLPI) !== 'undefined') {
                if (CFG_GLPI.session && CFG_GLPI.session.glpiID) {
                    return parseInt(CFG_GLPI.session.glpiID);
                }
                
                if (CFG_GLPI.currentUserID) {
                    return parseInt(CFG_GLPI.currentUserID);
                }
            }
            
            // Data attribute no body
            const body = document.querySelector('body[data-userid]');
            if (body && body.dataset.userid) {
                return parseInt(body.dataset.userid);
            }
            
            // Menu usuário
            const userMenu = document.querySelector('.user-menu a, .usermenu a');
            if (userMenu && userMenu.href) {
                const match = userMenu.href.match(/id=(\d+)/);
                if (match && match[1]) {
                    return parseInt(match[1]);
                }
            }
            
            // Texto no menu do usuário
            const userMenuText = document.querySelector('.user-menu .d-none, .username, .usertext');
            if (userMenuText) {
                // Extrai ID numérico se presente no texto
                const userIdMatch = userMenuText.textContent.match(/\((\d+)\)/);
                if (userIdMatch && userIdMatch[1]) {
                    return parseInt(userIdMatch[1]);
                }
            }
            
            // Qualquer elemento com data-userid
            const userIdElement = document.querySelector('[data-userid]');
            if (userIdElement && userIdElement.dataset.userid) {
                return parseInt(userIdElement.dataset.userid);
            }
            
            return null;
        } catch (error) {
            console.error('[Notificação Chat] Erro ao obter ID do usuário:', error);
            return null;
        }
    }
    
    /**
     * Cria e garante que o contêiner de notificações existe
     */
    function ensureNotificationContainer() {
        if (!document.getElementById('notificacaochat-container')) {
            const container = document.createElement('div');
            container.id = 'notificacaochat-container';
            document.body.appendChild(container);
        }
    }
    
    /**
     * Adiciona estilos CSS para notificações
     */
    function addNotificationStyles() {
        if (document.getElementById('notificacaochat-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'notificacaochat-styles';
        style.textContent = `
            #notificacaochat-container {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 350px;
            }
            
            .notificacaochat-notification {
                background-color: #2f3f64;
                color: white;
                padding: 15px;
                border-radius: 5px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
                animation: notificacaochatSlideIn 0.3s ease-out;
                cursor: pointer;
                transition: all 0.2s;
                position: relative;
            }
            
            .notificacaochat-notification:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            }
            
            .notificacaochat-notification-title {
                font-weight: bold;
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .notificacaochat-notification-content {
                font-size: 13px;
                max-height: 100px;
                overflow-y: auto;
                word-wrap: break-word;
            }
            
            .notificacaochat-close {
                position: absolute;
                top: 5px;
                right: 5px;
                font-size: 16px;
                cursor: pointer;
                color: #ccc;
                width: 18px;
                height: 18px;
                text-align: center;
                line-height: 18px;
            }
            
            .notificacaochat-close:hover {
                color: white;
            }
            
            @keyframes notificacaochatSlideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        
        document.head.appendChild(style);
    }
    
    /**
     * Verifica se há novas notificações
     */
    function checkForNotifications() {
        if (!currentUserId) {
            console.error('[Notificação Chat] ID do usuário não disponível para verificar notificações');
            return;
        }
        
        const timestamp = new Date().getTime();
        let baseUrl = '';
        
        // Determina a URL base correta
        if (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) {
            baseUrl = CFG_GLPI.root_doc;
        } else {
            // Tenta extrair a URL base a partir do caminho atual
            const pathParts = window.location.pathname.split('/');
            const indexOfGlpi = pathParts.indexOf('glpi');
            if (indexOfGlpi !== -1) {
                baseUrl = '/' + pathParts.slice(0, indexOfGlpi + 1).join('/');
            } else {
                // Fallback para o domínio atual
                baseUrl = window.location.origin;
            }
        }
        
        const url = `${baseUrl}/plugins/notificacaochat/ajax/checkNotifications.php?t=${timestamp}&user_id=${currentUserId}&last_check=${lastCheckTime}`;
        
        console.log('[Notificação Chat] Verificando notificações:', url);
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Resposta HTTP não ok: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('[Notificação Chat] Resposta da verificação:', data);
                
                if (data && data.notifications && data.notifications.length > 0) {
                    console.log('[Notificação Chat] Recebidas ' + data.notifications.length + ' notificações');
                    
                    data.notifications.forEach(notification => {
                        console.log('[Notificação Chat] Mostrando notificação:', notification);
                        showNotification(notification);
                    });
                    
                    // Atualiza o timestamp da última verificação
                    lastCheckTime = data.timestamp || new Date().getTime();
                } else {
                    console.log('[Notificação Chat] Nenhuma nova notificação');
                }
            })
            .catch(error => {
                console.error('[Notificação Chat] Erro ao verificar notificações:', error);
            });
    }
    
    /**
     * Exibe uma notificação
     */
    function showNotification(notification) {
        try {
            ensureNotificationContainer();
            const container = document.getElementById('notificacaochat-container');
            
            const notificationElement = document.createElement('div');
            notificationElement.className = 'notificacaochat-notification';
            notificationElement.innerHTML = `
                <div class="notificacaochat-close">&times;</div>
                <div class="notificacaochat-notification-title">${notification.title || 'Nova mensagem'}</div>
                <div class="notificacaochat-notification-content">${notification.message}</div>
            `;
            
            // Adiciona evento de clique para fechar
            const closeButton = notificationElement.querySelector('.notificacaochat-close');
            if (closeButton) {
                closeButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (container.contains(notificationElement)) {
                        container.removeChild(notificationElement);
                    }
                });
            }
            
            // Adiciona evento de clique para a notificação
            notificationElement.addEventListener('click', function() {
                if (container.contains(notificationElement)) {
                    container.removeChild(notificationElement);
                }
                
                // Se tiver URL, navega para ela
                if (notification.url) {
                    window.location.href = notification.url;
                }
            });
            
            // Adiciona ao container
            container.appendChild(notificationElement);
            
            // Remove automaticamente após 15 segundos
            setTimeout(() => {
                if (container.contains(notificationElement)) {
                    container.removeChild(notificationElement);
                }
            }, 15000);
        } catch (error) {
            console.error('[Notificação Chat] Erro ao mostrar notificação:', error);
        }
    }
    
    // Inicializa quando o documento estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // Se o documento já foi carregado, inicializa com um pequeno atraso
        setTimeout(init, 500);
    }
})();