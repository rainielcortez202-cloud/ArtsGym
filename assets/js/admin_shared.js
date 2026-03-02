document.addEventListener('DOMContentLoaded', function() {
    // Prefer existing overlay id 'overlay' (used in many admin pages), fall back to 'sidebarOverlay'
    let overlay = document.getElementById('overlay') || document.getElementById('sidebarOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        overlay.id = 'overlay';
        overlay.addEventListener('click', toggleSidebar);
        document.body.prepend(overlay);
    }

    // If sidebar element exists but lacks accessible attributes, ensure basic setup
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.setAttribute('aria-hidden', sidebar.classList.contains('show') ? 'false' : 'true');
});

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay') || document.getElementById('sidebarOverlay');
    const main = document.getElementById('main');

    if (!sidebar) return;

    if (window.innerWidth < 992) {
        sidebar.classList.toggle('show');
        if (overlay) overlay.classList.toggle('show');
    } else {
        sidebar.classList.toggle('collapsed');
        if (main) main.classList.toggle('expanded');
    }
}

function toggleDarkMode() { 
    const isDark = !document.body.classList.contains('dark-mode-active'); 
    document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60); 
    location.reload(); 
}
