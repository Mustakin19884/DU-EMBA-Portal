</main>

</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.getElementById('adminMenuToggle');
    const backdrop = document.getElementById('adminSidebarBackdrop');

    function openSidebar() {
        sidebar.classList.add('open');
        backdrop.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
    }

    if (toggle) {
        toggle.addEventListener('click', function() {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

});
</script>

</body>

</html>