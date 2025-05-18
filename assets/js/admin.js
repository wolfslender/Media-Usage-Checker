document.addEventListener('DOMContentLoaded', function() {
    // Animaciones para las tarjetas de estadísticas
    const statCards = document.querySelectorAll('.muc-stat-card');
    statCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.animation = 'pulse 1s infinite';
        });
        card.addEventListener('mouseleave', () => {
            card.style.animation = '';
        });
    });

    // Mejorar la experiencia de selección múltiple
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_media[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }

    // Confirmación para eliminación
    const deleteButtons = document.querySelectorAll('button[name="delete_media"]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('¿Estás seguro de que deseas eliminar este archivo?')) {
                e.preventDefault();
            }
        });
    });

    // Feedback visual para acciones
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.classList.add('updating-message');
            }
        });
    });

    // Mejorar la accesibilidad del teclado
    const navTabs = document.querySelectorAll('.nav-tab');
    navTabs.forEach(tab => {
        tab.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
});