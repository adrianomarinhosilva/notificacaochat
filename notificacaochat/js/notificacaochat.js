/**
 * Chat plugin para GLPI
 * Versão completa e otimizada com sistema de mensagens do sistema e validação de tickets
 */
(function() {
    'use strict';
    
    // Variáveis principais
let chatContainer;
let currentUserId = null;
let baseUrl = '';
let usersListInterval;
let messageCheckInterval;
let validationCheckInterval; // Novo: intervalo separado para validações
let activeUserId = null;
let unreadMessages = {};
let lastNotificationCheck = Date.now();
let activeUserCanValidate = false;
let lastValidationsCheck = 0;
let loadedValidationIds = new Set();
let isLoadingValidations = false;
let unreadValidationsCount = 0;

// Novos: controle inteligente de verificação
let validationCheckFrequency = 30000; // Inicia com 30 segundos
let lastValidationActivity = Date.now();
let consecutiveEmptyChecks = 0;
let isUserActive = true;
let lastUserActivity = Date.now();

// Tipos de mensagens do sistema - EXPANDIDOS
let systemMessageTypes = {
    'new_ticket': '🎫',
    'assigned_ticket': '👤',
    'pending_approval': '⏳',
    'unassigned_ticket': '⚠️',
    'ticket_update': '📋',
    'ticket_solution': '✅',
    'ticket_reopened': '🔄',
    'ticket_closed': '🔒',
    'validation_chat_request': '🔍',
    'validation_chat_response': '📋',
    'itilfollowup_added': '💬',
    'followup_added': '💬',
    'task_added': '📋',
    'task_updated': '📝',
    'task_completed': '✅',
    'task_overdue': '⏰',
    'validation_request': '🔍',
    'validation_response': '📋',
    'solution_proposed': '✅',
    'solution_approved': '✅',
    'solution_rejected': '❌',
    'sla_alert': '⏰',
    'sla_breach': '🚨',
    'escalation': '🔔',
    'approval_request': '📝',
    'approval_granted': '✅',
    'approval_denied': '❌',
    'reminder_due': '📅'
};

/**
 * Detecta atividade do usuário para ajustar frequência de verificação
 */
function setupActivityDetection() {
    const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    
    function updateUserActivity() {
        isUserActive = true;
        lastUserActivity = Date.now();
        
        // Se o usuário ficou ativo, reduz a frequência de verificação
        if (validationCheckFrequency > 30000) {
            validationCheckFrequency = 30000; // Volta para 30 segundos
            console.log('[Chat] Usuário ativo - reduzindo frequência para 30s');
            restartValidationInterval();
        }
    }
    
    // Adiciona listeners de atividade
    activityEvents.forEach(event => {
        document.addEventListener(event, updateUserActivity, true);
    });
    
    // Verifica inatividade a cada minuto
    setInterval(() => {
        const timeSinceActivity = Date.now() - lastUserActivity;
        
        if (timeSinceActivity > 300000) { // 5 minutos de inatividade
            isUserActive = false;
            if (validationCheckFrequency < 120000) {
                validationCheckFrequency = 120000; // 2 minutos quando inativo
                console.log('[Chat] Usuário inativo - aumentando frequência para 2 minutos');
                restartValidationInterval();
            }
        }
    }, 60000);
}

/**
 * Verificação de validações otimizada com controle inteligente
 */
function loadPendingValidationsOptimized() {
    if (isLoadingValidations) {
        console.log('[Chat] Verificação de validações já em andamento - pulando');
        return;
    }
    
    // Verifica se precisa mesmo fazer a requisição
    const now = Date.now();
    const timeSinceLastCheck = now - lastValidationsCheck;
    
    // Se checou há menos de 20 segundos, pula (exceto se user estiver no chat do sistema)
    if (timeSinceLastCheck < 20000 && activeUserId !== 0) {
        console.log('[Chat] Verificação muito recente - pulando');
        return;
    }
    
    isLoadingValidations = true;
    lastValidationsCheck = now;
    
    // Adiciona timestamp e hash para evitar cache
    const cacheBreaker = `${now}_${Math.random().toString(36).substr(2, 9)}`;
    
    fetch(`${baseUrl}validations.php?action=getPendingValidations&t=${cacheBreaker}&light=1`, {
        method: 'GET',
        cache: 'no-store',
        headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[Chat] Resposta de validações otimizada recebida:', data);
            
            if (data.success) {
                processValidationsResponse(data);
            } else {
                consecutiveEmptyChecks++;
                adjustValidationFrequency();
                console.log('[Chat] Resposta de validações não contém dados válidos:', data);
            }
        })
        .catch(error => {
            consecutiveEmptyChecks++;
            adjustValidationFrequency();
            console.error('Erro ao carregar validações pendentes (otimizado):', error);
        })
        .finally(() => {
            isLoadingValidations = false;
        });
}

/**
 * Processa resposta de validações e ajusta frequência
 */
function processValidationsResponse(data) {
    if (data.validations && data.validations.length > 0) {
        // Tem validações - resetar contadores e reduzir frequência
        consecutiveEmptyChecks = 0;
        lastValidationActivity = Date.now();
        
        if (validationCheckFrequency > 30000) {
            validationCheckFrequency = 30000; // 30 segundos quando tem atividade
            restartValidationInterval();
        }
        
        const currentValidationIds = new Set(data.validations.map(v => v.validation_id));
        const newValidations = data.validations.filter(v => !loadedValidationIds.has(v.validation_id));
        
        if (newValidations.length > 0 && activeUserId !== 0) {
            unreadValidationsCount += newValidations.length;
            console.log(`[Chat] ${newValidations.length} nova(s) validação(ões) detectada(s). Total não vistas: ${unreadValidationsCount}`);
        }
        
        loadedValidationIds = currentValidationIds;
        
        if (activeUserId === 0) {
            if (data.validations.length > 0) {
                console.log(`[Chat] Exibindo ${data.validations.length} validações no chat do sistema`);
                displayPendingValidationsInSystemChat(data.validations);
            } else {
                console.log('[Chat] Nenhuma validação para exibir - limpando chat do sistema');
                clearValidationsFromSystemChat();
            }
        }
        
        if (data.validations.length === 0 && activeUserId === 0) {
            unreadValidationsCount = 0;
        }
        
        updateSystemUnreadCount();
    } else {
        // Não tem validações - aumentar contador e ajustar frequência
        consecutiveEmptyChecks++;
        adjustValidationFrequency();
    }
}

/**
 * Ajusta a frequência de verificação baseado na atividade
 */
function adjustValidationFrequency() {
    const timeSinceLastActivity = Date.now() - lastValidationActivity;
    
    // Se não há atividade há mais de 10 minutos e usuário inativo
    if (timeSinceLastActivity > 600000 && !isUserActive && consecutiveEmptyChecks > 5) {
        if (validationCheckFrequency < 300000) { // Máximo 5 minutos
            validationCheckFrequency = Math.min(validationCheckFrequency * 1.5, 300000);
            console.log(`[Chat] Aumentando frequência para ${validationCheckFrequency/1000}s (sem atividade)`);
            restartValidationInterval();
        }
    }
    // Se há atividade recente, volta para frequência normal
    else if (timeSinceLastActivity < 120000 && consecutiveEmptyChecks < 3) {
        if (validationCheckFrequency > 30000) {
            validationCheckFrequency = 30000;
            console.log('[Chat] Retornando para frequência normal de 30s');
            restartValidationInterval();
        }
    }
}

/**
 * Reinicia o intervalo de verificação de validações com nova frequência
 */
function restartValidationInterval() {
    if (validationCheckInterval) {
        clearInterval(validationCheckInterval);
    }
    
    validationCheckInterval = setInterval(() => {
        if (currentUserId) {
            loadPendingValidationsOptimized();
        }
    }, validationCheckFrequency);
}

    /**
 * Converte URLs em texto para links clicáveis
 */
function convertLinksToHTML(text) {
    // Regex para detectar URLs
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    
    // Substitui URLs por links HTML
    return text.replace(urlRegex, function(url) {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="color: #2f3f64; text-decoration: underline;">${url}</a>`;
    });
}
    
    /**
 * Inicializa o plugin
 */
function init() {
    console.log('Iniciando Chat GLPI...');
    
    try {
        // Define a URL base - ajustada para garantir o caminho correto
        baseUrl = window.location.origin + '/plugins/notificacaochat/ajax/';
        
        // Tenta obter ID do usuário diretamente via AJAX (método mais confiável)
        fetchUserIdFromServer();
    } catch (error) {
        console.error('Erro na inicialização do Chat GLPI:', error);
        createBasicUI();
    }
}
    
    /**
 * Busca o ID do usuário diretamente do servidor
 */
function fetchUserIdFromServer() {
    console.log('Buscando ID do usuário do servidor...');
    
    fetch(baseUrl + 'api.php?action=getUserId')
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.user_id) {
                currentUserId = data.user_id;
                console.log('ID do usuário obtido com sucesso:', currentUserId);
                initializeChat();
            } else {
                console.error('Não foi possível obter o ID do usuário:', data.error);
                createBasicUI();
            }
        })
        .catch(error => {
            console.error('Erro ao buscar ID do usuário:', error);
            createBasicUI();
        });
}
    
    /**
 * Inicializa a interface e funcionalidades do chat
 */
function initializeChat() {
    // Cria a interface do chat
    createChatUI();
    
    // Adiciona estilos CSS
    addChatStyles();
    
    // Carrega usuários online
    loadUsers();
    
    // Configura detecção de atividade do usuário
    setupActivityDetection();
    
    // Configura atualização periódica de usuários online (3 segundos para resposta mais rápida)
    usersListInterval = setInterval(loadUsers, 3000);
    
    // Configura verificação periódica de novas mensagens (1 segundo para notificações mais rápidas)
    messageCheckInterval = setInterval(checkForNewMessages, 1000);
    
    // INICIALIZA O SISTEMA DE VALIDAÇÕES OTIMIZADO
    setTimeout(() => {
        initializeOptimizedValidationSystem();
    }, 2000); // Aguarda 2 segundos para garantir que o sistema esteja pronto
    
    // Configura evento de antes de fechar a página
    window.addEventListener('beforeunload', function() {
        // Informa ao servidor que o usuário está saindo
        navigator.sendBeacon(baseUrl + 'userLogout.php');
        
        // Limpa os intervalos
        clearInterval(usersListInterval);
        clearInterval(messageCheckInterval);
        if (validationCheckInterval) {
            clearInterval(validationCheckInterval);
        }
    });
    
    console.log('Chat GLPI inicializado com sucesso!');
}

/**
 * Inicializa o sistema de validações otimizado
 */
function initializeOptimizedValidationSystem() {
    console.log('[Chat] Inicializando sistema de validações otimizado...');
    
    // Carrega validações iniciais imediatamente
    setTimeout(() => {
        loadPendingValidationsOptimized();
    }, 1000);
    
    // Configura verificação periódica otimizada
    restartValidationInterval();
    
    console.log('[Chat] Sistema de validações otimizado inicializado');
}
    
    /**
 * Cria a interface do chat
 */
function createChatUI() {
    // Cria ou localiza o contêiner do chat
    chatContainer = document.getElementById('usuariosonline-container');
    if (!chatContainer) {
        chatContainer = document.createElement('div');
        chatContainer.id = 'usuariosonline-container';
        document.body.appendChild(chatContainer);
    }
    
    // Define o HTML da interface
    chatContainer.innerHTML = `
        <div class="chat-wrapper">
            <div class="chat-header">
                <h3>Chat GLPI <span class="total-unread-badge" style="display: none;">0</span></h3>
                <div class="chat-header-buttons">
                    <button class="chat-toggle">+</button>
                </div>
            </div>
            <div class="chat-body" style="display: none;">
                <div class="chat-users-list">
                    <div class="chat-search">
                        <input type="text" placeholder="Pesquisar usuários..." id="chat-search-input">
                    </div>
                    <div class="chat-users" id="chat-users"></div>
                </div>
                <div class="chat-conversation" style="display: none;">
                    <div class="chat-conversation-header">
                        <button class="chat-back-button">←</button>
                        <div class="chat-user-info-header">
                            <span class="chat-user-name" data-user-id="">Nome do usuário</span>
                            <span class="chat-user-group-header"></span>
                        </div>
                        <div class="chat-conversation-actions">
                            <button class="chat-save-button" title="Salvar conversa como acompanhamento">💾</button>
                            <button class="chat-create-ticket-button" title="Criar ticket a partir desta conversa">🎫</button>
                            <button class="chat-clear-button" title="Limpar conversa">🗑️</button>
                        </div>
                    </div>
                    <div class="chat-validation-status" style="display: none;">
                        <div class="validation-status-content">
                            <span class="validation-icon">🔍</span>
                            <span class="validation-text">Este usuário pode validar tickets</span>
                            <button class="validation-request-btn" title="Solicitar validação de ticket">Solicitar Validação</button>
                        </div>
                    </div>
                    <div class="chat-create-ticket-area" style="display: none;">
                        <div class="create-ticket-header">
                            <h4>🎫 Criar Ticket a partir da Conversa</h4>
                        </div>
                        <div class="create-ticket-content">
                            <div class="ticket-preview" id="ticket-preview"></div>
                            <div class="ticket-form">
                                <div class="form-group">
                                    <label for="ticket-entity-create">Entidade:</label>
                                    <select id="ticket-entity-create" required>
                                        <option value="">Carregando entidades...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="ticket-category-create">Categoria:</label>
                                    <select id="ticket-category-create">
                                        <option value="">Carregando categorias...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="ticket-title-create">Título do Ticket:</label>
                                    <input type="text" id="ticket-title-create" placeholder="Ex: Problema com sistema X" required>
                                </div>
                                <div class="form-group">
                                    <label for="ticket-description-create">Descrição Adicional:</label>
                                    <textarea id="ticket-description-create" placeholder="Descreva detalhes adicionais sobre o problema..." rows="4"></textarea>
                                </div>
                                <div class="ticket-actions">
                                    <button class="btn-confirm-create">✅ Criar Ticket</button>
                                    <button class="btn-cancel-create">❌ Cancelar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-save-conversation-area" style="display: none;">
                        <div class="save-conversation-header">
                            <h4>💾 Salvar Conversa como Acompanhamento</h4>
                        </div>
                        <div class="save-conversation-content">
                            <div class="conversation-preview" id="conversation-preview"></div>
                            <div class="save-form">
                                <div class="form-group">
                                    <label for="ticket-number-save">Número do Ticket:</label>
                                    <input type="number" id="ticket-number-save" placeholder="Ex: 12345" min="1" required>
                                </div>
                                <div class="save-actions">
                                    <button class="btn-confirm-save">✅ Confirmar e Salvar</button>
                                    <button class="btn-cancel-save">❌ Cancelar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-messages" id="chat-messages">
                        <div class="chat-no-messages">Nenhuma mensagem. Inicie uma conversa!</div>
                    </div>
                    <div class="chat-input-container">
                        <textarea id="chat-input" placeholder="Enviar Msg" rows="1"></textarea>
                        <button id="chat-send-button">Enviar</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Adiciona eventos aos elementos da interface
    addChatEvents();
}
    
    /**
     * Cria uma interface básica para quando o chat não puder ser inicializado completamente
     */
    function createBasicUI() {
        // Cria ou localiza o contêiner do chat
        chatContainer = document.getElementById('usuariosonline-container');
        if (!chatContainer) {
            chatContainer = document.createElement('div');
            chatContainer.id = 'usuariosonline-container';
            document.body.appendChild(chatContainer);
        }
        
        // Define o HTML da interface básica
        chatContainer.innerHTML = `
            <div class="chat-wrapper">
                <div class="chat-header">
                    <h3>Chat GLPI <span class="total-unread-badge" style="display: none;">0</span></h3>
                    <button class="chat-toggle">+</button>
                </div>
                <div class="chat-body">
                    <div class="chat-error">
                        <p>Não foi possível inicializar o chat.</p>
                        <button id="chat-retry-button">Tentar novamente</button>
                    </div>
                </div>
            </div>
        `;
        
        // Adiciona estilos CSS
        addChatStyles();
        
        // Adiciona evento ao botão de alternância
        const toggleButton = chatContainer.querySelector('.chat-toggle');
        if (toggleButton) {
            toggleButton.addEventListener('click', toggleChat);
        }
        
        // Adiciona evento ao botão de tentar novamente
        const retryButton = document.getElementById('chat-retry-button');
        if (retryButton) {
            retryButton.addEventListener('click', function() {
                fetchUserIdFromServer();
            });
        }
    }
    
    /**
 * Adiciona eventos aos elementos da interface do chat
 */
function addChatEvents() {
    // Botão de alternância (minimizar/maximizar)
    const toggleButton = chatContainer.querySelector('.chat-toggle');
    if (toggleButton) {
        toggleButton.addEventListener('click', toggleChat);
    }
    
    // Também permite clicar no cabeçalho para alternar
    const chatHeader = chatContainer.querySelector('.chat-header');
    if (chatHeader) {
        chatHeader.addEventListener('click', function(e) {
            // Não executa se o clique foi no botão de alternância
            if (e.target !== toggleButton) {
                toggleChat();
            }
        });
    }
    
    // Botão de voltar na conversa
    const backButton = chatContainer.querySelector('.chat-back-button');
    if (backButton) {
        backButton.addEventListener('click', function() {
            showUsersList();
            activeUserId = null;
            activeUserCanValidate = false;
        });
    }
    
    // Botão de limpar conversa
    const clearButton = chatContainer.querySelector('.chat-clear-button');
    if (clearButton) {
        clearButton.addEventListener('click', function() {
            if (activeUserId && confirm('Tem certeza que deseja limpar toda a conversa? Esta ação não pode ser desfeita.')) {
                clearConversation(activeUserId);
                // Recarrega a página após limpar a conversa
                window.location.reload();
            }
        });
    }

    // Botão de salvar conversa
    const saveButton = chatContainer.querySelector('.chat-save-button');
    if (saveButton) {
        saveButton.addEventListener('click', function() {
            if (activeUserId && activeUserId !== 0) {
                openSaveConversationArea();
            } else {
                alert('Selecione uma conversa para salvar.');
            }
        });
    }
    
    // Botão de criar ticket
    const createTicketButton = chatContainer.querySelector('.chat-create-ticket-button');
    if (createTicketButton) {
        createTicketButton.addEventListener('click', function() {
            if (activeUserId && activeUserId !== 0) {
                openCreateTicketArea();
            } else {
                alert('Selecione uma conversa para criar um ticket.');
            }
        });
    }
    
    // Botões da área de salvar conversa
    const confirmSaveButton = chatContainer.querySelector('.btn-confirm-save');
    if (confirmSaveButton) {
        confirmSaveButton.addEventListener('click', confirmSaveConversation);
    }
    
    const cancelSaveButton = chatContainer.querySelector('.btn-cancel-save');
    if (cancelSaveButton) {
        cancelSaveButton.addEventListener('click', closeSaveConversationArea);
    }
    
    // Botões da área de criar ticket
    const confirmCreateButton = chatContainer.querySelector('.btn-confirm-create');
    if (confirmCreateButton) {
        confirmCreateButton.addEventListener('click', confirmCreateTicket);
    }
    
    const cancelCreateButton = chatContainer.querySelector('.btn-cancel-create');
    if (cancelCreateButton) {
        cancelCreateButton.addEventListener('click', closeCreateTicketArea);
    }
        
    // Botão de solicitar validação
    const validationButton = chatContainer.querySelector('.validation-request-btn');
    if (validationButton) {
        validationButton.addEventListener('click', function() {
            if (activeUserId) {
                openValidationRequestModal();
            }
        });
    }
    
    // Botão de enviar mensagem
    const sendButton = document.getElementById('chat-send-button');
    if (sendButton) {
        sendButton.addEventListener('click', sendMessage);
    }
    
    // Campo de entrada de mensagem (evento de tecla Enter)
    const chatInput = document.getElementById('chat-input');
    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // Auto-resize do textarea
        chatInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
    }
    
    // Campo de pesquisa de usuários
    const searchInput = document.getElementById('chat-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterUsers(this.value);
        });
    }
}

/**
 * Abre a área de criação de ticket
 */
function openCreateTicketArea() {
    const createArea = chatContainer.querySelector('.chat-create-ticket-area');
    const messagesContainer = document.getElementById('chat-messages');
    
    if (createArea && messagesContainer) {
        // Redimensiona o chat para modo expandido
        resizeChatForCreateTicket();
        
        // Mostra a área de criar ticket
        createArea.style.display = 'block';
        
        // Carrega preview da conversa
        loadConversationPreviewForTicket();
        
        // Carrega entidades e categorias
        loadEntitiesAndCategories();
        
        // Foca no campo de título
        setTimeout(() => {
            const titleInput = document.getElementById('ticket-title-create');
            if (titleInput) {
                titleInput.focus();
            }
        }, 100);
    }
}

/**
 * Fecha a área de criação de ticket e restaura tamanho normal
 */
function closeCreateTicketArea() {
    const createArea = chatContainer.querySelector('.chat-create-ticket-area');
    
    if (createArea) {
        createArea.style.display = 'none';
        
        // Restaura o tamanho normal do chat
        resizeChatForNormalConversation();
        
        // Limpa os campos
        clearTicketForm();
    }
}

/**
 * Carrega preview da conversa para o ticket
 */
function loadConversationPreviewForTicket() {
    const previewContainer = document.getElementById('ticket-preview');
    const messagesContainer = document.getElementById('chat-messages');
    
    if (!previewContainer || !messagesContainer || !activeUserId) return;
    
    // Obtém todas as mensagens da conversa atual (exceto mensagens do sistema)
    const messages = messagesContainer.querySelectorAll('.chat-message:not(.system-message)');
    
    if (messages.length === 0) {
        previewContainer.innerHTML = '<div class="no-messages-preview">Nenhuma mensagem para incluir no ticket.</div>';
        return;
    }
    
    let previewHTML = '<div class="ticket-preview-header">Conversa que será incluída no ticket:</div>';
    previewHTML += '<div class="ticket-preview-messages">';
    
    messages.forEach((message, index) => {
        try {
            const isSent = message.classList.contains('sent');
            const contentElement = message.querySelector('.chat-message-content');
            const timeElement = message.querySelector('.chat-message-time');
            
            // Sanitiza o conteúdo
            let content = '';
            if (contentElement) {
                content = contentElement.textContent || contentElement.innerText || '';
                content = content.trim();
                // Escapa HTML para exibição
                content = content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            
            let time = '';
            if (timeElement) {
                time = timeElement.textContent || timeElement.innerText || '';
                time = time.trim();
            }
            
            const sender = isSent ? 'Você' : getActiveUserName();
            
            previewHTML += `
                <div class="preview-message ${isSent ? 'sent' : 'received'}">
                    <div class="preview-sender">${sender}:</div>
                    <div class="preview-content">${content}</div>
                    <div class="preview-time">${time}</div>
                </div>
            `;
            
        } catch (error) {
            console.error('[Chat] Erro ao processar mensagem no preview:', error);
            previewHTML += `
                <div class="preview-message error">
                    <div class="preview-sender">Sistema:</div>
                    <div class="preview-content">[Erro ao exibir mensagem ${index + 1}]</div>
                    <div class="preview-time">-</div>
                </div>
            `;
        }
    });
    
    previewHTML += '</div>';
    previewHTML += `<div class="conversation-summary">Total de mensagens: ${messages.length}</div>`;
    
    previewContainer.innerHTML = previewHTML;
}

/**
 * Carrega entidades e categorias via AJAX
 */
function loadEntitiesAndCategories() {
    console.log('[Chat] Carregando entidades e categorias...');
    
    // Carrega entidades
    fetch(`${baseUrl}api.php?action=getEntities&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[Chat] Resposta de entidades:', data);
            
            const entitySelect = document.getElementById('ticket-entity-create');
            if (entitySelect) {
                if (data.success && data.entities && data.entities.length > 0) {
                    entitySelect.innerHTML = '<option value="">Selecione uma entidade...</option>';
                    data.entities.forEach(entity => {
                        entitySelect.innerHTML += `<option value="${entity.id}">${entity.name}</option>`;
                    });
                    console.log('[Chat] Carregadas', data.entities.length, 'entidades');
                } else {
                    entitySelect.innerHTML = '<option value="">Nenhuma entidade disponível</option>';
                    console.warn('[Chat] Nenhuma entidade encontrada');
                }
            }
        })
        .catch(error => {
            console.error('[Chat] Erro ao carregar entidades:', error);
            const entitySelect = document.getElementById('ticket-entity-create');
            if (entitySelect) {
                entitySelect.innerHTML = '<option value="">Erro ao carregar entidades</option>';
            }
        });
    
    // Carrega categorias
    fetch(`${baseUrl}api.php?action=getCategories&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[Chat] Resposta de categorias:', data);
            
            const categorySelect = document.getElementById('ticket-category-create');
            if (categorySelect) {
                if (data.success && data.categories && data.categories.length > 0) {
                    categorySelect.innerHTML = '<option value="">Selecione uma categoria (opcional)...</option>';
                    data.categories.forEach(category => {
                        categorySelect.innerHTML += `<option value="${category.id}">${category.name}</option>`;
                    });
                    console.log('[Chat] Carregadas', data.categories.length, 'categorias');
                } else {
                    categorySelect.innerHTML = '<option value="">Nenhuma categoria disponível</option>';
                    console.warn('[Chat] Nenhuma categoria encontrada');
                }
            }
        })
        .catch(error => {
            console.error('[Chat] Erro ao carregar categorias:', error);
            const categorySelect = document.getElementById('ticket-category-create');
            if (categorySelect) {
                categorySelect.innerHTML = '<option value="">Erro ao carregar categorias</option>';
            }
        });
}

/**
 * Confirma e cria o ticket
 */
function confirmCreateTicket() {
    console.log('[Chat] Iniciando criação do ticket');
    
    const entitySelect = document.getElementById('ticket-entity-create');
    const categorySelect = document.getElementById('ticket-category-create');
    const titleInput = document.getElementById('ticket-title-create');
    const descriptionTextarea = document.getElementById('ticket-description-create');
    
    const entityId = entitySelect ? parseInt(entitySelect.value) : -1;
    const categoryId = categorySelect ? parseInt(categorySelect.value) : 0;
    const title = titleInput ? titleInput.value.trim() : '';
    const description = descriptionTextarea ? descriptionTextarea.value.trim() : '';
    
    // Validações
    if (entityId < 0) { // Permite 0 (entidade raiz)
        alert('Por favor, selecione uma entidade.');
        entitySelect?.focus();
        return;
    }
    
    if (!title || title.length < 5) {
        alert('Por favor, informe um título com pelo menos 5 caracteres.');
        titleInput?.focus();
        return;
    }
    
    if (!description || description.length < 10) {
        alert('Por favor, informe uma descrição com pelo menos 10 caracteres.');
        descriptionTextarea?.focus();
        return;
    }
    
    if (!activeUserId || activeUserId === 0) {
        alert('Nenhuma conversa ativa para criar ticket.');
        return;
    }
    
    // Desabilita botões durante o processo
    const confirmBtn = document.querySelector('.btn-confirm-create');
    const cancelBtn = document.querySelector('.btn-cancel-create');
    
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Criando...';
    }
    if (cancelBtn) {
        cancelBtn.disabled = true;
    }
    
    console.log('[Chat] Coletando mensagens...');
    
    // Coleta mensagens da conversa
    const messages = collectSimpleConversationData();
    
    if (!messages || messages.length === 0) {
        alert('Nenhuma mensagem encontrada para incluir no ticket.');
        resetCreateTicketButtons();
        return;
    }
    
    console.log('[Chat] Encontradas', messages.length, 'mensagens para incluir no ticket');
    
    // Prepara dados para envio
    const ticketData = {
        action: 'create_ticket_from_chat',
        entity_id: entityId,
        category_id: categoryId || 0,
        title: title,
        description: description,
        other_user_id: activeUserId,
        current_user_id: currentUserId,
        other_user_name: getActiveUserName(),
        messages: messages,
        total_messages: messages.length
    };
    
    console.log('[Chat] Dados do ticket:', ticketData);
    
    // Envia dados para o servidor
    fetch(`${baseUrl}createTicket.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(ticketData)
    })
    .then(response => {
        console.log('[Chat] Resposta HTTP:', response.status, response.statusText);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.json();
    })
    .then(data => {
        console.log('[Chat] Resposta do servidor:', data);
        
        if (data.success) {
            // Cria uma mensagem de sucesso com link clicável
            const ticketUrl = data.ticket_url;
            const ticketId = data.ticket_id;
            const messagesCount = data.messages_count;
            
            // Fecha a área de criação de ticket ANTES de mostrar a mensagem
            closeCreateTicketArea();
            
            // Volta para a lista de usuários
            showUsersList();
            activeUserId = null;
            
            // Restaura o tamanho normal do chat
            resizeChatForNormalConversation();
            
            // Cria modal customizado com link clicável
            showTicketCreatedModal(ticketId, title, messagesCount, ticketUrl);
            
        } else {
            console.error('[Chat] Erro do servidor:', data.error || data.message);
            alert('❌ Erro: ' + (data.error || data.message || 'Erro desconhecido'));
            resetCreateTicketButtons();
        }
    })
    .catch(error => {
        console.error('[Chat] Erro na requisição:', error);
        alert('❌ Erro ao criar ticket: ' + error.message);
        resetCreateTicketButtons();
    });
}

/**
 * Mostra modal de ticket criado com sucesso
 */
function showTicketCreatedModal(ticketId, title, messagesCount, ticketUrl) {
    // Remove modal anterior se existir
    const existingModal = document.getElementById('ticket-created-modal');
    if (existingModal) {
        document.body.removeChild(existingModal);
    }
    
    // Cria o modal
    const modal = document.createElement('div');
    modal.id = 'ticket-created-modal';
    modal.className = 'ticket-created-modal';
    modal.innerHTML = `
        <div class="ticket-created-content">
            <div class="ticket-created-header">
                <h3>✅ Ticket Criado com Sucesso!</h3>
                <button class="close-ticket-created-modal">&times;</button>
            </div>
            <div class="ticket-created-body">
                <div class="ticket-success-info">
                    <div class="ticket-info-item">
                        <strong>Número do Ticket:</strong> #${ticketId}
                    </div>
                    <div class="ticket-info-item">
                        <strong>Título:</strong> ${title}
                    </div>
                    <div class="ticket-info-item">
                        <strong>Mensagens incluídas:</strong> ${messagesCount}
                    </div>
                </div>
                
                <div class="ticket-actions-container">
                    <p>O ticket foi criado com sucesso! Você pode acessá-lo clicando no link abaixo:</p>
                    <a href="${ticketUrl}" target="_blank" class="ticket-link-btn">
                        🎫 Abrir Ticket #${ticketId}
                    </a>
                </div>
            </div>
            <div class="ticket-created-footer">
                <button class="btn-close-modal">Fechar</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Adiciona eventos
    const closeButton = modal.querySelector('.close-ticket-created-modal');
    const closeModalButton = modal.querySelector('.btn-close-modal');
    
    function closeModal() {
        if (document.body.contains(modal)) {
            document.body.removeChild(modal);
        }
    }
    
    closeButton.addEventListener('click', closeModal);
    closeModalButton.addEventListener('click', closeModal);
    
    // Fecha modal clicando fora
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });
    
    // Fecha modal com ESC
    document.addEventListener('keydown', function escHandler(e) {
        if (e.key === 'Escape') {
            closeModal();
            document.removeEventListener('keydown', escHandler);
        }
    });
    
    // Auto-close após 10 segundos
    setTimeout(() => {
        if (document.body.contains(modal)) {
            closeModal();
        }
    }, 10000);
}


/**
 * Reativa os botões após erro
 */
function resetCreateTicketButtons() {
    const confirmBtn = document.querySelector('.btn-confirm-create');
    const cancelBtn = document.querySelector('.btn-cancel-create');
    
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = '✅ Criar Ticket';
    }
    if (cancelBtn) {
        cancelBtn.disabled = false;
    }
}

/**
 * Limpa o formulário de criação de ticket
 */
function clearTicketForm() {
    const entitySelect = document.getElementById('ticket-entity-create');
    const categorySelect = document.getElementById('ticket-category-create');
    const titleInput = document.getElementById('ticket-title-create');
    const descriptionTextarea = document.getElementById('ticket-description-create');
    
    if (entitySelect) entitySelect.value = '';
    if (categorySelect) categorySelect.value = '';
    if (titleInput) titleInput.value = '';
    if (descriptionTextarea) descriptionTextarea.value = '';
}

/**
 * Redimensiona o chat para modo de criação de ticket
 */
function resizeChatForCreateTicket() {
    if (chatContainer) {
        chatContainer.classList.add('create-ticket-mode');
    }
}
    
  /**
 * Verifica novas mensagens para o usuário atual (otimizada)
 */
function checkForNewMessages() {
    if (!currentUserId) return;
    
    const timestamp = new Date().getTime();
    fetch(`${baseUrl}messages.php?action=getNewMessages&user_id=${currentUserId}&t=${timestamp}`, {
        cache: 'no-store',
        headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success && data.messages && data.messages.length > 0) {
            // Processa mensagens normalmente
            const newMessages = data.messages.filter(message => {
                const messageTime = new Date(message.date_creation).getTime();
                return messageTime > lastNotificationCheck;
            });
            
            if (newMessages.length > 0) {
                lastNotificationCheck = timestamp;
                
                newMessages.forEach(message => {
                    if (message.is_system_message) {
                        if (activeUserId === 0) {
                            loadChatHistory(0);
                        }
                    } else {
                        const fromId = message.from_id.toString();
                        
                        if (activeUserId && fromId === activeUserId.toString()) {
                            loadChatHistory(activeUserId);
                            markMessagesAsRead(activeUserId);
                        } else {
                            if (!unreadMessages[fromId]) {
                                unreadMessages[fromId] = 0;
                            }
                            unreadMessages[fromId]++;
                        }
                    }
                });
                
                updateUnreadCounters();
            }
        }
        
        // REMOVIDO: A verificação de validações não é mais feita aqui
        // loadPendingValidations(); <- REMOVIDO
        
    })
    .catch(error => {
        console.error('Erro ao verificar novas mensagens:', error);
        // REMOVIDO: A verificação de validações não é mais feita aqui
        // loadPendingValidations(); <- REMOVIDO
    });
}
    
    /**
     * Alterna entre minimizado e maximizado
     */
    function toggleChat() {
        const chatBody = chatContainer.querySelector('.chat-body');
        const toggleButton = chatContainer.querySelector('.chat-toggle');
        
        if (chatBody) {
            const isVisible = chatBody.style.display !== 'none';
            chatBody.style.display = isVisible ? 'none' : 'block';
            
            if (toggleButton) {
                toggleButton.textContent = isVisible ? '+' : '−';
            }
        }
    }
    
     /**
     * Carrega a lista de usuários online
     */
    function loadUsers() {
    const usersContainer = document.getElementById('chat-users');
    if (!usersContainer) return;
    
    const timestamp = new Date().getTime();
    fetch(`${baseUrl}api.php?action=getOnlineUsers&t=${timestamp}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.users) {
                updateUsersList(data.users);
            } else {
                if (usersContainer.children.length === 0) {
                    usersContainer.innerHTML = '<div class="chat-error">Erro ao carregar usuários online.</div>';
                }
            }
        })
        .catch(error => {
            console.error('Erro ao carregar usuários:', error);
            if (usersContainer.children.length === 0) {
                usersContainer.innerHTML = `<div class="chat-error">Erro ao carregar usuários online: ${error.message}</div>`;
            }
        });
}
    
    /**
 * Atualiza a lista de usuários sem piscar
 */
function updateUsersList(users) {
    const usersContainer = document.getElementById('chat-users');
    if (!usersContainer) return;
    
    // Adiciona entrada especial para mensagens do sistema
    let systemMessagesEntry = usersContainer.querySelector('.chat-user[data-user-id="0"]');
    if (!systemMessagesEntry) {
        systemMessagesEntry = document.createElement('div');
        systemMessagesEntry.className = 'chat-user chat-user-system';
        systemMessagesEntry.setAttribute('data-user-id', '0');
        systemMessagesEntry.setAttribute('data-user-name', 'Notificações');
        systemMessagesEntry.setAttribute('data-user-group', 'Notificações do Sistema');
        
        systemMessagesEntry.innerHTML = `
            <div class="chat-user-info">
                <div class="chat-user-left">
                    <div class="chat-user-name-container">
                        <span class="chat-user-status" style="background-color: #2196f3;"></span>
                        <span class="chat-user-name">🤖 Notificações</span>
                    </div>
                    <div class="chat-user-group">Notificações do Sistema</div>
                </div>
                <div class="chat-user-tickets">
                    <div class="ticket-labels">
                        <span class="ticket-label">SYS</span>
                    </div>
                    <div class="ticket-counters">
                        <span class="ticket-counter" style="background-color: #2196f3;" id="system-unread-count">0</span>
                    </div>
                </div>
            </div>
        `;
        
        // Adiciona evento de clique para o sistema
        const systemUserLeft = systemMessagesEntry.querySelector('.chat-user-left');
        systemUserLeft.addEventListener('click', function(e) {
            e.stopPropagation();
            openSystemConversation();
        });
        
        // Insere no topo da lista
        usersContainer.insertBefore(systemMessagesEntry, usersContainer.firstChild);
    }
    
    // Atualiza contador de mensagens do sistema não lidas (incluindo validações)
updateSystemUnreadCount();
    
    if (!users || users.length === 0) {
        const existingUsers = usersContainer.querySelectorAll('.chat-user:not(.chat-user-system)');
        if (existingUsers.length === 0) {
            // Não adiciona mensagem "nenhum usuário" se só temos a entrada do sistema
        }
        return;
    }
    
    const filteredUsers = users.filter(user => user.id != currentUserId);
    
    if (filteredUsers.length === 0) {
        return;
    }
    
    const currentUsers = {};
    usersContainer.querySelectorAll('.chat-user:not(.chat-user-system)').forEach(el => {
        const userId = el.getAttribute('data-user-id');
        currentUsers[userId] = {
            element: el,
            badgeElement: el.querySelector('.unread-badge')
        };
    });
    
    // Atualizar ou adicionar usuários (resto do código continua igual...)
    filteredUsers.forEach(user => {
        const userId = user.id.toString();
        
        if (currentUsers[userId]) {
            const badgeElement = currentUsers[userId].badgeElement;
            const userUnreadCount = unreadMessages[userId] || 0;
            
            if (userUnreadCount > 0) {
                if (badgeElement) {
                    badgeElement.textContent = userUnreadCount;
                } else {
                    const ticketsContainer = currentUsers[userId].element.querySelector('.chat-user-tickets .ticket-counters');
                    if (ticketsContainer) {
                        const badge = document.createElement('span');
                        badge.className = 'unread-badge';
                        badge.textContent = userUnreadCount;
                        ticketsContainer.appendChild(badge);
                    }
                }
            } else if (badgeElement) {
                badgeElement.remove();
            }
            
            // Atualiza contadores de tickets
            const ticketsContainer = currentUsers[userId].element.querySelector('.chat-user-tickets');
            if (ticketsContainer && user.tickets) {
                const pendingTicket = ticketsContainer.querySelector('.ticket-pending');
                const processingTicket = ticketsContainer.querySelector('.ticket-processing');
                
                if (pendingTicket) {
                    pendingTicket.textContent = user.tickets.pending || '0';
                }
                
                if (processingTicket) {
                    processingTicket.textContent = user.tickets.processing || '0';
                }
            }
            
            delete currentUsers[userId];
        } else {
            // Usuário não está na lista, adiciona novo
            const userUnreadCount = unreadMessages[userId] || 0;
            
            const pendingCount = user.tickets && user.tickets.pending ? user.tickets.pending : '0';
            const processingCount = user.tickets && user.tickets.processing ? user.tickets.processing : '0';
            
            const userElement = document.createElement('div');
            userElement.className = 'chat-user';
            userElement.setAttribute('data-user-id', user.id);
            userElement.setAttribute('data-user-name', user.name);
            userElement.setAttribute('data-user-group', user.group_name || '');

            userElement.innerHTML = `
                <div class="chat-user-info">
                    <div class="chat-user-left">
                        <div class="chat-user-name-container">
                            <span class="chat-user-status"></span>
                            <span class="chat-user-name">${user.name}</span>
                        </div>
                        <div class="chat-user-group">${user.group_name || ''}</div>
                    </div>
                    <div class="chat-user-tickets">
                        <div class="ticket-labels">
                            <span class="ticket-label">PEND</span>
                            <span class="ticket-label">ATEN</span>
                            <span class="ticket-label">MSG</span>
                        </div>
                        <div class="ticket-counters">
                            <span class="ticket-counter ticket-pending" title="Tickets pendentes" data-status="4">${pendingCount}</span>
                            <span class="ticket-counter ticket-processing" title="Tickets em processamento" data-status="2">${processingCount}</span>
                            <span class="ticket-counter unread-badge" title="Mensagens não lidas">${(unreadMessages[userId] || 0) > 0 ? (unreadMessages[userId] || 0) : '0'}</span>
                        </div>
                    </div>
                </div>
            `;
            
            // Adiciona evento de clique para o nome/grupo do usuário
            const userLeft = userElement.querySelector('.chat-user-left');
            userLeft.addEventListener('click', function(e) {
                e.stopPropagation();
                const userId = userElement.getAttribute('data-user-id');
                const userName = userElement.getAttribute('data-user-name');
                const userGroup = userElement.getAttribute('data-user-group');
                openConversation(userId, userName, userGroup);
            });
            
            // Adiciona eventos de clique para os contadores de tickets
            const ticketCounters = userElement.querySelectorAll('.ticket-counter');
            ticketCounters.forEach(counter => {
                if (!counter.classList.contains('unread-badge')) {
                    counter.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const userId = userElement.getAttribute('data-user-id');
                        const status = this.getAttribute('data-status');
                        openTicketsList(userId, status);
                    });
                }
            });
            
            // Adiciona ao container (ordena alfabeticamente, mas depois da entrada do sistema)
            let inserted = false;
            const existingUsers = usersContainer.querySelectorAll('.chat-user:not(.chat-user-system)');
            for (let i = 0; i < existingUsers.length; i++) {
                const existingName = existingUsers[i].getAttribute('data-user-name').toLowerCase();
                const newName = user.name.toLowerCase();
                
                if (newName < existingName) {
                    usersContainer.insertBefore(userElement, existingUsers[i]);
                    inserted = true;
                    break;
                }
            }
            
            if (!inserted) {
                usersContainer.appendChild(userElement);
            }
        }
    });
    
    // Remove usuários que não estão mais online
    for (const userId in currentUsers) {
        if (Object.prototype.hasOwnProperty.call(currentUsers, userId)) {
            const element = currentUsers[userId].element;
            if (element) {
                element.style.transition = 'opacity 0.5s';
                element.style.opacity = '0';
                
                setTimeout(() => {
                    if (element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                }, 500);
            }
        }
    }
    
    // Atualiza o contador total
    updateTotalUnreadCounter();
}

/**
 * Abre conversa com o sistema
 */
function openSystemConversation() {
    activeUserId = 0;
    activeUserCanValidate = false;
    
    // ZERA O CONTADOR DE VALIDAÇÕES NÃO VISTAS
    unreadValidationsCount = 0;
    console.log('[Chat] Abrindo chat do sistema - zerando contador de validações');
    
    const chatUserName = chatContainer.querySelector('.chat-conversation-header .chat-user-name');
    const chatUserGroup = chatContainer.querySelector('.chat-conversation-header .chat-user-group-header');
    
    if (chatUserName) {
        chatUserName.textContent = '🤖 Notificações';
        chatUserName.setAttribute('data-user-id', '0');
    }
    
    if (chatUserGroup) {
        chatUserGroup.innerHTML = `
            Notificações do Sistema
            <button class="mark-all-read-btn" id="mark-all-system-btn" title="Marcar todas como lidas">
                ✓ Marcar Todas
            </button>
        `;
        
        // Adiciona evento de clique ao botão
        const markAllBtn = chatUserGroup.querySelector('#mark-all-system-btn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                markAllSystemMessagesAsRead();
            });
        }
    }
    
    const usersList = chatContainer.querySelector('.chat-users-list');
    const conversation = chatContainer.querySelector('.chat-conversation');
    
    if (usersList && conversation) {
        usersList.style.display = 'none';
        conversation.style.display = 'flex';
    }
    
    // REDIMENSIONA O CHAT PARA O Notificações
    resizeChatForSystemConversation();
    
    // Oculta área de validação para conversa do sistema
    updateValidationStatusDisplay();
    
    // Desabilita área de input para sistema
    disableInputForSystemConversation();
    
    // Força o carregamento inicial das validações
    lastValidationsCheck = 0;
    
    // Carrega mensagens do sistema
    loadSystemMessages();
    
    // Marca validações como vistas
    if (loadedValidationIds.size > 0) {
        markValidationsAsSeen();
    }
    
    // Atualiza contadores (devem mostrar 0 agora)
    setTimeout(() => {
        updateSystemUnreadCount();
        updateTotalUnreadCounter();
    }, 500);
}

/**
 * Inicializa o sistema de verificação de validações (versão mais agressiva)
 */
function initializeValidationSystem() {
    console.log('[Chat] Inicializando sistema de validações...');
    
    // Carrega validações iniciais imediatamente
    setTimeout(() => {
        loadPendingValidations();
    }, 1000);
    
    // Configura verificação periódica mais frequente para validações
    setInterval(() => {
        if (currentUserId) {
            console.log('[Chat] Verificação periódica de validações...');
            loadPendingValidations();
        }
    }, 5000); // A cada 5 segundos
    
    console.log('[Chat] Sistema de validações inicializado');
}

/**
 * Desabilita a área de input para conversas do sistema
 */
function disableInputForSystemConversation() {
    const inputContainer = chatContainer.querySelector('.chat-input-container');
    const chatInput = document.getElementById('chat-input');
    const sendButton = document.getElementById('chat-send-button');
    
    if (inputContainer && chatInput && sendButton) {
        // Adiciona classe especial para estilização
        inputContainer.classList.add('system-conversation');
        
        // Desabilita os elementos
        chatInput.disabled = true;
        sendButton.disabled = true;
        sendButton.textContent = 'Sistema';
        
        // Adiciona ícone visual
        const systemIcon = inputContainer.querySelector('.system-icon');
        if (!systemIcon) {
            const icon = document.createElement('div');
            icon.className = 'system-icon';
            icon.innerHTML = '🤖';
            icon.title = 'Conversa do Sistema - Somente leitura';
            inputContainer.insertBefore(icon, chatInput);
        }
    }
}

/**
 * Abre a área de salvar conversa
 */
function openSaveConversationArea() {
    const saveArea = chatContainer.querySelector('.chat-save-conversation-area');
    const messagesContainer = document.getElementById('chat-messages');
    
    if (saveArea && messagesContainer) {
        // Redimensiona o chat para modo expandido
        resizeChatForSaveConversation();
        
        // Mostra a área de salvar
        saveArea.style.display = 'block';
        
        // Carrega preview da conversa
        loadConversationPreview();
        
        // Foca no campo do ticket
        setTimeout(() => {
            const ticketInput = document.getElementById('ticket-number-save');
            if (ticketInput) {
                ticketInput.focus();
            }
        }, 100);
    }
}

/**
 * Fecha a área de salvar conversa
 */
function closeSaveConversationArea() {
    const saveArea = chatContainer.querySelector('.chat-save-conversation-area');
    
    if (saveArea) {
        saveArea.style.display = 'none';
        
        // Restaura o tamanho normal do chat
        resizeChatForNormalConversation();
        
        // Limpa o campo do ticket
        const ticketInput = document.getElementById('ticket-number-save');
        if (ticketInput) {
            ticketInput.value = '';
        }
    }
}

/**
 * Carrega preview da conversa atual
 */
function loadConversationPreview() {
    const previewContainer = document.getElementById('conversation-preview');
    const messagesContainer = document.getElementById('chat-messages');
    
    if (!previewContainer || !messagesContainer || !activeUserId) return;
    
    // Obtém todas as mensagens da conversa atual (exceto mensagens do sistema)
    const messages = messagesContainer.querySelectorAll('.chat-message:not(.system-message)');
    
    if (messages.length === 0) {
        previewContainer.innerHTML = '<div class="no-messages-preview">Nenhuma mensagem para salvar.</div>';
        return;
    }
    
    let previewHTML = '<div class="conversation-preview-header">Preview da Conversa:</div>';
    previewHTML += '<div class="conversation-preview-messages">';
    
    messages.forEach((message, index) => {
        try {
            const isSent = message.classList.contains('sent');
            const contentElement = message.querySelector('.chat-message-content');
            const timeElement = message.querySelector('.chat-message-time');
            
            // Sanitiza o conteúdo
            let content = '';
            if (contentElement) {
                content = contentElement.textContent || contentElement.innerText || '';
                content = content.trim();
                // Escapa HTML para exibição
                content = content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            
            let time = '';
            if (timeElement) {
                time = timeElement.textContent || timeElement.innerText || '';
                time = time.trim();
            }
            
            const sender = isSent ? 'Você' : getActiveUserName();
            
            previewHTML += `
                <div class="preview-message ${isSent ? 'sent' : 'received'}">
                    <div class="preview-sender">${sender}:</div>
                    <div class="preview-content">${content}</div>
                    <div class="preview-time">${time}</div>
                </div>
            `;
            
        } catch (error) {
            console.error('[Chat] Erro ao processar mensagem no preview:', error);
            previewHTML += `
                <div class="preview-message error">
                    <div class="preview-sender">Sistema:</div>
                    <div class="preview-content">[Erro ao exibir mensagem ${index + 1}]</div>
                    <div class="preview-time">-</div>
                </div>
            `;
        }
    });
    
    previewHTML += '</div>';
    previewHTML += `<div class="conversation-summary">Total de mensagens: ${messages.length}</div>`;
    
    previewContainer.innerHTML = previewHTML;
}

/**
 * Obtém o nome do usuário ativo
 */
function getActiveUserName() {
    const chatUserName = chatContainer.querySelector('.chat-conversation-header .chat-user-name');
    return chatUserName ? chatUserName.textContent : 'Usuário';
}

/**
 * Confirma e salva a conversa como ITILFollowup - Versão Inspirada no WhatsApp
 */
function confirmSaveConversation() {
    console.log('[Chat] Iniciando salvamento da conversa');
    
    const ticketInput = document.getElementById('ticket-number-save');
    const ticketNumber = ticketInput ? parseInt(ticketInput.value) : 0;
    
    if (!ticketNumber || ticketNumber <= 0) {
        alert('Por favor, informe um número de ticket válido.');
        ticketInput?.focus();
        return;
    }
    
    if (!activeUserId || activeUserId === 0) {
        alert('Nenhuma conversa ativa para salvar.');
        return;
    }
    
    
    
    // Desabilita botões durante o processo
    const confirmBtn = document.querySelector('.btn-confirm-save');
    const cancelBtn = document.querySelector('.btn-cancel-save');
    
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Salvando...';
    }
    if (cancelBtn) {
        cancelBtn.disabled = true;
    }
    
    console.log('[Chat] Coletando mensagens...');
    
    // Coleta mensagens de forma mais simples
    const messages = collectSimpleConversationData();
    
    if (!messages || messages.length === 0) {
        alert('Nenhuma mensagem encontrada para salvar.');
        resetSaveButtons();
        return;
    }
    
    console.log('[Chat] Encontradas', messages.length, 'mensagens para salvar');
    
    // Usa jQuery se disponível, senão fetch
    if (typeof $ !== 'undefined') {
        saveWithJQuery(ticketNumber, messages);
    } else {
        saveWithFetch(ticketNumber, messages);
    }
}

/**
 * Envia dados da conversa para o servidor
 */
function sendConversationToServer(formData, messageCount) {
    fetch(`${baseUrl}saveConversation.php`, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('[Chat] Resposta HTTP:', response.status, response.statusText);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.text();
    })
    .then(responseText => {
        console.log('[Chat] Resposta raw (primeiros 500 chars):', responseText.substring(0, 500));
        
        // Tenta limpar resposta de possível output extra
        let cleanResponse = responseText.trim();
        
        // Se começar com erro do PHP, extrai apenas o JSON
        const jsonStart = cleanResponse.indexOf('{');
        const jsonEnd = cleanResponse.lastIndexOf('}');
        
        if (jsonStart >= 0 && jsonEnd > jsonStart) {
            cleanResponse = cleanResponse.substring(jsonStart, jsonEnd + 1);
        }
        
        try {
            const data = JSON.parse(cleanResponse);
            handleServerResponse(data, messageCount);
        } catch (parseError) {
            console.error('[Chat] Erro ao parsear resposta:', parseError);
            console.error('[Chat] Resposta original:', responseText);
            hideLoadingIndicator();
            showErrorNotification('❌ Resposta inválida do servidor');
        }
    })
    .catch(error => {
        console.error('[Chat] Erro na requisição:', error);
        hideLoadingIndicator();
        showErrorNotification('❌ Erro de conexão: ' + error.message);
    });
}

/**
 * Salva usando jQuery (similar ao plugin WhatsApp)
 */
function saveWithJQuery(ticketNumber, messages) {
    $.ajax({
        url: baseUrl + 'saveConversation.php',
        type: 'POST',
        data: {
            action: 'save_chat_conversation',
            ticket_id: ticketNumber,
            other_user_id: activeUserId,
            current_user_id: currentUserId,
            other_user_name: getActiveUserName(),
            messages: messages,
            total_messages: messages.length
        },
        success: function(response) {
            console.log('[Chat] Resposta do servidor:', response);
            
            if (response.success) {
                alert(`✅ Conversa salva com sucesso!\n\n${messages.length} mensagens foram adicionadas como acompanhamento no ticket #${ticketNumber}.`);
                
                // Limpa tudo
                clearConversationAfterSave();
                closeSaveConversationArea();
                showUsersList();
                activeUserId = null;
                
                // Minimiza chat
                minimizeChat();
                
                // ATUALIZA A PÁGINA
                console.log('[Chat] Atualizando página...');
                window.location.reload();
                
            } else {
                console.error('[Chat] Erro do servidor:', response.error);
                alert('❌ Erro: ' + (response.error || response.message || 'Erro desconhecido'));
                resetSaveButtons();
            }
        },
        error: function(xhr, status, error) {
            console.error('[Chat] Erro na requisição:', error);
            console.error('[Chat] Status:', status);
            console.error('[Chat] Response:', xhr.responseText);
            alert('❌ Erro ao salvar conversa: ' + error);
            resetSaveButtons();
        }
    });
}

/**
 * Salva usando fetch (fallback)
 */
function saveWithFetch(ticketNumber, messages) {
    const formData = new FormData();
    formData.append('action', 'save_chat_conversation');
    formData.append('ticket_id', ticketNumber);
    formData.append('other_user_id', activeUserId);
    formData.append('current_user_id', currentUserId);
    formData.append('other_user_name', getActiveUserName());
    formData.append('total_messages', messages.length);
    
    // Adiciona cada mensagem individualmente
    messages.forEach((message, index) => {
        formData.append(`messages[${index}][sender]`, message.sender);
        formData.append(`messages[${index}][content]`, message.content);
        formData.append(`messages[${index}][time]`, message.time);
        formData.append(`messages[${index}][is_sent]`, message.is_sent ? '1' : '0');
    });
    
    fetch(baseUrl + 'saveConversation.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('[Chat] Resposta do servidor:', data);
        
        if (data.success) {
            alert(`✅ Conversa salva com sucesso!\n\n${messages.length} mensagens foram adicionadas como acompanhamento no ticket #${ticketNumber}.`);
            
            // Limpa tudo
            clearConversationAfterSave();
            closeSaveConversationArea();
            showUsersList();
            activeUserId = null;
            
            // Minimiza chat
            minimizeChat();
            
            // ATUALIZA A PÁGINA
            console.log('[Chat] Atualizando página...');
            window.location.reload();
            
        } else {
            console.error('[Chat] Erro do servidor:', data.error);
            alert('❌ Erro: ' + (data.error || data.message || 'Erro desconhecido'));
            resetSaveButtons();
        }
    })
    .catch(error => {
        console.error('[Chat] Erro na requisição:', error);
        alert('❌ Erro ao salvar conversa: ' + error.message);
        resetSaveButtons();
    });
}

/**
 * Reativa os botões após erro
 */
function resetSaveButtons() {
    const confirmBtn = document.querySelector('.btn-confirm-save');
    const cancelBtn = document.querySelector('.btn-cancel-save');
    
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = '✅ Confirmar e Salvar';
    }
    if (cancelBtn) {
        cancelBtn.disabled = false;
    }
}

/**
 * Minimiza o chat
 */
function minimizeChat() {
    const chatBody = chatContainer.querySelector('.chat-body');
    const toggleButton = chatContainer.querySelector('.chat-toggle');
    
    if (chatBody && toggleButton) {
        chatBody.style.display = 'none';
        toggleButton.textContent = '+';
    }
}

/**
 * Coleta dados da conversa de forma mais simples
 */
function collectSimpleConversationData() {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return [];
    
    const messageElements = messagesContainer.querySelectorAll('.chat-message:not(.system-message)');
    const messages = [];
    
    messageElements.forEach((element, index) => {
        try {
            const isSent = element.classList.contains('sent');
            const contentElement = element.querySelector('.chat-message-content');
            const timeElement = element.querySelector('.chat-message-time');
            
            if (contentElement) {
                const content = (contentElement.textContent || contentElement.innerText || '').trim();
                const time = timeElement ? (timeElement.textContent || timeElement.innerText || '').trim() : '';
                
                if (content.length > 0) {
                    messages.push({
                        sender: isSent ? 'Você' : getActiveUserName(),
                        content: content,
                        time: time || 'Sem horário',
                        is_sent: isSent
                    });
                }
            }
        } catch (error) {
            console.error('[Chat] Erro ao processar mensagem', index, ':', error);
        }
    });
    
    return messages;
}

/**
 * Manipula resposta do servidor
 */
function handleServerResponse(data, messageCount) {
    hideLoadingIndicator();
    
    if (data.success) {
        showSuccessNotification(`✅ Conversa salva! ${messageCount} mensagens adicionadas ao ticket.`);
        
        // Limpa a conversa
        clearConversationAfterSave();
        
        // Fecha a área de salvar
        closeSaveConversationArea();
        
        // Volta para a lista de usuários
        showUsersList();
        activeUserId = null;
        
        // Minimiza o chat
        const chatBody = chatContainer.querySelector('.chat-body');
        const toggleButton = chatContainer.querySelector('.chat-toggle');
        if (chatBody && toggleButton) {
            chatBody.style.display = 'none';
            toggleButton.textContent = '+';
        }
        
    } else {
        console.error('[Chat] Erro do servidor:', data.error);
        showErrorNotification('❌ ' + (data.error || 'Erro desconhecido do servidor'));
    }
}

/**
 * Atualiza texto do indicador de carregamento
 */
function updateLoadingIndicator(message) {
    const loadingText = document.querySelector('.validation-loading-text');
    if (loadingText) {
        loadingText.textContent = message;
    }
    console.log('[Chat] Loading:', message);
}

/**
 * Sanitização ultra-robusta de texto
 */
function ultraSanitizeText(text) {
    if (!text || typeof text !== 'string') {
        return '';
    }
    
    // Converte para string se não for
    text = String(text);
    
    // Remove caracteres não-ASCII e de controle
    text = text.replace(/[^\x20-\x7E\u00A0-\u024F\u1E00-\u1EFF]/g, '');
    
    // Remove quebras de linha problemáticas
    text = text.replace(/[\r\n\t]/g, ' ');
    
    // Remove múltiplos espaços
    text = text.replace(/\s+/g, ' ');
    
    // Remove caracteres especiais que podem quebrar JSON
    text = text.replace(/['"\\]/g, '');
    
    // Trim
    text = text.trim();
    
    // Limita tamanho
    if (text.length > 500) {
        text = text.substring(0, 500) + '...';
    }
    
    return text;
}

/**
 * Coleta dados da conversa com sanitização ultra-robusta
 */
function collectConversationData() {
    console.log('[Chat] Iniciando coleta de dados ultra-robusta');
    
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer || !activeUserId) {
        console.error('[Chat] Container ou activeUserId não encontrado');
        return null;
    }
    
    const messages = messagesContainer.querySelectorAll('.chat-message:not(.system-message)');
    console.log('[Chat] Encontradas', messages.length, 'mensagens');
    
    const conversationData = {
        participants: {
            current_user: parseInt(currentUserId) || 0,
            other_user: parseInt(activeUserId) || 0,
            other_user_name: ultraSanitizeText(getActiveUserName()) || 'Usuario'
        },
        messages: [],
        total_messages: 0,
        saved_at: new Date().toISOString().substring(0, 19) + 'Z'
    };
    
    let validMessages = 0;
    
    messages.forEach((message, index) => {
        try {
            const isSent = message.classList.contains('sent');
            const contentElement = message.querySelector('.chat-message-content');
            const timeElement = message.querySelector('.chat-message-time');
            
            // Extrai conteúdo de forma mais segura
            let content = '';
            if (contentElement) {
                content = contentElement.textContent || contentElement.innerText || '';
                content = ultraSanitizeText(content);
            }
            
            // Extrai tempo de forma mais segura
            let timeText = 'Sem horario';
            if (timeElement) {
                timeText = timeElement.textContent || timeElement.innerText || '';
                timeText = ultraSanitizeText(timeText) || 'Sem horario';
            }
            
            // Só adiciona se tiver conteúdo válido
            if (content && content.length > 0) {
                const messageData = {
                    id: validMessages + 1,
                    sender_id: parseInt(isSent ? currentUserId : activeUserId),
                    sender_name: ultraSanitizeText(isSent ? 'Voce' : getActiveUserName()) || 'Usuario',
                    content: content,
                    time: timeText,
                    is_sent: isSent ? true : false
                };
                
                conversationData.messages.push(messageData);
                validMessages++;
                
                console.log('[Chat] Mensagem', validMessages, 'adicionada:', content.substring(0, 30));
            }
            
        } catch (error) {
            console.error('[Chat] Erro ao processar mensagem', index, ':', error);
        }
    });
    
    conversationData.total_messages = validMessages;
    
    console.log('[Chat] Total de mensagens válidas:', validMessages);
    return conversationData;
}

/**
 * Sanitiza texto removendo caracteres problemáticos
 */
function sanitizeText(text) {
    if (!text || typeof text !== 'string') {
        return '';
    }
    
    // Remove caracteres de controle e não-printáveis
    text = text.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/g, '');
    
    // Normaliza quebras de linha
    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    
    // Remove múltiplas quebras de linha consecutivas
    text = text.replace(/\n{3,}/g, '\n\n');
    
    // Trim e remove espaços extras
    text = text.trim().replace(/\s+/g, ' ');
    
    // Escapa caracteres que podem quebrar JSON
    text = text.replace(/\\/g, '\\\\');
    text = text.replace(/"/g, '\\"');
    text = text.replace(/\t/g, '\\t');
    text = text.replace(/\n/g, '\\n');
    
    return text;
}

/**
 * Limpa a conversa após salvar
 */
function clearConversationAfterSave() {
    if (!activeUserId) return;
    
    fetch(`${baseUrl}messages.php?action=clearConversation`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `user_id=${activeUserId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove contador de mensagens não lidas
            if (unreadMessages[activeUserId]) {
                unreadMessages[activeUserId] = 0;
            }
            updateTotalUnreadCounter();
            loadUsers(); // Recarrega a lista de usuários
        }
    })
    .catch(error => {
        console.error('Erro ao limpar conversa:', error);
    });
}

/**
 * Redimensiona o chat para modo de salvar conversa
 */
function resizeChatForSaveConversation() {
    if (chatContainer) {
        chatContainer.classList.add('save-conversation-mode');
    }
}

/**
 * Habilita a área de input para conversas normais
 */
function enableInputForNormalConversation() {
    const inputContainer = chatContainer.querySelector('.chat-input-container');
    const chatInput = document.getElementById('chat-input');
    const sendButton = document.getElementById('chat-send-button');
    
    if (inputContainer && chatInput && sendButton) {
        // Remove classe especial
        inputContainer.classList.remove('system-conversation');
        
        // Habilita os elementos
        chatInput.disabled = false;
        chatInput.placeholder = 'Enviar Msg';
        sendButton.disabled = false;
        sendButton.textContent = 'Enviar';
        
        // Remove ícone do sistema
        const systemIcon = inputContainer.querySelector('.system-icon');
        if (systemIcon) {
            inputContainer.removeChild(systemIcon);
        }
    }
}

/**
 * Carrega mensagens do sistema
 */
function loadSystemMessages() {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return;
    
    messagesContainer.innerHTML = '<div class="chat-loading">Carregando mensagens do sistema...</div>';
    
    loadPendingValidations();
    
    const timestamp = new Date().getTime();
    fetch(`${baseUrl}messages.php?action=getSystemConversation&t=${timestamp}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.messages && data.messages.length > 0) {
                updateMessagesWithReadButton(data.messages);
            } else {
                const hasValidations = messagesContainer.querySelector('.system-validation-message');
                if (!hasValidations) {
                    messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma notificação do sistema ainda.</div>';
                }
            }
        })
        .catch(error => {
            console.error('Erro ao carregar mensagens do sistema:', error);
            const hasValidations = messagesContainer.querySelector('.system-validation-message');
            if (!hasValidations) {
                messagesContainer.innerHTML = `<div class="chat-error">Erro ao carregar mensagens do sistema: ${error.message}</div>`;
            }
        });
}

/**
 * Atualiza mensagens com botão de marcar como lida
 */
function updateMessagesWithReadButton(messages) {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return;
    
    // Remove apenas as mensagens normais do sistema (não as validações)
    const existingMessages = messagesContainer.querySelectorAll('.system-message:not(.system-validation-message)');
    existingMessages.forEach(msg => {
        if (msg.parentNode) {
            msg.parentNode.removeChild(msg);
        }
    });
    
    if (!messages || messages.length === 0) {
        return;
    }
    
    messages.forEach(message => {
        const time = new Date(message.date).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const date = new Date(message.date).toLocaleDateString();
        
        const messageEl = document.createElement('div');
        messageEl.setAttribute('data-id', message.id);
        messageEl.className = 'chat-message system-message';
        
        const icon = systemMessageTypes[message.system_message_type] || '🤖';
        const systemClass = `system-${message.system_message_type}`;
        
        let actionButtons = '';
        
        // Se for uma solicitação de validação via chat
        if (message.system_message_type === 'validation_chat_request' && message.system_data) {
            const validationId = message.system_data.validation_id;
            actionButtons = `
                <div class="validation-actions">
                    <button class="validation-approve-btn" onclick="handleValidationResponse(${validationId}, 'approve')">
                        ✅ Aprovar
                    </button>
                    <button class="validation-reject-btn" onclick="handleValidationResponse(${validationId}, 'reject')">
                        ❌ Rejeitar
                    </button>
                </div>
            `;
        } else if (message.system_data && message.system_data.url) {
            actionButtons = `<button class="system-message-action" onclick="window.open('${message.system_data.url}', '_blank')">Ver Ticket</button>`;
        }
        
        messageEl.innerHTML = `
            <div class="system-message-header">
                <span class="system-message-icon">${icon}</span>
                <span class="system-message-title">Notificações</span>
                <button class="mark-as-read-btn" data-message-id="${message.id}" title="Marcar como lida">
                    ✓ Lida
                </button>
            </div>
            <div class="system-message-content ${systemClass}">${convertLinksToHTML(message.content)}</div>
            <div class="system-message-footer">
                <div class="chat-message-time">${time} - ${date}</div>
                ${actionButtons}
            </div>
        `;
        
        // Adiciona evento de clique ao botão de marcar como lida
        const markReadBtn = messageEl.querySelector('.mark-as-read-btn');
        if (markReadBtn) {
            markReadBtn.addEventListener('click', function() {
                const msgId = this.getAttribute('data-message-id');
                markSystemMessageAsRead(messageEl, msgId);
            });
        }
        
        messagesContainer.appendChild(messageEl);
    });
    
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Carrega validações pendentes para o usuário atual (versão funcional e simples)
 */
function loadPendingValidations() {
    if (isLoadingValidations) {
        return;
    }
    
    const now = Date.now();
    if (now - lastValidationsCheck < 10000) {
        return;
    }
    
    isLoadingValidations = true;
    lastValidationsCheck = now;
    
    const timestamp = new Date().getTime();
    fetch(`${baseUrl}validations.php?action=getPendingValidations&t=${timestamp}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[Chat] Resposta de validações recebida:', data);
            
            if (data.success && data.validations) {
                // Resto da lógica permanece igual...
                const currentValidationIds = new Set(data.validations.map(v => v.validation_id));
                const newValidations = data.validations.filter(v => !loadedValidationIds.has(v.validation_id));
                
                if (newValidations.length > 0 && activeUserId !== 0) {
                    unreadValidationsCount += newValidations.length;
                    console.log(`[Chat] ${newValidations.length} nova(s) validação(ões) detectada(s). Total não vistas: ${unreadValidationsCount}`);
                }
                
                loadedValidationIds = currentValidationIds;
                
                if (activeUserId === 0) {
                    if (data.validations.length > 0) {
                        console.log(`[Chat] Exibindo ${data.validations.length} validações no chat do sistema`);
                        displayPendingValidationsInSystemChat(data.validations);
                    } else {
                        console.log('[Chat] Nenhuma validação para exibir - limpando chat do sistema');
                        clearValidationsFromSystemChat();
                    }
                }
                
                if (data.validations.length === 0 && activeUserId === 0) {
                    unreadValidationsCount = 0;
                }
                
                updateSystemUnreadCount();
            } else {
                console.log('[Chat] Resposta de validações não contém dados válidos:', data);
            }
        })
        .catch(error => {
            console.error('Erro ao carregar validações pendentes:', error);
        })
        .finally(() => {
            isLoadingValidations = false;
        });
}

/**
 * Compara dois Sets para verificar se são iguais
 */
function setsAreEqual(set1, set2) {
    if (set1.size !== set2.size) {
        return false;
    }
    for (let item of set1) {
        if (!set2.has(item)) {
            return false;
        }
    }
    return true;
}

/**
 * Remove todas as validações do chat do sistema
 */
function clearValidationsFromSystemChat() {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return;
    
    const validationMessages = messagesContainer.querySelectorAll('.system-validation-message');
    validationMessages.forEach(msg => {
        if (msg.parentNode) {
            msg.parentNode.removeChild(msg);
        }
    });
    
    // Se não há mais nada, mostra mensagem padrão
    if (messagesContainer.children.length === 0) {
        messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma validação pendente.</div>';
    }
}

/**
 * Exibe validações pendentes no chat do sistema (versão otimizada com ordem correta)
 */
function displayPendingValidationsInSystemChat(validations) {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return;
    
    // Salva a posição atual do scroll
    const scrollPosition = messagesContainer.scrollTop;
    const isScrolledToBottom = (messagesContainer.scrollTop + messagesContainer.clientHeight) >= (messagesContainer.scrollHeight - 5);
    
    // Remove apenas as validações antigas (não todas as mensagens)
    const existingValidations = messagesContainer.querySelectorAll('.system-validation-message');
    existingValidations.forEach(msg => {
        if (msg.parentNode) {
            msg.parentNode.removeChild(msg);
        }
    });
    
    // Remove mensagem "nenhuma validação" se existir
    const noValidationsMsg = messagesContainer.querySelector('.chat-no-messages');
    if (noValidationsMsg) {
        noValidationsMsg.remove();
    }
    
    // Ordena validações por data de submissão (mais antigas primeiro, mais recentes por último)
    const sortedValidations = validations.sort((a, b) => {
        return new Date(a.submission_date) - new Date(b.submission_date);
    });
    
    // Adiciona as validações em ordem cronológica
    sortedValidations.forEach((validation, index) => {
        const messageEl = document.createElement('div');
        messageEl.className = 'chat-message system-validation-message';
        messageEl.setAttribute('data-validation-id', validation.validation_id);
        
        const priorityColor = getPriorityColor(validation.priority);
        const time = new Date(validation.submission_date).toLocaleString();
        
        messageEl.innerHTML = `
            <div class="validation-message-header">
                <span class="validation-message-icon">🔍</span>
                <span class="validation-message-title">Validação Pendente</span>
                <span class="validation-priority" style="background-color: ${priorityColor}">
                    ${validation.priority_name}
                </span>
            </div>
            <div class="validation-message-content">
                <div class="validation-ticket-info">
                    <strong>Ticket #${validation.ticket_id}:</strong> ${validation.ticket_title}
                </div>
                <div class="validation-requester">
                    <strong>Solicitado por:</strong> ${validation.requester_name}
                </div>
                <div class="validation-submission-comment">
                    <strong>Comentário:</strong><br>
                    ${validation.comment_submission || 'Sem comentário'}
                </div>
                <div class="validation-ticket-description">
                    <strong>Descrição do Ticket:</strong><br>
                    <div class="ticket-description-content"></div>
                </div>
            </div>
            <div class="validation-message-actions">
                <button class="validation-approve-btn" onclick="processValidationFromChat(${validation.validation_id}, 'approve')">
                    ✅ Aprovar
                </button>
                <button class="validation-reject-btn" onclick="processValidationFromChat(${validation.validation_id}, 'reject')">
                    ❌ Rejeitar
                </button>
                <button class="validation-view-ticket-btn" onclick="window.open('${validation.url}', '_blank')">
                    👁️ Ver Ticket
                </button>
            </div>
            <div class="validation-message-time">${time}</div>
        `;
        
        // Adiciona no final (ordem cronológica: mais antigas primeiro, mais recentes por último)
        messagesContainer.appendChild(messageEl);
        
        // IMPORTANTE: Define o HTML da descrição APÓS inserir no DOM
        const descriptionContainer = messageEl.querySelector('.ticket-description-content');
        if (descriptionContainer && validation.ticket_description) {
            descriptionContainer.innerHTML = validation.ticket_description;
        }
    });
    
    // Restaura a posição do scroll ou mantém no final se estava no final
    if (isScrolledToBottom) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    } else {
        messagesContainer.scrollTop = scrollPosition;
    }
    
    // Atualiza contador do sistema apenas se necessário
    updateSystemUnreadCount();
}

/**
 * Sanitiza HTML permitindo apenas tags seguras
 */
function sanitizeHTML(html) {
    // Cria um elemento temporário
    const temp = document.createElement('div');
    temp.innerHTML = html;
    
    // Lista de tags permitidas
    const allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'div', 'blockquote', 'pre', 'code'];
    
    // Remove scripts e outras tags perigosas
    const scripts = temp.querySelectorAll('script, style, object, embed, iframe');
    scripts.forEach(script => script.remove());
    
    // Remove atributos perigosos
    const allElements = temp.querySelectorAll('*');
    allElements.forEach(el => {
        // Remove atributos de eventos (onclick, onload, etc.)
        Array.from(el.attributes).forEach(attr => {
            if (attr.name.startsWith('on') || attr.name === 'javascript:') {
                el.removeAttribute(attr.name);
            }
        });
        
        // Mantém apenas tags permitidas
        if (!allowedTags.includes(el.tagName.toLowerCase())) {
            // Se a tag não é permitida, mantém apenas o conteúdo
            el.outerHTML = el.innerHTML;
        }
    });
    
    return temp.innerHTML;
}

/**
 * Sanitiza especificamente a descrição de tickets
 */
function sanitizeDescriptionHTML(html) {
    if (!html) return '';
    
    // Cria um elemento temporário para processar
    const temp = document.createElement('div');
    temp.innerHTML = html;
    
    // Remove scripts e elementos perigosos
    const dangerousElements = temp.querySelectorAll('script, style, object, embed, iframe, form, input, button');
    dangerousElements.forEach(el => el.remove());
    
    // Limpa atributos perigosos de todos os elementos
    const allElements = temp.querySelectorAll('*');
    allElements.forEach(el => {
        // Remove atributos de eventos
        Array.from(el.attributes).forEach(attr => {
            if (attr.name.startsWith('on') || attr.name.includes('javascript:')) {
                el.removeAttribute(attr.name);
            }
        });
        
        // Remove estilos inline excessivos, mas preserva alguns básicos
        if (el.hasAttribute('style')) {
            const style = el.getAttribute('style');
            // Mantém apenas estilos básicos seguros
            const safeStyles = style.match(/(color|font-weight|text-align|margin|padding):\s*[^;]*/g);
            if (safeStyles) {
                el.setAttribute('style', safeStyles.join('; '));
            } else {
                el.removeAttribute('style');
            }
        }
    });
    
    return temp.innerHTML;
}

/**
 * Marca uma mensagem do sistema como lida
 */
function markSystemMessageAsRead(messageElement, messageId) {
    const formData = new FormData();
    formData.append('message_id', messageId);
    
    fetch(`${baseUrl}messages.php?action=markSystemMessageAsRead`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove a mensagem da interface com animação
            messageElement.style.transition = 'opacity 0.3s, transform 0.3s';
            messageElement.style.opacity = '0';
            messageElement.style.transform = 'translateX(100%)';
            
            setTimeout(() => {
                if (messageElement.parentNode) {
                    messageElement.parentNode.removeChild(messageElement);
                }
                
                // Atualiza contadores
                updateSystemUnreadCount();
                updateTotalUnreadCounter();
                
                // Verifica se não há mais mensagens
                const messagesContainer = document.getElementById('chat-messages');
                if (messagesContainer && messagesContainer.children.length === 0) {
                    messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma notificação do sistema.</div>';
                }
            }, 300);
            
            console.log('[Chat] Mensagem marcada como lida:', messageId);
        } else {
            console.error('[Chat] Erro ao marcar mensagem como lida:', data.error);
            showErrorNotification('❌ Erro ao marcar como lida: ' + data.error);
        }
    })
    .catch(error => {
        console.error('[Chat] Erro na requisição:', error);
        showErrorNotification('❌ Erro de conexão ao marcar como lida');
    });
}

/**
 * Marca todas as mensagens do sistema como lidas
 */
function markAllSystemMessagesAsRead() {
    if (!confirm('Marcar todas as notificações do sistema como lidas?')) {
        return;
    }
    
    fetch(`${baseUrl}messages.php?action=markAllSystemMessagesAsRead`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove todas as mensagens da interface
            const messagesContainer = document.getElementById('chat-messages');
            if (messagesContainer) {
                const systemMessages = messagesContainer.querySelectorAll('.system-message, .system-validation-message');
                
                systemMessages.forEach((messageElement, index) => {
                    setTimeout(() => {
                        messageElement.style.transition = 'opacity 0.3s, transform 0.3s';
                        messageElement.style.opacity = '0';
                        messageElement.style.transform = 'translateX(100%)';
                        
                        setTimeout(() => {
                            if (messageElement.parentNode) {
                                messageElement.parentNode.removeChild(messageElement);
                            }
                        }, 300);
                    }, index * 100); // Animação escalonada
                });
                
                // Depois de todas as animações, atualiza a interface
                setTimeout(() => {
                    messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma notificação do sistema.</div>';
                    
                    // Atualiza contadores
                    updateSystemUnreadCount();
                    updateTotalUnreadCounter();
                }, (systemMessages.length * 100) + 500);
            }
            
            showSuccessNotification(`✅ ${data.marked_count} notificações marcadas como lidas`);
            console.log('[Chat] Todas as mensagens do sistema marcadas como lidas');
        } else {
            console.error('[Chat] Erro ao marcar todas como lidas:', data.error);
            showErrorNotification('❌ Erro ao marcar todas como lidas: ' + data.error);
        }
    })
    .catch(error => {
        console.error('[Chat] Erro na requisição:', error);
        showErrorNotification('❌ Erro de conexão');
    });
}

/**
 * Processa validação diretamente do chat (versão simplificada)
 */
function processValidationFromChat(validationId, action) {
    let comment = '';
    
    if (action === 'reject') {
        comment = prompt('Informe o motivo da rejeição (opcional):');
        if (comment === null) {
            return;
        }
    } else if (action === 'approve') {
        comment = prompt('Adicione um comentário à aprovação (opcional):');
        if (comment === null) {
            return;
        }
    }
    
    showLoadingIndicator('Processando validação...');
    
    const formData = new FormData();
    formData.append('validation_id', validationId);
    formData.append('action', action);
    formData.append('comment', comment || '');
    
    fetch(`${baseUrl}validations.php?action=processValidationFromChat`, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        hideLoadingIndicator();
        
        if (data.success) {
            const messageEl = document.querySelector(`[data-validation-id="${validationId}"]`);
            if (messageEl) {
                messageEl.style.transition = 'opacity 0.3s';
                messageEl.style.opacity = '0';
                setTimeout(() => {
                    if (messageEl.parentNode) {
                        messageEl.parentNode.removeChild(messageEl);
                    }
                }, 300);
            }
            
            loadedValidationIds.delete(parseInt(validationId));
            showSuccessNotification(`✅ ${data.message}`);
            
            // ===== ATUALIZA A PÁGINA APÓS 2 SEGUNDOS =====
            setTimeout(() => {
                console.log('[Chat] Recarregando página após validação...');
                window.location.reload();
            }, 2000);
            // ===== FIM DA MODIFICAÇÃO =====
            
        } else {
            showErrorNotification(`❌ ${data.error}`);
        }
    })
    .catch(error => {
        hideLoadingIndicator();
        console.error('Erro ao processar validação:', error);
        showErrorNotification('❌ Erro ao processar validação. Tente novamente.');
    });
}

/**
 * Limpa validações que foram processadas em outras interfaces
 */
function cleanupProcessedValidations() {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer || activeUserId !== 0) return;
    
    const validationMessages = messagesContainer.querySelectorAll('.system-validation-message');
    const currentValidationIds = Array.from(validationMessages).map(msg => 
        parseInt(msg.getAttribute('data-validation-id'))
    );
    
    // Verifica no servidor quais validações ainda estão realmente pendentes
    if (currentValidationIds.length > 0) {
        fetch(`${baseUrl}checkValidationStatus.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                validation_ids: currentValidationIds
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.processed_ids && data.processed_ids.length > 0) {
                console.log(`[Chat] Encontradas ${data.processed_ids.length} validações já processadas para remover`);
                
                // Remove validações já processadas da interface
                data.processed_ids.forEach(validationId => {
                    const messageEl = messagesContainer.querySelector(`[data-validation-id="${validationId}"]`);
                    if (messageEl) {
                        messageEl.style.transition = 'opacity 0.5s';
                        messageEl.style.opacity = '0';
                        setTimeout(() => {
                            if (messageEl.parentNode) {
                                messageEl.parentNode.removeChild(messageEl);
                            }
                        }, 500);
                    }
                    
                    // Remove do conjunto carregado
                    loadedValidationIds.delete(parseInt(validationId));
                });
            }
        })
        .catch(error => {
            console.error('Erro ao verificar status das validações:', error);
        });
    }
}

/**
 * Marca validações como vistas quando o usuário abre o chat do sistema
 */
function markValidationsAsSeen() {
    fetch(`${baseUrl}validations.php?action=markValidationsAsSeen`, {  // MUDANÇA AQUI
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: currentUserId,
            validation_ids: Array.from(loadedValidationIds)
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log('[Chat] Validações marcadas como vistas');
        } else {
            console.error('[Chat] Erro ao marcar validações como vistas:', data.error);
        }
    })
    .catch(error => {
        console.error('Erro ao marcar validações como vistas:', error);
    });
}

/**
 * Obtém cor da prioridade
 */
function getPriorityColor(priority) {
    switch (parseInt(priority)) {
        case 1: return '#4caf50'; // Muito baixa - Verde
        case 2: return '#8bc34a'; // Baixa - Verde claro
        case 3: return '#ffc107'; // Média - Amarelo
        case 4: return '#ff9800'; // Alta - Laranja
        case 5: return '#f44336'; // Muito alta - Vermelho
        case 6: return '#9c27b0'; // Urgente - Roxo
        default: return '#757575'; // Padrão - Cinza
    }
}

// Função global para ser chamada pelos botões
window.processValidationFromChat = function(validationId, action) {
    let comment = '';
    
    if (action === 'reject') {
        comment = prompt('Informe o motivo da rejeição (opcional):');
        if (comment === null) return;
    } else if (action === 'approve') {
        comment = prompt('Adicione um comentário à aprovação (opcional):');
        if (comment === null) return;
    }
    
    const formData = new FormData();
    formData.append('validation_id', validationId);
    formData.append('action', action);
    formData.append('comment', comment || '');
    
    fetch(baseUrl + 'validations.php?action=processValidationFromChat', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Removido o alert de sucesso
            window.location.reload();
        } else {
            // Mantido apenas o alert de erro (opcional)
            alert('❌ ' + data.error);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        // Mantido apenas o alert de erro crítico (opcional)
        alert('❌ Erro ao processar validação');
    });
};

/**
 * Atualiza contador de mensagens do sistema não lidas (incluindo validações)
 */
function updateSystemUnreadCount() {
    fetch(`${baseUrl}messages.php?action=getNewMessages&user_id=${currentUserId}&t=${Date.now()}`)
        .then(response => response.json())
        .then(data => {
            let systemMessagesCount = 0;
            
            if (data.success && data.messages) {
                // Filtra apenas mensagens do sistema não lidas
                const systemMessages = data.messages.filter(msg => msg.is_system_message);
                systemMessagesCount = systemMessages.length;
            }
            
            const totalCount = systemMessagesCount + unreadValidationsCount;
            
            const systemUnreadElement = document.getElementById('system-unread-count');
            if (systemUnreadElement) {
                systemUnreadElement.textContent = totalCount;
                systemUnreadElement.style.display = totalCount > 0 ? 'flex' : 'none';
            }
            
            updateTotalUnreadCounter();
        })
        .catch(error => {
            console.error('Erro ao atualizar contador do sistema:', error);
        });
}
    
    /**
     * Filtra a lista de usuários
     */
    function filterUsers(searchText) {
        const users = document.querySelectorAll('.chat-user');
        
        searchText = searchText.toLowerCase();
        
        users.forEach(user => {
            const userName = user.getAttribute('data-user-name').toLowerCase();
            const groupName = user.querySelector('.chat-user-group')?.textContent.toLowerCase() || '';
            
            if (userName.includes(searchText) || groupName.includes(searchText)) {
                user.style.display = '';
            } else {
                user.style.display = 'none';
            }
        });
    }
    
    /**
 * Abre uma conversa com um usuário
 */
function openConversation(userId, userName, userGroup) {
    activeUserId = userId;
    
    // Reset contador de mensagens não lidas para este usuário
    if (unreadMessages[userId]) {
        unreadMessages[userId] = 0;
    }
    
    const chatUserName = chatContainer.querySelector('.chat-conversation-header .chat-user-name');
    const chatUserGroup = chatContainer.querySelector('.chat-conversation-header .chat-user-group-header');
    
    if (chatUserName) {
        chatUserName.textContent = userName;
        chatUserName.setAttribute('data-user-id', userId);
    }
    
    if (chatUserGroup) {
        chatUserGroup.textContent = userGroup || '';
    }
    
    const usersList = chatContainer.querySelector('.chat-users-list');
    const conversation = chatContainer.querySelector('.chat-conversation');
    
    if (usersList && conversation) {
        usersList.style.display = 'none';
        conversation.style.display = 'flex';
    }
    
    // RESTAURA O TAMANHO NORMAL DO CHAT
    resizeChatForNormalConversation();
    
    // Habilita área de input para conversas normais
    enableInputForNormalConversation();
    
    // Verifica se o usuário pode validar tickets
    checkUserValidationStatus(userId);
    
    const chatInput = document.getElementById('chat-input');
    if (chatInput) {
        chatInput.value = '';
        chatInput.style.height = 'auto';
        setTimeout(() => chatInput.focus(), 100);
    }
    
    // Remove o badge de notificação imediatamente
    const userElement = document.querySelector(`.chat-user[data-user-id="${userId}"]`);
    if (userElement) {
        const badgeElement = userElement.querySelector('.unread-badge');
        if (badgeElement) {
            badgeElement.remove();
        }
    }
    
    loadChatHistory(userId);
    markMessagesAsRead(userId);
    updateTotalUnreadCounter();
}
    
    /**
 * Verifica se o usuário pode validar tickets
 */
function checkUserValidationStatus(userId) {
    fetch(`${baseUrl}validations.php?action=getUserValidationStatus&user_id=${userId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                activeUserCanValidate = data.can_validate;
                updateValidationStatusDisplay();
            } else {
                console.error('Erro ao verificar status de validação:', data.error);
                activeUserCanValidate = false;
                updateValidationStatusDisplay();
            }
        })
        .catch(error => {
            console.error('Erro ao verificar status de validação:', error);
            activeUserCanValidate = false;
            updateValidationStatusDisplay();
        });
}
    
    /**
 * Atualiza a exibição do status de validação
 */
function updateValidationStatusDisplay() {
    const validationStatus = chatContainer.querySelector('.chat-validation-status');
    
    if (validationStatus) {
        // Se for conversa do sistema (activeUserId = 0), oculta completamente
        if (activeUserId === 0 || activeUserId === '0') {
            validationStatus.style.display = 'none';
            return;
        }
        
        // Sempre mostra a área de validação para usuários normais
        validationStatus.style.display = 'block';
        
        // Remove classes anteriores
        validationStatus.classList.remove('disabled');
        
        const validationIcon = validationStatus.querySelector('.validation-icon');
        const validationText = validationStatus.querySelector('.validation-text');
        const validationButton = validationStatus.querySelector('.validation-request-btn');
        
        if (activeUserCanValidate) {
            // Usuário PODE validar tickets
            if (validationIcon) {
                validationIcon.textContent = '🔍';
            }
            if (validationText) {
                validationText.textContent = 'Validador';
            }
            if (validationButton) {
                validationButton.style.display = 'block';
                validationButton.textContent = 'Solicitar Validação';
            }
        } else {
            // Usuário NÃO PODE validar tickets
            validationStatus.classList.add('disabled');
            
            if (validationIcon) {
                validationIcon.textContent = '🚫';
            }
            if (validationText) {
                validationText.textContent = 'Não Validador';
            }
            if (validationButton) {
                validationButton.style.display = 'none';
            }
        }
    }
}
    
    /**
     * Abre modal para solicitar validação
     */
    function openValidationRequestModal() {
        if (!activeUserId || !activeUserCanValidate) {
            alert('Este usuário não pode validar tickets.');
            return;
        }
        
        // Cria modal
        const modal = document.createElement('div');
        modal.className = 'validation-request-modal';
        modal.innerHTML = `
            <div class="validation-request-content">
                <div class="validation-request-header">
                    <h3>🔍 Solicitar Validação de Ticket</h3>
                    <button class="close-validation-modal">&times;</button>
                </div>
                <div class="validation-request-body">
                    <form id="validation-request-form">
                        <div class="form-group">
                            <label for="ticket-number">Número do Ticket:</label>
                            <input type="number" id="ticket-number" name="ticket_id" required min="1" placeholder="Ex: 12345">
                        </div>
                        <div class="form-group">
                            <label for="validation-comment">Comentário da Solicitação:</label>
                            <textarea id="validation-comment" name="comment" required rows="4" 
                                placeholder="Descreva o motivo da solicitação de validação..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="validation-request-footer">
                    <button type="button" class="btn-send-validation">Enviar Solicitação</button>
                    <button type="button" class="btn-cancel-validation">Cancelar</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Eventos do modal
        const closeButton = modal.querySelector('.close-validation-modal');
        const cancelButton = modal.querySelector('.btn-cancel-validation');
        const sendButton = modal.querySelector('.btn-send-validation');
        
        function closeModal() {
            if (document.body.contains(modal)) {
                document.body.removeChild(modal);
            }
        }
        
        closeButton.addEventListener('click', closeModal);
        cancelButton.addEventListener('click', closeModal);
        
        sendButton.addEventListener('click', function() {
            const form = document.getElementById('validation-request-form');
            const formData = new FormData(form);
            
            const ticketId = formData.get('ticket_id');
            const comment = formData.get('comment');
            
            // Validações
            if (!ticketId || ticketId <= 0) {
                alert('Por favor, informe um número de ticket válido.');
                return;
            }
            
            if (!comment || comment.trim().length < 10) {
                alert('Por favor, informe um comentário com pelo menos 10 caracteres.');
                return;
            }
            
            // Envia solicitação
            sendValidationRequest(ticketId, comment, closeModal);
        });
        
        // Foca no campo de ticket
        setTimeout(() => {
            const ticketInput = document.getElementById('ticket-number');
            if (ticketInput) {
                ticketInput.focus();
            }
        }, 100);
    }
    
   /**
 * Envia solicitação de validação
 */
function sendValidationRequest(ticketId, comment, closeModalCallback) {
    showLoadingIndicator('Enviando solicitação de validação...');
    
    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('validator_id', activeUserId);
    formData.append('comment', comment);
    
    fetch(`${baseUrl}validations.php?action=requestValidation`, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        hideLoadingIndicator();
        
        if (data.success) {
            closeModalCallback();
            showSuccessNotification('✅ Solicitação de validação enviada com sucesso!');
            
            if (activeUserId) {
                loadChatHistory(activeUserId);
            }
            
            updateGLPITicketPage(ticketId);
            
        } else {
            showErrorNotification('❌ Erro ao enviar solicitação: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        hideLoadingIndicator();
        console.error('Erro ao enviar solicitação de validação:', error);
        showErrorNotification('❌ Erro ao enviar solicitação. Tente novamente.');
    });
}

/**
 * Mostra indicador de carregamento
 */
function showLoadingIndicator(message) {
    // Remove indicador anterior se existir
    hideLoadingIndicator();
    
    const loader = document.createElement('div');
    loader.id = 'validation-loading-indicator';
    loader.className = 'validation-loading-overlay';
    loader.innerHTML = `
        <div class="validation-loading-content">
            <div class="validation-loading-spinner"></div>
            <div class="validation-loading-text">${message}</div>
        </div>
    `;
    
    document.body.appendChild(loader);
}

/**
 * Esconde indicador de carregamento
 */
function hideLoadingIndicator() {
    const loader = document.getElementById('validation-loading-indicator');
    if (loader) {
        document.body.removeChild(loader);
    }
}

/**
 * Mostra notificação de sucesso
 */
function showSuccessNotification(message) {
    showNotification({
        title: 'Sucesso!',
        message: message,
        type: 'success',
        duration: 5000
    });
}

/**
 * Mostra notificação de erro
 */
function showErrorNotification(message) {
    showNotification({
        title: 'Erro!',
        message: message,
        type: 'error',
        duration: 5000
    });
}

/**
 * Atualiza a página do GLPI onde o ticket está sendo visualizado
 */
function updateGLPITicketPage(ticketId) {
    // Verifica se estamos em uma página de ticket do GLPI
    const currentUrl = window.location.href;
    
    if (currentUrl.includes('ticket.form.php') && currentUrl.includes(`id=${ticketId}`)) {
        // Estamos na página do ticket, atualiza a página
        setTimeout(() => {
            window.location.reload();
        }, 2000); // Aguarda 2 segundos para dar tempo da validação ser processada
    } else {
        // Não estamos na página do ticket, verifica se há outras abas abertas
        try {
            // Tenta comunicar com outras abas/janelas do GLPI
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage({
                    type: 'refresh_ticket',
                    ticket_id: ticketId
                }, window.location.origin);
            }
        } catch (e) {
            // Ignorar erros de cross-origin
        }
    }
}

    
    /**
     * Processa resposta de validação (aprovar/rejeitar)
     */
    function processValidationResponse(validationId, status, comment = '') {
        const formData = new FormData();
        formData.append('validation_id', validationId);
        formData.append('status', status);
        formData.append('comment', comment);
        
        fetch(`${baseUrl}processValidation.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const statusText = status === 'approve' ? 'aprovada' : 'rejeitada';
                showNotification({
                    title: '✅ Validação processada',
                    message: `Validação ${statusText} com sucesso!`,
                    from_name: 'Sistema',
                    is_system_message: false
                });
                
                // Recarrega o histórico de mensagens
                if (activeUserId) {
                    loadChatHistory(activeUserId);
                }
            } else {
                alert('Erro ao processar validação: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao processar validação:', error);
            alert('Erro ao processar validação. Tente novamente.');
        });
    }
    
    /**
     * Abre a lista de tickets do GLPI com filtros
     */
    function openTicketsList(userId, status) {
        // Determina a URL base do GLPI
        let glpiUrl = window.location.origin;
        const pathParts = window.location.pathname.split('/');
        const pluginIndex = pathParts.indexOf('plugins');
        
        if (pluginIndex > 0) {
            glpiUrl += '/' + pathParts.slice(1, pluginIndex).join('/');
        }
        
        // Constrói a URL para a lista de tickets com filtros corretos
        let ticketsUrl = glpiUrl + '/front/ticket.php?is_deleted=0&reset=reset';
        
        // Adiciona filtro de status (critério 0)
        ticketsUrl += `&criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=${status}&criteria[0][link]=AND`;
        
        // Adiciona filtro de usuário atribuído (critério 1)
        ticketsUrl += `&criteria[1][field]=5&criteria[1][searchtype]=equals&criteria[1][value]=${userId}&criteria[1][link]=AND`;
        
        // Adiciona configuração para não mostrar tickets deletados (critério 2)
        ticketsUrl += `&criteria[2][field]=23&criteria[2][searchtype]=equals&criteria[2][value]=0&criteria[2][link]=AND`;
        
        // Força a busca
        ticketsUrl += '&search=Buscar';
        
        console.log('Abrindo URL de tickets:', ticketsUrl);
        
        // Abre em nova aba
        window.open(ticketsUrl, '_blank');
    }
    
    /**
     * Marca mensagens como lidas no servidor
     */
    function markMessagesAsRead(userId) {
    fetch(`${baseUrl}messages.php?action=markMessagesAsRead&from_id=${userId}`, {
        method: 'POST'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success && data.marked > 0) {
            if (unreadMessages[userId]) {
                unreadMessages[userId] = 0;
            }
            updateTotalUnreadCounter();
        }
    })
    .catch(error => {
        console.error('Erro ao marcar mensagens como lidas:', error);
    });
}
    
    /**
     * Limpa a conversa com um usuário
     */
    function clearConversation(userId) {
    fetch(`${baseUrl}messages.php?action=clearConversation`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `user_id=${userId}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const messagesContainer = document.getElementById('chat-messages');
            if (messagesContainer) {
                messagesContainer.innerHTML = '<div class="chat-no-messages">Conversa limpa. Inicie uma nova conversa!</div>';
            }
            
            if (unreadMessages[userId]) {
                unreadMessages[userId] = 0;
            }
            updateTotalUnreadCounter();
            
            loadUsers();
        } else {
            alert('Erro ao limpar conversa: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao limpar conversa:', error);
        alert('Erro ao limpar conversa. Tente novamente.');
    });
}
    
    /**
 * Mostra a lista de usuários
 */
function showUsersList() {
    const usersList = chatContainer.querySelector('.chat-users-list');
    const conversation = chatContainer.querySelector('.chat-conversation');
    
    if (usersList && conversation) {
        usersList.style.display = 'block';
        conversation.style.display = 'none';
    }
    
    // RESTAURA O TAMANHO NORMAL DO CHAT
    resizeChatForNormalConversation();
}

/**
 * Redimensiona o chat para conversas do sistema (dobro do tamanho)
 */
function resizeChatForSystemConversation() {
    if (chatContainer) {
        chatContainer.classList.add('system-conversation-enlarged');
    }
}

/**
 * Restaura o tamanho normal do chat (função melhorada)
 */
function resizeChatForNormalConversation() {
    if (chatContainer) {
        // Remove todas as classes de redimensionamento
        chatContainer.classList.remove('create-ticket-mode');
        chatContainer.classList.remove('save-conversation-mode');
        chatContainer.classList.remove('system-conversation-enlarged');
        
        console.log('[Chat] Chat redimensionado para tamanho normal');
    }
}
    
    /**
     * Carrega o histórico de mensagens
     */
    function loadChatHistory(userId) {
    const messagesContainer = document.getElementById('chat-messages');
    if (!messagesContainer) return;
    
    const existingMessages = messagesContainer.querySelectorAll('.chat-message');
    if (existingMessages.length === 0) {
        messagesContainer.innerHTML = '<div class="chat-loading">Carregando mensagens...</div>';
    }
    
    const timestamp = new Date().getTime();
    fetch(`${baseUrl}messages.php?action=getChatHistory&user_id=${userId}&t=${timestamp}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.messages && data.messages.length > 0) {
                updateMessages(data.messages);
            } else {
                if (existingMessages.length === 0) {
                    messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma mensagem anterior. Comece a conversar!</div>';
                }
            }
        })
        .catch(error => {
            console.error('Erro ao carregar histórico de mensagens:', error);
            if (existingMessages.length === 0) {
                messagesContainer.innerHTML = `<div class="chat-error">Erro ao carregar mensagens: ${error.message}</div>`;
            }
        });
}
    
    /**
     * Atualiza as mensagens sem piscar (incluindo mensagens do sistema e validações)
     */
    function updateMessages(messages) {
        const messagesContainer = document.getElementById('chat-messages');
        if (!messagesContainer) return;
        
        if (!messages || messages.length === 0) {
            if (!messagesContainer.querySelector('.chat-message')) {
                messagesContainer.innerHTML = '<div class="chat-no-messages">Nenhuma mensagem anterior. Comece a conversar!</div>';
            }
            return;
        }
        
        const currentMessages = {};
        messagesContainer.querySelectorAll('.chat-message').forEach((el, index) => {
            const messageId = el.getAttribute('data-id') || `local_${index}`;
            currentMessages[messageId] = el;
        });
        
        let hasNewMessages = false;
        messages.forEach(message => {
            const messageId = message.id.toString();
            if (!currentMessages[messageId]) {
                hasNewMessages = true;
            }
        });
        
        if (!hasNewMessages && Object.keys(currentMessages).length > 0) {
            return;
        }
        
        messagesContainer.innerHTML = '';
        
        messages.forEach(message => {
            const time = new Date(message.date).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const date = new Date(message.date).toLocaleDateString();
            
            const messageEl = document.createElement('div');
            messageEl.setAttribute('data-id', message.id);
            
            // Se for mensagem do sistema
            if (message.is_system_message) {
                messageEl.className = 'chat-message system-message';
                
                const icon = systemMessageTypes[message.system_message_type] || '🤖';
                const systemClass = `system-${message.system_message_type}`;
                
                let actionButtons = '';
                
                // Se for uma solicitação de validação via chat
                if (message.system_message_type === 'validation_chat_request' && message.system_data) {
                    const validationId = message.system_data.validation_id;
                    actionButtons = `
                        <div class="validation-actions">
                            <button class="validation-approve-btn" onclick="handleValidationResponse(${validationId}, 'approve')">
                                ✅ Aprovar
                            </button>
                            <button class="validation-reject-btn" onclick="handleValidationResponse(${validationId}, 'reject')">
                                ❌ Rejeitar
                            </button>
                        </div>
                    `;
                } else if (message.system_data && message.system_data.url) {
                    actionButtons = `<button class="system-message-action" onclick="window.open('${message.system_data.url}', '_blank')">Ver Ticket</button>`;
                }
                
                messageEl.innerHTML = `
                    <div class="system-message-header">
                        <span class="system-message-icon">${icon}</span>
                        <span class="system-message-title">Notificações</span>
                    </div>
                    <div class="system-message-content ${systemClass}">${convertLinksToHTML(message.content)}</div>
                    <div class="system-message-footer">
                        <div class="chat-message-time">${time} - ${date}</div>
                        ${actionButtons}
                    </div>
                `;
            } else {
                // Mensagem normal
                const messageClass = message.is_sender ? 'sent' : 'received';
                messageEl.className = `chat-message ${messageClass}`;
                messageEl.innerHTML = `
                    <div class="chat-message-content">${convertLinksToHTML(message.content)}</div>
                    <div class="chat-message-time">${time} - ${date}</div>
                `;
            }
            
            messagesContainer.appendChild(messageEl);
        });
        
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    /**
     * Atualiza os contadores de mensagens não lidas
     */
    function updateUnreadCounters() {
        // Atualiza badges na lista de usuários
        document.querySelectorAll('.chat-user').forEach(userElement => {
            const userId = userElement.getAttribute('data-user-id');
            const userUnreadCount = unreadMessages[userId] || 0;
            
            let badgeElement = userElement.querySelector('.unread-badge');
            
            if (userUnreadCount > 0) {
                if (badgeElement) {
                    badgeElement.textContent = userUnreadCount;
                } else {
                    badgeElement = document.createElement('span');
                    badgeElement.className = 'unread-badge';
                    badgeElement.textContent = userUnreadCount;
                    
                    const userInfo = userElement.querySelector('.chat-user-info');
                    if (userInfo) {
                        userInfo.appendChild(badgeElement);
                    }
                }
            } else if (badgeElement) {
                badgeElement.remove();
            }
        });
        
        updateTotalUnreadCounter();
    }
    
    /**
 * Atualiza o contador total de mensagens não lidas (incluindo validações)
 */
function updateTotalUnreadCounter() {
    let totalUnread = 0;
    
    for (const userId in unreadMessages) {
        totalUnread += unreadMessages[userId] || 0;
    }
    
    if (activeUserId !== 0) {
        totalUnread += unreadValidationsCount;
    }
    
    fetch(`${baseUrl}messages.php?action=getNewMessages&user_id=${currentUserId}&t=${Date.now()}`)
        .then(response => response.json())
        .then(data => {
            let systemMessagesCount = 0;
            
            if (data.success && data.messages) {
                const systemMessages = data.messages.filter(msg => msg.is_system_message);
                systemMessagesCount = systemMessages.length;
            }
            
            if (activeUserId !== 0) {
                totalUnread += systemMessagesCount;
            }
            
            const totalBadge = chatContainer.querySelector('.total-unread-badge');
            if (totalBadge) {
                if (totalUnread > 0) {
                    totalBadge.textContent = totalUnread;
                    totalBadge.style.display = 'flex';
                } else {
                    totalBadge.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Erro ao calcular contador total:', error);
            
            const totalBadge = chatContainer.querySelector('.total-unread-badge');
            if (totalBadge) {
                if (totalUnread > 0) {
                    totalBadge.textContent = totalUnread;
                    totalBadge.style.display = 'flex';
                } else {
                    totalBadge.style.display = 'none';
                }
            }
        });
}
    
    /**
     * Envia uma mensagem
     */
    function sendMessage() {
    const input = document.getElementById('chat-input');
    if (!input) return;
    
    const message = input.value.trim();
    if (!message) return;
    
    input.value = '';
    input.style.height = 'auto';
    input.focus();
    
    const chatUserName = chatContainer.querySelector('.chat-conversation-header .chat-user-name');
    if (!chatUserName) return;
    
    const userId = chatUserName.getAttribute('data-user-id');
    if (!userId) {
        console.error('ID do usuário não encontrado');
        return;
    }
    
    const messagesContainer = document.getElementById('chat-messages');
    if (messagesContainer) {
        const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const date = new Date().toLocaleDateString();
        
        const tempId = `temp_${Date.now()}`;
        
        const noMessagesEl = messagesContainer.querySelector('.chat-no-messages');
        if (noMessagesEl) {
            messagesContainer.removeChild(noMessagesEl);
        }
        
        const messageEl = document.createElement('div');
        messageEl.className = 'chat-message sent';
        messageEl.setAttribute('data-id', tempId);
        messageEl.innerHTML = `
            <div class="chat-message-content">${convertLinksToHTML(message)}</div>
            <div class="chat-message-time">${time} - ${date}</div>
        `;
        
        messagesContainer.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    const formData = new FormData();
    formData.append('to_id', userId);
    formData.append('message', message);
    
    fetch(`${baseUrl}messages.php?action=sendMessage`, {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erro HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (!data.success) {
            console.error('Erro ao enviar mensagem:', data.error);
            const errorMsg = document.createElement('div');
            errorMsg.className = 'chat-error';
            errorMsg.textContent = 'Erro ao enviar mensagem. Tente novamente.';
            messagesContainer.appendChild(errorMsg);
            
            setTimeout(() => {
                if (messagesContainer.contains(errorMsg)) {
                    messagesContainer.removeChild(errorMsg);
                }
            }, 3000);
        }
    })
    .catch(error => {
        console.error('Erro ao enviar mensagem:', error);
        if (messagesContainer) {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'chat-error';
            errorMsg.textContent = 'Erro ao enviar mensagem. Tente novamente.';
            messagesContainer.appendChild(errorMsg);
            
            setTimeout(() => {
                if (messagesContainer.contains(errorMsg)) {
                    messagesContainer.removeChild(errorMsg);
                }
            }, 3000);
        }
    });
}
    
    /**
 * Mostra uma notificação
 */
function showNotification(notification) {
    // Cria ou localiza o contêiner de notificações
    let notificationContainer = document.getElementById('notificacaochat-container');
    if (!notificationContainer) {
        notificationContainer = document.createElement('div');
        notificationContainer.id = 'notificacaochat-container';
        document.body.appendChild(notificationContainer);
    }
    
    const notificationElement = document.createElement('div');
    notificationElement.className = 'notificacaochat-notification';
    
    // Adiciona classe de tipo se especificada
    if (notification.type) {
        notificationElement.classList.add(notification.type);
    }
    
    notificationElement.innerHTML = `
        <div class="notificacaochat-close">&times;</div>
        <div class="notificacaochat-notification-title">${notification.title || 'Nova mensagem'}</div>
        <div class="notificacaochat-notification-content">${notification.message}</div>
    `;
    
    // Adiciona evento de clique para fechar
    const closeButton = notificationElement.querySelector('.notificacaochat-close');
    closeButton.addEventListener('click', function(e) {
        e.stopPropagation();
        if (notificationContainer.contains(notificationElement)) {
            notificationContainer.removeChild(notificationElement);
        }
    });
    
    // Adiciona evento de clique para a notificação
    notificationElement.addEventListener('click', function() {
        if (notificationContainer.contains(notificationElement)) {
            notificationContainer.removeChild(notificationElement);
        }
        
        // Abre conversa se for mensagem de usuário
        if (!notification.is_system_message && notification.from_id && notification.from_id != 0) {
            const userElement = document.querySelector(`.chat-user[data-user-id="${notification.from_id}"]`);
            if (userElement) {
                const userName = userElement.getAttribute('data-user-name');
                const userGroup = userElement.getAttribute('data-user-group');
                openConversation(notification.from_id, userName, userGroup);
                
                const chatBody = chatContainer.querySelector('.chat-body');
                if (chatBody && chatBody.style.display === 'none') {
                    toggleChat();
                }
            }
        }
    });
    
    notificationContainer.appendChild(notificationElement);
    
    // Remove automaticamente após o tempo especificado ou 10 segundos
    const duration = notification.duration || 10000;
    setTimeout(() => {
        if (notificationContainer.contains(notificationElement)) {
            notificationContainer.removeChild(notificationElement);
        }
    }, duration);
}
    
    /**
     * Adiciona estilos CSS para o chat (incluindo estilos de validação)
     */
    function addChatStyles() {
        // Verifica se os estilos já foram adicionados
        if (document.getElementById('chat-styles')) return;
        
        // Cria o elemento de estilo
        const styleElement = document.createElement('style');
        styleElement.id = 'chat-styles';
        
        // Define os estilos CSS
        styleElement.textContent = `
            #usuariosonline-container {
                position: fixed;
                bottom: 0;
                right: 20px;
                width: 230px;
                z-index: 9999;
                font-family: Arial, sans-serif;
                font-size: 13px;
            }
            
            .chat-wrapper {
                background-color: #fff;
                border: 1px solid #ddd;
                border-radius: 8px 8px 0 0;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }
            
            .chat-header {
                background-color: #2f3f64;
                color: white;
                padding: 8px 12px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                min-height: 20px;
            }
            
            .chat-header h3 {
                margin: 0;
                font-size: 13px;
                font-weight: bold;
                display: flex;
                align-items: center;
            }
            
            .chat-header-buttons {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .chat-toggle {
                background: none;
                border: none;
                color: white;
                font-size: 14px;
                cursor: pointer;
                padding: 2px 4px;
            }
            
            .chat-body {
                max-height: 400px;
                overflow: hidden;
            }
            
            .chat-search {
                padding: 8px;
                background-color: #f5f5f5;
                border-bottom: 1px solid #eee;
            }
            
            .chat-search input {
                width: 100%;
                padding: 6px;
                border: 1px solid #ddd;
                border-radius: 3px;
                box-sizing: border-box;
                font-size: 12px;
            }
            
            .chat-users {
                max-height: 280px;
                overflow-y: auto;
            }
            
            .chat-user {
                padding: 8px 12px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
                transition: background-color 0.2s;
            }
            
            .chat-user:hover {
                background-color: #f5f5f5;
            }
            
            .chat-user-info {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .chat-user-left {
                flex: 1;
                min-width: 0;
            }
            
            .chat-user-name-container {
                display: flex;
                align-items: center;
            }
            
            .chat-user-status {
                width: 6px;
                height: 6px;
                background-color: #4CAF50;
                border-radius: 50%;
                margin-right: 8px;
                flex-shrink: 0;
            }
            
            .chat-user-name {
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 140px;
                font-size: 12px;
            }
            
            .chat-user-group {
                font-size: 10px;
                color: #777;
                margin-top: 1px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .chat-user-tickets {
                display: flex;
                gap: 4px;
                flex-direction: column;
                align-items: center;
                flex-shrink: 0;
                margin-left: 8px;
            }
            
            .ticket-labels {
                display: flex;
                gap: 4px;
                margin-bottom: 1px;
            }
            
            .ticket-label {
                font-size: 7px;
                text-align: center;
                width: 18px;
                color: #666;
                font-weight: bold;
            }
            
            .ticket-counters {
                display: flex;
                gap: 4px;
            }
            
            .ticket-counter {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 18px;
                height: 18px;
                border-radius: 50%;
                font-size: 9px;
                font-weight: bold;
                color: white;
                cursor: pointer;
                transition: transform 0.2s;
            }
            
            .ticket-counter:hover {
                transform: scale(1.1);
            }
            
            .ticket-pending {
                background-color: #FF9800;
            }
            
            .ticket-processing {
                background-color: #2fb344;
            }
            
            .chat-loading, .chat-error, .chat-no-users, .chat-no-messages {
                padding: 15px;
                text-align: center;
                color: #666;
                font-size: 12px;
            }
            
            .chat-error {
                color: #f44336;
            }
            
            .chat-conversation {
                height: 370px;
                display: flex;
                flex-direction: column;
            }
            
            .chat-conversation-header {
                padding: 6px 10px;
                background-color: #f5f5f5;
                border-bottom: 1px solid #eee;
                display: flex;
                align-items: center;
                justify-content: space-between;
                min-height: 24px;
            }
            
            .chat-conversation-header-left {
                display: flex;
                align-items: center;
                flex: 1;
                min-width: 0;
            }
            
            .chat-back-button {
                background-color: #2f3f64;
                color: white;
                border: none;
                border-radius: 3px;
                font-size: 12px;
                padding: 3px 6px;
                cursor: pointer;
                margin-right: 8px;
                font-weight: bold;
            }
            
            .chat-clear-button {
                background-color: #f44336;
                color: white;
                border: none;
                border-radius: 3px;
                font-size: 11px;
                padding: 3px 6px;
                cursor: pointer;
                font-weight: bold;
                margin-left: 8px;
            }
            
            .chat-clear-button:hover {
                background-color: #d32f2f;
            }
            
            .chat-user-info-header {
                display: flex;
                flex-direction: column;
                min-width: 0;
                flex: 1;
            }
            
            .chat-user-name {
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: 12px;
            }
            
            .chat-user-group-header {
                font-size: 10px;
                color: #777;
                margin-top: 1px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            /* Estilos para validação de tickets */
            .chat-validation-status {
                background-color: #e8f5e8;
                border: 1px solid #4caf50;
                border-radius: 4px;
                padding: 8px 12px;
                margin: 0 6px 6px 6px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 12px;
            }
            
            .validation-status-content {
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
            }
            
            .validation-icon {
                font-size: 14px;
            }
            
            .validation-text {
                flex: 1;
                color: #2e7d32;
                font-weight: 500;
            }
            
            .validation-request-btn {
                background-color: #4caf50;
                color: white;
                border: none;
                border-radius: 3px;
                padding: 4px 8px;
                font-size: 10px;
                cursor: pointer;
                font-weight: bold;
            }
            
            .validation-request-btn:hover {
                background-color: #45a049;
            }
            
            .chat-messages {
                flex: 1;
                padding: 8px;
                overflow-y: auto;
                background-color: #f9f9f9;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            .chat-message {
                margin-bottom: 1px;
                max-width: 75%;
                padding: 6px 10px;
                border-radius: 12px;
                word-wrap: break-word;
                position: relative;
                font-size: 12px;
            }
            
            .chat-message.sent {
                background-color: #dcf8c6;
                margin-left: auto;
                border-bottom-right-radius: 3px;
            }

            .chat-message.received {
                background-color: #fff;
                margin-right: auto;
                border-bottom-left-radius: 3px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            /* Mensagens do Sistema */
            .chat-message.system-message {
                background-color: #e3f2fd;
                border-left: 4px solid #2196f3;
                max-width: 90%;
                margin: 8px auto;
                padding: 12px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            
            .system-message-header {
                display: flex;
                align-items: center;
                margin-bottom: 8px;
                font-weight: bold;
                color: #1976d2;
            }
            
            .system-message-icon {
                font-size: 16px;
                margin-right: 8px;
            }
            
            .system-message-title {
                font-size: 12px;
            }
            
            .system-message-content {
                color: #333;
                line-height: 1.4;
                margin-bottom: 8px;
            }
            
            .system-message-content.system-new_ticket {
                border-left: 3px solid #4caf50;
                padding-left: 8px;
            }
            
            .system-message-content.system-assigned_ticket {
                border-left: 3px solid #ff9800;
                padding-left: 8px;
            }
            
            .system-message-content.system-ticket_solution {
                border-left: 3px solid #2196f3;
                padding-left: 8px;
            }
            
            .system-message-content.system-unassigned_ticket {
                border-left: 3px solid #f44336;
                padding-left: 8px;
            }
            
            .system-message-content.system-validation_chat_request {
                border-left: 4px solid #ff9800;
                padding-left: 8px;
                background-color: #fff3e0;
            }
            
            .system-message-content.system-validation_chat_response {
                border-left: 4px solid #2196f3;
                padding-left: 8px;
                background-color: #e3f2fd;
            }
            
            .system-message-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
            }
            
            .system-message-action {
                background-color: #2196f3;
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 10px;
                cursor: pointer;
                text-decoration: none;
            }
            
            .system-message-action:hover {
                background-color: #1976d2;
            }
            
            /* Botões de validação nas mensagens */
            .validation-actions {
                display: flex;
                gap: 8px;
                margin-top: 8px;
            }
            
            .validation-approve-btn,
            .validation-reject-btn {
                padding: 6px 12px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
                font-weight: bold;
                transition: background-color 0.2s;
            }
            
            .validation-approve-btn {
                background-color: #4caf50;
                color: white;
            }
            
            .validation-approve-btn:hover {
                background-color: #45a049;
            }
            
            .validation-reject-btn {
                background-color: #f44336;
                color: white;
            }
            
            .validation-reject-btn:hover {
                background-color: #d32f2f;
            }
            
            .chat-message-time {
                font-size: 9px;
                color: #999;
                margin-top: 2px;
                text-align: right;
            }
            
            .chat-input-container {
                padding: 6px;
                border-top: 1px solid #eee;
                display: flex;
                background-color: #fff;
                gap: 6px;
                align-items: flex-end;
            }
            
            #chat-input {
                flex: 1;
                padding: 6px;
                border: 1px solid #ddd;
                border-radius: 3px;
                resize: none;
                font-family: inherit;
                font-size: 12px;
                min-height: 20px;
                max-height: 60px;
                line-height: 1.3;
            }
            
            #chat-send-button {
                padding: 6px 12px;
                background-color: #2f3f64;
                color: white;
                border: none;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
                font-weight: bold;
                height: 32px;
            }
            
            #chat-send-button:hover {
                background-color: #1e2b4a;
            }
            
            #chat-retry-button {
                padding: 6px 12px;
                background-color: #2f3f64;
                color: white;
                border: none;
                border-radius: 3px;
                cursor: pointer;
                margin-top: 8px;
                font-size: 12px;
            }
            
            .unread-badge, .total-unread-badge {
                background-color: #f44336;
                color: white;
                border-radius: 50%;
                min-width: 16px;
                height: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                font-weight: bold;
                margin-left: 6px;
            }
            
            /* Modal de solicitação de validação */
            .validation-request-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 10001;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .validation-request-content {
                background-color: white;
                border-radius: 8px;
                width: 450px;
                max-width: 90%;
                max-height: 80%;
                overflow-y: auto;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            }
            
            .validation-request-header {
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background-color: #2f3f64;
                color: white;
                border-radius: 8px 8px 0 0;
            }
            
            .validation-request-header h3 {
                margin: 0;
                font-size: 16px;
            }
            
            .close-validation-modal {
                background: none;
                border: none;
                color: white;
                font-size: 20px;
                cursor: pointer;
                padding: 0;
                width: 25px;
                height: 25px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .close-validation-modal:hover {
                background-color: rgba(255, 255, 255, 0.1);
                border-radius: 50%;
            }
            
            .validation-request-body {
                padding: 20px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #333;
            }
            
            .form-group input,
            .form-group textarea {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
                box-sizing: border-box;
            }
            
            .form-group input:focus,
            .form-group textarea:focus {
                outline: none;
                border-color: #2f3f64;
            }
            
            .validation-request-footer {
                padding: 15px 20px;
                border-top: 1px solid #eee;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
            
            .btn-send-validation {
                background-color: #4caf50;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
            }
            
            .btn-send-validation:hover {
                background-color: #45a049;
            }
            
            .btn-cancel-validation {
                background-color: #f44336;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            }
            
            .btn-cancel-validation:hover {
                background-color: #d32f2f;
            }
            
            /* Notificações */
            #notificacaochat-container {
                position: fixed;
                bottom: 20px;
                right: 320px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 8px;
                max-width: 300px;
            }
            
            .notificacaochat-notification {
                background-color: #2f3f64;
                color: white;
                padding: 12px;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
                animation: notificacaochatSlideIn 0.3s ease-out;
                cursor: pointer;
                transition: all 0.2s;
                position: relative;
            }
            
            .notificacaochat-notification:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            }
            
            .notificacaochat-notification-title {
                font-weight: bold;
                font-size: 12px;
                margin-bottom: 4px;
            }
            
            .notificacaochat-notification-content {
                font-size: 11px;
                max-height: 80px;
                overflow-y: auto;
                word-wrap: break-word;
            }
            
            .notificacaochat-close {
                position: absolute;
                top: 4px;
                right: 4px;
                font-size: 14px;
                cursor: pointer;
                color: #ccc;
                width: 16px;
                height: 16px;
                text-align: center;
                line-height: 16px;
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
            
            /* Scrollbar customizada */
            .chat-users::-webkit-scrollbar,
            .chat-messages::-webkit-scrollbar {
                width: 6px;
            }
            
            .chat-users::-webkit-scrollbar-track,
            .chat-messages::-webkit-scrollbar-track {
                background: #f1f1f1;
            }
            
            .chat-users::-webkit-scrollbar-thumb,
            .chat-messages::-webkit-scrollbar-thumb {
                background: #888;
                border-radius: 3px;
            }
            
            .chat-users::-webkit-scrollbar-thumb:hover,
            .chat-messages::-webkit-scrollbar-thumb:hover {
                background: #555;
            }
        `;
        
        // Adiciona o elemento de estilo ao cabeçalho do documento
        document.head.appendChild(styleElement);
    }
    
    // Função global para lidar com respostas de validação
    window.handleValidationResponse = function(validationId, status) {
        let comment = '';
        
        if (status === 'reject') {
            comment = prompt('Informe o motivo da rejeição (opcional):');
            if (comment === null) {
                return; // Usuário cancelou
            }
        }
        
        processValidationResponse(validationId, status, comment || '');
    };
    
    // Inicializa quando o documento estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 500);
    }
// Torna as funções globais para serem acessíveis via onclick
    window.markSystemMessageAsRead = markSystemMessageAsRead;
    window.markAllSystemMessagesAsRead = markAllSystemMessagesAsRead;
    
    // Inicializa quando o documento estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 500);
    }
})();