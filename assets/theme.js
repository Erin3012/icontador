/**
 * Sistema de Tema (Dark Mode / Light Mode)
 * Gestiona preferencias de tema con persistencia en localStorage
 */

class ThemeManager {
    constructor() {
        this.STORAGE_KEY = 'icontador_theme_preference';
        this.THEME_ATTRIBUTE = 'data-theme';
        this.THEMES = ['light', 'dark', 'auto'];
        this.init();
    }

    /**
     * Inicializar tema al cargar la página
     */
    init() {
        const savedTheme = this.getStoredTheme();
        const preferredTheme = savedTheme || this.getSystemPreference();
        this.applyTheme(preferredTheme);
        this.setupThemeToggle();
        this.listenSystemPreference();
    }

    /**
     * Obtener tema guardado en localStorage
     */
    getStoredTheme() {
        try {
            return localStorage.getItem(this.STORAGE_KEY);
        } catch (e) {
            console.warn('localStorage no disponible:', e);
            return null;
        }
    }

    /**
     * Obtener preferencia del sistema
     */
    getSystemPreference() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    /**
     * Aplicar tema al documento
     */
    applyTheme(theme) {
        if (!this.THEMES.includes(theme)) {
            theme = 'auto';
        }

        document.documentElement.setAttribute(this.THEME_ATTRIBUTE, theme);

        // Si es 'auto', aplicar según preferencia del sistema
        if (theme === 'auto') {
            const systemTheme = this.getSystemPreference();
            document.documentElement.style.colorScheme = systemTheme;
        } else {
            document.documentElement.style.colorScheme = theme;
        }

        // Guardar preferencia
        try {
            localStorage.setItem(this.STORAGE_KEY, theme);
        } catch (e) {
            console.warn('No se pudo guardar tema en localStorage:', e);
        }

        // Dispatch evento personalizado
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
    }

    /**
     * Obtener tema actual
     */
    getCurrentTheme() {
        return document.documentElement.getAttribute(this.THEME_ATTRIBUTE) || 'auto';
    }

    /**
     * Alternar entre temas
     */
    toggleTheme() {
        const currentTheme = this.getCurrentTheme();
        const nextTheme = currentTheme === 'light' ? 'dark' : currentTheme === 'dark' ? 'auto' : 'light';
        this.applyTheme(nextTheme);
    }

    /**
     * Configurar botón toggle
     */
    setupThemeToggle() {
        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggleTheme());
            this.updateToggleIcon(toggleBtn);
        }
    }

    /**
     * Actualizar icono del botón toggle
     */
    updateToggleIcon(btn) {
        const theme = this.getCurrentTheme();
        let icon = '🌙';
        if (theme === 'dark') {
            icon = '☀️';
        } else if (theme === 'auto') {
            icon = '🔄';
        }
        btn.textContent = icon;
    }

    /**
     * Escuchar cambios de preferencia del sistema
     */
    listenSystemPreference() {
        if (window.matchMedia) {
            const darkModeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            darkModeMediaQuery.addEventListener('change', () => {
                const currentTheme = this.getCurrentTheme();
                if (currentTheme === 'auto') {
                    this.applyTheme('auto');
                }
            });
        }
    }
}

// Inicializar tema manager al cargar el documento
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.themeManager = new ThemeManager();
    });
} else {
    window.themeManager = new ThemeManager();
}
