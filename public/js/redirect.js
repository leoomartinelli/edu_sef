// public/js/redirect.js

// Função para redirecionar após o login
function redirectToDashboard(role) {
    switch (role) {
        case 'admin':
        case 'professor':
            // Para admin/professor, redireciona para a tela de mensalidades (ou um dashboard geral)
            window.location.href = 'crud_mensalidades.html';
            break;
        case 'aluno':
            window.location.href = 'aluno_dashboard.html';
            break;
        default:
            window.location.href = 'login.html'; // Fallback
    }
}

// Em cada página protegida, você pode chamar uma função para verificar o role
// e redirecionar se o usuário não tiver permissão.
// Ex: Em crud_alunos.html, crud_turmas.html, crud_mensalidades.html, verificar se é admin/professor.
// Ex: Em aluno_dashboard.html, verificar se é aluno.