</main>

</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const sidebar = document.getElementById('teacherSidebar');
    const toggle = document.getElementById('teacherMenuToggle');
    const backdrop = document.getElementById('teacherSidebarBackdrop');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
    }

    if (toggle) {
        toggle.addEventListener('click', function() {
            if (sidebar && sidebar.classList.contains('open')) {
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