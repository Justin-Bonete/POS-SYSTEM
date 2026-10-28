document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target !== modal) {
                return;
            }

            modal.style.display = 'none';
            modal.classList.remove('show');
        });
    });
});
