// public/js/AuthService.js

const AUTH_TOKEN_KEY = 'jwtToken';
const USER_DATA_KEY = 'userData'; // Chave para armazenar dados decodificados do usuário

const AuthService = {
    // Armazena o token JWT e os dados do usuário (decodificados)
    setToken: (token, userData) => {
        localStorage.setItem(AUTH_TOKEN_KEY, token);
        localStorage.setItem(USER_DATA_KEY, JSON.stringify(userData));
    },

    // Obtém o token JWT
    getToken: () => {
        return localStorage.getItem(AUTH_TOKEN_KEY);
    },

    // Obtém os dados do usuário logado
    getLoggedInUser: () => {
        const userDataString = localStorage.getItem(USER_DATA_KEY);
        return userDataString ? JSON.parse(userDataString) : null;
    },

    // Remove o token e os dados do usuário, e redireciona para a página de login
    logout: () => {
        localStorage.removeItem(AUTH_TOKEN_KEY);
        localStorage.removeItem(USER_DATA_KEY);
        window.location.href = 'login.html';
    },

    // Decodifica o payload do JWT para obter dados do usuário sem enviar para o backend
    // Útil para obter o role ou username para exibição no frontend
    decodeJwt: (token) => {
        try {
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(jsonPayload).data; // Assumindo que seus dados de usuário estão em 'data'
        } catch (e) {
            console.error("Erro ao decodificar JWT:", e);
            return null;
        }
    }
};

// Adiciona listener para o botão de logout se existir na página
document.addEventListener('DOMContentLoaded', () => {
    const logoutButton = document.getElementById('logoutBtn');
    if (logoutButton) {
        logoutButton.addEventListener('click', AuthService.logout);
    }
});