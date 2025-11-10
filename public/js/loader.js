// Conteúdo FINAL para o arquivo: public/js/loader.js

window.showLoader = () => {
    const loader = document.getElementById('global-loader');
    if (loader) loader.style.display = 'flex';
};

window.hideLoader = () => {
    const loader = document.getElementById('global-loader');
    if (loader) loader.style.display = 'none';
};