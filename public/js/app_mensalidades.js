document.addEventListener('DOMContentLoaded', () => {
    // URLs
    const API_URL_MENSALIDADES = '/api/mensalidades';
    const API_URL_ALUNOS = '/api/alunos';
    const API_URL_TURMAS = '/api/turmas';
    const LOGIN_URL = 'login.html';

    // Elementos das Views
    const viewTurmas = document.getElementById('view-turmas');
    const viewAlunosTurma = document.getElementById('view-alunos-turma');
    const viewDetalhes = document.getElementById('view-detalhes');

    // Containers de conteúdo
    const turmasContainer = document.getElementById('turmas-container');
    const alunosContainer = document.getElementById('alunos-container');
    const tabelaDetalhes = document.getElementById('tabela-detalhes-mensalidades');

    // Títulos e Botões
    const filterMonthSelect = document.getElementById('filter-month');
    const alunosTurmaTitulo = document.getElementById('alunos-turma-titulo');
    const detalhesTitulo = document.getElementById('detalhes-titulo');
    const btnVoltarParaTurmas = document.getElementById('btn-voltar-para-turmas');
    const btnVoltar = document.getElementById('btn-voltar');

    // Elementos do Formulário de Adição
    const formAddMensalidade = document.getElementById('form-add-mensalidade');
    const addAlunoSearchInput = document.getElementById('add-aluno-search');
    const addAlunoIdInput = document.getElementById('add-aluno-id');
    const addAlunoResultsContainer = document.getElementById('add-aluno-results');

    // Elementos do Modal de Pagamento
    const modalPagamento = document.getElementById('modal-pagamento');
    const formPagamento = document.getElementById('form-pagamento');
    const closeModalPagamento = document.getElementById('close-modal-pagamento');
    const btnCancelarPagamento = document.getElementById('btn-cancelar-pagamento');
    const inputIdMensalidade = document.getElementById('pagamento-id-mensalidade');
    const inputValorPago = document.getElementById('pagamento-valor');
    const inputDataPagamento = document.getElementById('pagamento-data');

    // Outros Elementos
    const messageArea = document.getElementById('message-area');

    // Estado da aplicação para controlar a navegação
    let state = {
        currentView: 'turmas', // 'turmas', 'alunos', 'mensalidades'
        selectedMonth: null,
        selectedTurmaId: null,
        selectedTurmaNome: null,
        selectedAlunoId: null,
        selectedAlunoNome: null
    };

    // Funções Utilitárias
    const debounce = (func, delay) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => func.apply(this, a), delay); } };
    const getAuthData = () => ({ token: localStorage.getItem('jwt_token'), role: localStorage.getItem('user_role') });
    window.logout = () => { localStorage.clear(); window.location.href = LOGIN_URL; };
    const displayMessage = (message, type) => { messageArea.textContent = message; messageArea.className = `message ${type}`; messageArea.classList.remove('hidden'); setTimeout(() => messageArea.classList.add('hidden'), 5000); };
    const handleAuthError = (response) => { if (response.status === 401 || response.status === 403) logout(); };
    const formatCurrency = (v) => { const n = parseFloat(v); return isNaN(n) ? "R$ 0,00" : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    const formatDate = (d) => { if (!d) return 'N/A'; const [y, m, day] = d.split(' ')[0].split('-'); return `${day}/${m}/${y}`; };
    const getStatusBadge = (s, vencimento) => {
        const status = s.toLowerCase();
        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);
        const dataVenc = new Date(vencimento + 'T00:00:00-03:00'); // Considera o fuso horário

        if (status === 'open' && dataVenc < hoje) {
            return { text: 'Atrasado', color: 'bg-red-100 text-red-800' };
        }
        switch (status) {
            case 'approved': return { text: 'Pago', color: 'bg-green-100 text-green-800' };
            case 'open': return { text: 'Aberto', color: 'bg-blue-100 text-blue-800' };
            default: return { text: 'Pendente', color: 'bg-yellow-100 text-yellow-800' };
        }
    };
    window.showLoader = () => {
        const loader = document.getElementById('global-loader');
        if (loader) loader.style.display = 'flex';
    };
    window.hideLoader = () => {
        const loader = document.getElementById('global-loader');
        if (loader) loader.style.display = 'none';
    };


    // --- FUNÇÕES DE RENDERIZAÇÃO E NAVEGAÇÃO ---

    const renderView = () => {
        console.log("A função renderView() foi chamada com a view:", state.currentView); // <-- ADICIONE ESTA LINHA
        viewTurmas.classList.add('hidden');
        viewAlunosTurma.classList.add('hidden');
        viewDetalhes.classList.add('hidden');

        if (state.currentView === 'turmas') {
            viewTurmas.classList.remove('hidden');
            carregarResumoTurmas();
        } else if (state.currentView === 'alunos') {
            viewAlunosTurma.classList.remove('hidden');
            alunosTurmaTitulo.textContent = `Alunos da Turma: ${state.selectedTurmaNome}`;
            carregarAlunosDaTurma();
        } else if (state.currentView === 'mensalidades') {
            viewDetalhes.classList.remove('hidden');
            carregarDetalhesMensalidades();
        }
    };

    const navigateTo = (view, newState = {}) => {
        Object.assign(state, newState, { currentView: view });
        renderView();
    };

    // --- CARREGAMENTO DE DADOS (API) ---

    const carregarResumoTurmas = async () => {
        console.log("entrou");
        showLoader();
        turmasContainer.innerHTML = '<p class="text-gray-500 col-span-full">Carregando resumo das turmas...</p>';
        try {
            const response = await fetch(`${API_URL_MENSALIDADES}/summary?mes=${state.selectedMonth}`, {
                headers: { 'Authorization': `Bearer ${getAuthData().token}` }
            });
            handleAuthError(response);
            const result = await response.json();

            turmasContainer.innerHTML = '';
            if (result.success && result.data.length > 0) {
                result.data.forEach(turma => {
                    const card = document.createElement('div');
                    card.className = 'bg-gray-50 border border-gray-200 rounded-lg p-6 shadow-sm hover:shadow-lg hover:border-indigo-500 transition-all cursor-pointer';
                    card.dataset.turmaId = turma.id_turma;
                    card.dataset.turmaNome = turma.nome_turma;
                    card.innerHTML = `
                        <h3 class="text-xl font-bold text-gray-800 mb-4">${turma.nome_turma}</h3>
                        <div class="flex justify-around text-center">
                            <div>
                                <p class="text-2xl font-semibold text-blue-600">${turma.a_receber || 0}</p>
                                <p class="text-xs text-gray-500 uppercase">A Receber</p>
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-red-600">${turma.em_atraso || 0}</p>
                                <p class="text-xs text-gray-500 uppercase">Em Atraso</p>
                            </div>
                        </div>
                    `;
                    turmasContainer.appendChild(card);
                });
            } else {
                turmasContainer.innerHTML = '<p class="text-gray-500 col-span-full">Nenhuma turma com mensalidades encontradas para este período.</p>';
            }
        } catch (error) {
            console.error("Erro ao carregar resumo:", error);
            displayMessage('Falha ao carregar o resumo das turmas.', 'error');
        } finally {
            hideLoader();
        }
    };

    const carregarAlunosDaTurma = async () => {
        showLoader();
        alunosContainer.innerHTML = '<p class="p-4 text-gray-500">Carregando alunos...</p>';
        try {
            const response = await fetch(`${API_URL_TURMAS}/${state.selectedTurmaId}/alunos`, {
                headers: { 'Authorization': `Bearer ${getAuthData().token}` }
            });
            handleAuthError(response);
            const result = await response.json();

            alunosContainer.innerHTML = '';
            if (result.success && result.data.length > 0) {
                result.data.forEach(aluno => {
                    const alunoEl = document.createElement('div');
                    alunoEl.className = 'p-4 hover:bg-gray-50 cursor-pointer flex justify-between items-center';
                    alunoEl.dataset.alunoId = aluno.id_aluno;
                    alunoEl.dataset.alunoNome = aluno.nome_aluno;
                    alunoEl.innerHTML = `
                        <div>
                            <p class="font-semibold text-gray-800">${aluno.nome_aluno}</p>
                            <p class="text-sm text-gray-500">RA: ${aluno.ra}</p>
                        </div>
                        <span class="text-indigo-600 font-semibold">Ver Mensalidades &rarr;</span>
                    `;
                    alunosContainer.appendChild(alunoEl);
                });
            } else {
                alunosContainer.innerHTML = '<p class="p-4 text-gray-500">Nenhum aluno encontrado nesta turma.</p>';
            }
        } catch (error) {
            displayMessage('Falha ao buscar alunos da turma.', 'error');
        } finally {
            hideLoader();
        }
    };

    const carregarDetalhesMensalidades = async () => {
        showLoader();
        tabelaDetalhes.innerHTML = `<tr><td colspan="7" class="text-center py-4">Carregando...</td></tr>`;

        let url;
        const thAluno = document.getElementById('th-aluno');
        const isAlunoView = state.selectedMonth === 'todos';

        if (isAlunoView) {
            detalhesTitulo.textContent = `Mensalidades de: ${state.selectedAlunoNome}`;
            url = `${API_URL_MENSALIDADES}?aluno_id=${state.selectedAlunoId}`;
            thAluno.style.display = 'none';
        } else {
            detalhesTitulo.textContent = `Mensalidades de ${state.selectedTurmaNome} em ${filterMonthSelect.options[filterMonthSelect.selectedIndex].text}`;
            url = `${API_URL_MENSALIDADES}?turma_id=${state.selectedTurmaId}&mes=${state.selectedMonth}`;
            thAluno.style.display = '';
        }

        try {
            const response = await fetch(url, { headers: { 'Authorization': `Bearer ${getAuthData().token}` } });
            handleAuthError(response);
            const result = await response.json();

            tabelaDetalhes.innerHTML = '';
            if (result.success && result.data.length > 0) {
                result.data.forEach(m => {
                    const statusInfo = getStatusBadge(m.status, m.data_vencimento);
                    const valorTotalDevido = m.valor_total_devido || m.valor_mensalidade;
                    const encargos = (parseFloat(m.multa_aplicada) || 0) + (parseFloat(m.juros_aplicados) || 0);

                    const alunoCell = isAlunoView ? '' : `<td class="px-6 py-4 font-medium">${m.nome_aluno}</td>`;
                    const row = `
                        <tr class="hover:bg-gray-50">
                            ${alunoCell}
                            <td class="px-6 py-4">${formatDate(m.data_vencimento)}</td>
                            <td class="px-6 py-4">${formatCurrency(m.valor_mensalidade)}</td>
                            <td class="px-6 py-4 text-red-600">${formatCurrency(encargos)}</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 text-xs font-semibold rounded-full ${statusInfo.color}">${statusInfo.text}</span></td>
                            <td class="px-6 py-4 font-bold">${formatCurrency(valorTotalDevido)}</td>
                            <td class="px-6 py-4 text-center space-x-2">
                                ${m.status !== 'approved' ? `<button data-id="${m.id_mensalidade}" data-valor="${valorTotalDevido}" class="btn-pagar font-semibold text-green-600 hover:text-green-800">Pagar</button>` : ''}
                                <button data-id="${m.id_mensalidade}" class="btn-excluir font-semibold text-red-600 hover:text-red-800">Excluir</button>
                            </td>
                        </tr>
                    `;
                    tabelaDetalhes.insertAdjacentHTML('beforeend', row);
                });
            } else {
                const colspan = isAlunoView ? 6 : 7;
                tabelaDetalhes.innerHTML = `<tr><td colspan="${colspan}" class="text-center py-4">Nenhuma mensalidade encontrada.</td></tr>`;
            }
        } catch (error) {
            displayMessage('Falha ao carregar as mensalidades.', 'error');
        } finally {
            hideLoader();
        }
    };

    // --- LÓGICA DE EVENTOS (Ações do Usuário) ---

    const excluirMensalidade = async (id) => {
        if (!confirm('Tem certeza que deseja excluir esta mensalidade?')) return;
        showLoader();
        try {
            const response = await fetch(`${API_URL_MENSALIDADES}/${id}`, { method: 'DELETE', headers: { 'Authorization': `Bearer ${getAuthData().token}` } });
            handleAuthError(response);
            const result = await response.json();
            displayMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                carregarDetalhesMensalidades();
            }
        } catch (error) {
            displayMessage('Não foi possível conectar ao servidor.', 'error');
        } finally {
            hideLoader();
        }
    };

    const abrirModalPagamento = (id, valor) => {
        inputIdMensalidade.value = id;
        inputValorPago.value = parseFloat(valor).toFixed(2);
        inputDataPagamento.value = new Date().toISOString().split('T')[0];
        modalPagamento.classList.remove('hidden');
        modalPagamento.classList.add('visible');
    };

    const fecharModalPagamento = () => {
        modalPagamento.classList.remove('visible');
        modalPagamento.classList.add('hidden');
        formPagamento.reset();
    };

    formPagamento.addEventListener('submit', async (e) => {
        e.preventDefault();
        showLoader();
        const id = inputIdMensalidade.value;
        const data = { valor_pago: inputValorPago.value, data_pagamento: inputDataPagamento.value };
        try {
            const response = await fetch(`${API_URL_MENSALIDADES}/${id}/pagar`, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getAuthData().token}` }, body: JSON.stringify(data) });
            handleAuthError(response);
            const result = await response.json();
            if (result.success) {
                displayMessage('Pagamento registrado com sucesso!', 'success');
                fecharModalPagamento();
                carregarDetalhesMensalidades();
            } else {
                alert(`Erro: ${result.message}`);
            }
        } catch (error) {
            alert('Não foi possível conectar ao servidor.');
        } finally {
            hideLoader();
        }
    });

    // --- INICIALIZAÇÃO E EVENT LISTENERS GLOBAIS ---

    tabelaDetalhes.addEventListener('click', (e) => {
        const pagarBtn = e.target.closest('.btn-pagar');
        const excluirBtn = e.target.closest('.btn-excluir');
        if (pagarBtn) {
            abrirModalPagamento(pagarBtn.dataset.id, pagarBtn.dataset.valor);
        }
        if (excluirBtn) {
            excluirMensalidade(excluirBtn.dataset.id);
        }
    });

    filterMonthSelect.addEventListener('change', (e) => {
        state.selectedMonth = e.target.value;
        navigateTo('turmas');
    });

    btnVoltarParaTurmas.addEventListener('click', () => navigateTo('turmas'));
    btnVoltar.addEventListener('click', () => {
        if (state.selectedMonth === 'todos') {
            navigateTo('alunos', { selectedAlunoId: null, selectedAlunoNome: null });
        } else {
            navigateTo('turmas');
        }
    });

    turmasContainer.addEventListener('click', (e) => {
        const card = e.target.closest('[data-turma-id]');
        if (!card) return;

        const turmaId = card.dataset.turmaId;
        const turmaNome = card.dataset.turmaNome;

        if (state.selectedMonth === 'todos') {
            navigateTo('alunos', { selectedTurmaId: turmaId, selectedTurmaNome: turmaNome });
        } else {
            navigateTo('mensalidades', { selectedTurmaId: turmaId, selectedTurmaNome: turmaNome });
        }
    });

    alunosContainer.addEventListener('click', (e) => {
        const alunoEl = e.target.closest('[data-aluno-id]');
        if (!alunoEl) return;
        navigateTo('mensalidades', {
            selectedAlunoId: alunoEl.dataset.alunoId,
            selectedAlunoNome: alunoEl.dataset.alunoNome
        });
    });

    closeModalPagamento.addEventListener('click', fecharModalPagamento);
    btnCancelarPagamento.addEventListener('click', fecharModalPagamento);
    window.addEventListener('click', (e) => { if (e.target === modalPagamento) fecharModalPagamento(); });

    addAlunoSearchInput.addEventListener('input', debounce(async () => {
        const searchTerm = addAlunoSearchInput.value.trim();
        addAlunoResultsContainer.innerHTML = '';
        addAlunoResultsContainer.classList.add('hidden');
        addAlunoIdInput.value = '';

        if (searchTerm.length < 2) return;
        showLoader();
        try {
            const response = await fetch(`${API_URL_ALUNOS}?search=${encodeURIComponent(searchTerm)}`, {
                headers: { 'Authorization': `Bearer ${getAuthData().token}` }
            });
            handleAuthError(response);
            const result = await response.json();

            if (result.success && result.data.length > 0) {
                result.data.forEach(aluno => {
                    const item = document.createElement('div');
                    item.className = 'p-2 hover:bg-indigo-100 cursor-pointer';
                    item.textContent = `${aluno.nome_aluno} (RA: ${aluno.ra})`;
                    item.addEventListener('click', () => {
                        addAlunoSearchInput.value = aluno.nome_aluno;
                        addAlunoIdInput.value = aluno.id_aluno;
                        addAlunoResultsContainer.classList.add('hidden');
                    });
                    addAlunoResultsContainer.appendChild(item);
                });
                addAlunoResultsContainer.classList.remove('hidden');
            }
        } catch (error) { console.error('Erro na busca de alunos:', error); }
        finally { hideLoader(); }
    }, 300));

    document.addEventListener('click', (e) => { if (e.target.id !== 'add-aluno-search') addAlunoResultsContainer.classList.add('hidden'); });

    formAddMensalidade.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!addAlunoIdInput.value) {
            alert('Por favor, busque e selecione um aluno da lista.');
            return;
        }
        showLoader();
        const data = {
            id_aluno: addAlunoIdInput.value,
            valor_mensalidade: document.getElementById('valor').value,
            data_vencimento: document.getElementById('vencimento').value,
        };

        try {
            const response = await fetch(API_URL_MENSALIDADES, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getAuthData().token}` }, body: JSON.stringify(data) });
            handleAuthError(response);
            const result = await response.json();
            displayMessage(result.message, result.success ? 'success' : 'error');
            if (result.success) {
                formAddMensalidade.reset();
                addAlunoIdInput.value = '';
                carregarResumoTurmas();
            }
        } catch (error) {
            displayMessage('Não foi possível conectar ao servidor.', 'error');
        } finally {
            hideLoader();
        }
    });

    const init = () => {
        
        const { token, role } = getAuthData();
        if (!token || (role !== 'admin' && role !== 'professor')) { logout(); return; }

        const select = filterMonthSelect;
        select.innerHTML = '<option value="todos">Ano Inteiro / Todos</option>';
        const hoje = new Date();
        const meses = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];

        // <-- MUDANÇA AQUI: Loop alterado para incluir meses futuros -->
        // Gera 2 meses futuros, o atual, e 9 meses passados.
        for (let i = -2; i < 10; i++) {
            const data = new Date(hoje.getFullYear(), hoje.getMonth() - i, 1);
            const ano = data.getFullYear();
            const mesNumero = (data.getMonth() + 1).toString().padStart(2, '0');
            const mesNome = meses[data.getMonth()];
            const option = new Option(`${mesNome} de ${ano}`, `${ano}-${mesNumero}`);
            select.add(option);
        }

        // Define o mês atual como padrão ao carregar
        select.value = `${hoje.getFullYear()}-${(hoje.getMonth() + 1).toString().padStart(2, '0')}`;
        state.selectedMonth = select.value;

        renderView();
    };

    init();
});