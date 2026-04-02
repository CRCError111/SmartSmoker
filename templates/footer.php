        </div><!-- /.content-wrapper -->
    </div><!-- /.main-content -->

    <!-- Bootstrap JS (для совместимости с существующими страницами) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Автоскрытие алертов через 5 секунд
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el) {
                setTimeout(function() {
                    el.style.transition = 'opacity .4s ease';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 400);
                }, 5000);
            });

            // Подтверждение удаления
            document.querySelectorAll('[data-confirm]').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    if (!confirm(this.getAttribute('data-confirm'))) e.preventDefault();
                });
            });
        });

        // Toast helper
        function showToast(title, message, type = 'success') {
            const container = document.querySelector('.toast-container') || (() => {
                const c = document.createElement('div');
                c.className = 'toast-container';
                document.body.appendChild(c);
                return c;
            })();
            const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<span style="font-size:1.2em;flex-shrink:0">${icons[type] ?? icons.info}</span>
                <div><div class="toast-title">${title}</div>${message ? `<div class="toast-body">${message}</div>` : ''}</div>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.transition = 'opacity .3s ease';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
    </script>
</body>
</html>
