document.addEventListener('DOMContentLoaded', function () {
    const API_URL = ''; // Altere para a URL da sua API
    const token = localStorage.getItem('jwt_token');

    // Elementos da UI
    const globalLoader = document.getElementById('global-loader');
    const messageArea = document.getElementById('message-area');
    const navGerenciarUsuarios = document.getElementById('nav-gerenciar-usuarios');

    // Views
    const viewSelecaoTurma = document.getElementById('view-selecao-turma');
    const viewListaConteudo = document.getElementById('view-lista-conteudo');
    const viewDetalhesProgresso = document.getElementById('view-detalhes-progresso');

    // Seletores e Containers
    const selectTurma = document.getElementById('select-turma');
    const conteudosContainer = document.getElementById('conteudos-container');
    const tituloListaConteudo = document.getElementById('titulo-lista-conteudo');
    const tituloDetalhes = document.getElementById('titulo-detalhes');
    const tabelaHistorico = document.getElementById('tabela-historico');

    // Botões
    const btnAddConteudo = document.getElementById('btn-add-conteudo');
    const btnVoltarLista = document.getElementById('btn-voltar-lista');
    const btnLancarProgresso = document.getElementById('btn-lancar-progresso');

    // Modais e Formulários
    const modalAddConteudo = document.getElementById('modal-add-conteudo');
    const formAddConteudo = document.getElementById('form-add-conteudo');
    const btnCloseModalAddConteudo = document.getElementById('close-modal-add-conteudo');
    const btnCancelAddConteudo = document.getElementById('btn-cancelar-add-conteudo');
    const selectAddDisciplina = document.getElementById('add-disciplina');

    const modalAddProgresso = document.getElementById('modal-add-progresso');
    const formAddProgresso = document.getElementById('form-add-progresso');
    const btnCloseModalAddProgresso = document.getElementById('close-modal-add-progresso');
    const btnCancelAddProgresso = document.getElementById('btn-cancelar-add-progresso');

    // Gráfico
    const canvasGrafico = document.getElementById('grafico-progresso');
    let chartInstance = null; // Armazena a instância do gráfico para destruí-la depois

    // Estado da aplicação
    let currentUserRole = null;
    let currentTurmaId = null;
    let currentConteudoId = null;

    // --- FUNÇÕES DE INICIALIZAÇÃO E AUTENTICAÇÃO ---

    function checkAuth() {
        if (!token) {
            window.location.href = 'login.html';
            return;
        }
        try {
            const payload = JSON.parse(atob(token.split('.')[1]));
            currentUserRole = payload.data.role;

            if (currentUserRole !== 'admin') {
                // NOVO: Esconde TODOS os links de navegação do admin
                document.querySelectorAll('.admin-only-nav').forEach(el => {
                    el.style.display = 'none';
                });

                btnAddConteudo.style.display = 'none'; // Apenas admins podem adicionar conteúdo
            }
            if (currentUserRole !== 'professor' && currentUserRole !== 'admin') {
                btnLancarProgresso.style.display = 'none';
            }

        } catch (e) {
            logout();
        }
    }

    function logout() {
        localStorage.removeItem('jwt_token');
        window.location.href = 'login.html';
    }

    // --- FUNÇÕES AUXILIARES ---

    const showLoader = (show = true) => globalLoader.style.display = show ? 'flex' : 'none';
    const showMessage = (message, type = 'success') => {
        messageArea.textContent = message;
        messageArea.className = `message ${type}`;
        messageArea.classList.remove('hidden');
        setTimeout(() => messageArea.classList.add('hidden'), 5000);
    };
    const formatDate = (dateString) => dateString ? new Date(dateString + 'T03:00:00').toLocaleDateString('pt-BR') : 'N/A';

    // --- NAVEGAÇÃO ENTRE VIEWS ---

    function showView(viewToShow) {
        [viewSelecaoTurma, viewListaConteudo, viewDetalhesProgresso].forEach(v => v.classList.add('hidden'));
        if (viewToShow) {
            viewToShow.classList.remove('hidden');
        }
    }

    // --- LÓGICA DA API ---

    async function fetchData(endpoint, options = {}) {
        showLoader();
        try {
            const response = await fetch(`${API_URL}${endpoint}`, {
                ...options,
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, ...options.headers },
            });
            if (response.status === 401) { logout(); return; }
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Erro na requisição.');
            return result;
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            showLoader(false);
        }
    }

    // --- LÓGICA DE NEGÓCIO ---

   async function loadTurmas() {
        let endpoint = '/api/turmas'; // Endpoint padrão para admins
        if (currentUserRole === 'professor') {
            endpoint = '/api/professor/dashboard'; // Endpoint para professores
        }

        const result = await fetchData(endpoint);
        
        let turmasList = [];
        if (result && result.data) {
            if (currentUserRole === 'professor') {
                turmasList = result.data.turmas; // A API do professor aninha as turmas
            } else {
                turmasList = result.data; // A API de admin é direta
            }
        }

        if (turmasList && turmasList.length > 0) {
            selectTurma.innerHTML = '<option value="">-- Selecione uma turma --</option>';
            turmasList.forEach(turma => {
                selectTurma.innerHTML += `<option value="${turma.id_turma}">${turma.nome_turma}</option>`;
            });
        } else {
            // Se for professor, esconde a seleção e avisa
            if(currentUserRole === 'professor') {
                viewSelecaoTurma.innerHTML = '<p class="text-center text-gray-500 py-4">Você ainda não foi alocado(a) a nenhuma turma. Fale com a administração.</p>';
            } else {
                selectTurma.innerHTML = '<option value="">Nenhuma turma cadastrada.</option>';
            }
        }
    }

    async function loadDisciplinas() {
        const result = await fetchData('/api/disciplinas');
        if (result && result.data) {
            selectAddDisciplina.innerHTML = '<option value="">-- Selecione uma disciplina --</option>';
            result.data.forEach(d => {
                selectAddDisciplina.innerHTML += `<option value="${d.id_disciplina}">${d.nome_disciplina}</option>`;
            });
        }
    }

    async function handleTurmaSelection(turmaNomeFromUrl = null) {
        let turmaNome;

        if (turmaNomeFromUrl) {
            // Veio da URL, currentTurmaId já foi setado no init()
            turmaNome = decodeURIComponent(turmaNomeFromUrl);
        } else {
            // Veio do select dropdown
            currentTurmaId = selectTurma.value;
            if (!currentTurmaId) {
                showView(viewSelecaoTurma);
                return;
            }
            turmaNome = selectTurma.options[selectTurma.selectedIndex].text;
        }

        tituloListaConteudo.textContent = `Conteúdo Programático para: ${turmaNome}`;

        const result = await fetchData(`/api/turmas/${currentTurmaId}/conteudo`);
        conteudosContainer.innerHTML = '';
        if (result && result.data) {
            if (result.data.length === 0) {
                conteudosContainer.innerHTML = '<p class="text-center text-gray-500 py-4">Nenhum conteúdo programático cadastrado para esta turma.</p>';
            } else {
                result.data.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'bg-white p-4 rounded-lg shadow border flex justify-between items-center';

                    card.innerHTML = `
                <div>
                    <div class="flex items-center gap-3">
                        <span class="bg-indigo-100 text-indigo-800 text-xs font-bold px-2.5 py-0.5 rounded-full">${item.bimestre}º Bimestre</span>
                        <h3 class="font-bold text-lg text-indigo-700">${item.titulo}</h3>
                    </div>
                    <p class="text-sm text-gray-600 mt-1">${item.descricao || ''}</p>
                    <p class="text-xs text-gray-500 mt-2">
                        Período: ${formatDate(item.data_inicio_prevista)} a ${formatDate(item.data_fim_prevista)} | 
                        Meta: Páginas ${item.meta_pagina_inicio} a ${item.meta_pagina_fim}
                    </p>
                </div>
                <button class="btn btn-primary btn-sm btn-ver-progresso" data-id="${item.id_conteudo}">Ver Progresso</button>
            `;
                    conteudosContainer.appendChild(card);
                });
            }
        }
        document.querySelectorAll('.btn-ver-progresso').forEach(btn => {
            btn.addEventListener('click', (e) => loadDetalhesEGrafico(e.target.dataset.id));
        });
        showView(viewListaConteudo);
    }

    async function loadDetalhesEGrafico(conteudoId) {
        currentConteudoId = conteudoId;
        const result = await fetchData(`/api/conteudo/${currentConteudoId}/grafico`);
        if (!result || !result.data) return;

        const { meta, progresso, historico } = result.data;
        tituloDetalhes.textContent = `Progresso: ${meta.titulo}`;

        // Renderiza o gráfico
        renderChart(progresso, meta);

        // Preenche o histórico
        tabelaHistorico.innerHTML = '';
        if (historico && historico.length > 0) {
            historico.forEach(item => {
                const row = tabelaHistorico.insertRow();
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(item.data_aula)}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${item.paginas_concluidas_inicio} - ${item.paginas_concluidas_fim}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${item.nome_professor}</td>
                    <td class="px-6 py-4">${item.observacoes || '-'}</td>
                `;
            });
        } else {
            tabelaHistorico.innerHTML = '<tr><td colspan="4" class="text-center text-gray-500 py-4">Nenhum progresso lançado ainda.</td></tr>';
        }

        showView(viewDetalhesProgresso);
    }

    function renderChart(progressoData, meta) {
        if (chartInstance) {
            chartInstance.destroy(); // Destrói o gráfico anterior para evitar sobreposição
        }

        const labels = progressoData.map(p => formatDate(p.data));
        const dataEsperado = progressoData.map(p => p.progresso_esperado);
        const dataReal = progressoData.map(p => p.progresso_real);

        chartInstance = new Chart(canvasGrafico, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Progresso Esperado (Meta)',
                        data: dataEsperado,
                        borderColor: 'rgba(156, 163, 175, 1)',
                        backgroundColor: 'rgba(156, 163, 175, 0.2)',
                        borderDash: [5, 5],
                        tension: 0.1,
                        fill: false
                    },
                    {
                        label: 'Progresso Real',
                        data: dataReal,
                        borderColor: 'rgba(79, 70, 229, 1)',
                        backgroundColor: 'rgba(79, 70, 229, 0.2)',
                        tension: 0.1,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: { display: true, text: `Evolução de Páginas (Meta: ${meta.meta_pagina_inicio} a ${meta.meta_pagina_fim})` },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        title: { display: true, text: 'Número da Página' }
                    },
                    x: {
                        title: { display: true, text: 'Data' }
                    }
                }
            }
        });
    }

    // --- MODAL E FORM HANDLERS ---

    async function handleCreateConteudo(e) {
        e.preventDefault();
        const data = {
            id_disciplina: document.getElementById('add-disciplina').value,
            bimestre: document.getElementById('add-bimestre').value, // <-- ADICIONE ESTA LINHA
            titulo: document.getElementById('add-titulo').value,
            descricao: document.getElementById('add-descricao').value,
            data_inicio_prevista: document.getElementById('add-data-inicio').value,
            data_fim_prevista: document.getElementById('add-data-fim').value,
            meta_pagina_inicio: document.getElementById('add-pag-inicio').value,
            meta_pagina_fim: document.getElementById('add-pag-fim').value,
        };
        const result = await fetchData(`/api/turmas/${currentTurmaId}/conteudo`, {
            method: 'POST',
            body: JSON.stringify(data)
        });
        if (result) {
            showMessage('Conteúdo criado com sucesso!');
            closeModal(modalAddConteudo);
            handleTurmaSelection(); // Recarrega a lista
        }
    }

    async function handleAddProgresso(e) {
        e.preventDefault();
        const data = {
            data_aula: document.getElementById('prog-data-aula').value,
            paginas_concluidas_inicio: document.getElementById('prog-pag-inicio').value,
            paginas_concluidas_fim: document.getElementById('prog-pag-fim').value,
            observacoes: document.getElementById('prog-observacoes').value,
        };
        const result = await fetchData(`/api/conteudo/${currentConteudoId}/progresso`, {
            method: 'POST',
            body: JSON.stringify(data)
        });
        if (result) {
            showMessage('Progresso salvo com sucesso!');
            closeModal(modalAddProgresso);
            loadDetalhesEGrafico(currentConteudoId); // Recarrega o gráfico e o histórico
        }
    }

    const openModal = (modal) => {
        if (modal === modalAddProgresso) {
            formAddProgresso.reset();
            document.getElementById('prog-data-aula').value = new Date().toISOString().split('T')[0];
        }
        modal.classList.remove('hidden');
    }
    const closeModal = (modal) => modal.classList.add('hidden');

    // --- INICIALIZAÇÃO E EVENT LISTENERS ---
    function init() {
        checkAuth();
        document.querySelector('button[onclick="logout()"]').addEventListener('click', logout);

        selectTurma.addEventListener('change', () => handleTurmaSelection(null)); // Chama sem parâmetro

        btnVoltarLista.addEventListener('click', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('idTurma')) {
                // Se veio de um link direto, volta para o dashboard
                window.location.href = 'professor_dashboard.html';
            } else {
                // Se estava navegando na pág, volta para a seleção
                showView(viewSelecaoTurma);
            }
        });

        // Listeners do Modal de Adicionar Conteúdo
        btnAddConteudo.addEventListener('click', () => openModal(modalAddConteudo));
        btnCloseModalAddConteudo.addEventListener('click', () => closeModal(modalAddConteudo));
        btnCancelAddConteudo.addEventListener('click', () => closeModal(modalAddConteudo));
        formAddConteudo.addEventListener('submit', handleCreateConteudo);

        // Listeners do Modal de Lançar Progresso
        btnLancarProgresso.addEventListener('click', () => openModal(modalAddProgresso));
        btnCloseModalAddProgresso.addEventListener('click', () => closeModal(modalAddProgresso));
        btnCancelAddProgresso.addEventListener('click', () => closeModal(modalAddProgresso));
        formAddProgresso.addEventListener('submit', handleAddProgresso);

        // --- LÓGICA DE INICIALIZAÇÃO MODIFICADA ---
        const urlParams = new URLSearchParams(window.location.search);
        const turmaIdFromUrl = urlParams.get('idTurma');
        const turmaNomeFromUrl = urlParams.get('nomeTurma');

        if (turmaIdFromUrl && turmaNomeFromUrl) {
            // Veio de um link direto (ex: dashboard do professor)
            currentTurmaId = turmaIdFromUrl;

            // Chama a função de carregar dados, passando o nome
            handleTurmaSelection(turmaNomeFromUrl);

            // Esconde o seletor de turma
            viewSelecaoTurma.style.display = 'none';

        } else {
            // Caminho normal: usuário seleciona a turma na própria página
            loadTurmas();
            showView(viewSelecaoTurma);
        }

        if (currentUserRole === 'admin') {
            loadDisciplinas();
        } // Carrega as disciplinas para o modal (sempre necessário)
    }

    init();
});