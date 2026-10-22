document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('[data-admin-sidebar]');
    const sidebarOverlay = document.querySelector('[data-admin-sidebar-overlay]');
    const mobileSidebar = window.matchMedia('(max-width: 780px)');
    const sidebarButtons = [...document.querySelectorAll('[data-sidebar-toggle]')];

    const setMobileSidebar = (open) => {
        sidebar?.classList.toggle('open', open);
        sidebarOverlay?.classList.toggle('open', open);
        document.body.classList.toggle('admin-menu-open', open);
        sidebarButtons.forEach((button) => button.setAttribute('aria-expanded', String(open)));
    };

    sidebarButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (mobileSidebar.matches) {
                setMobileSidebar(!sidebar?.classList.contains('open'));
                return;
            }

            setMobileSidebar(false);
            document.body.classList.toggle('sidebar-collapsed');
        });
    });
    sidebarOverlay?.addEventListener('click', () => setMobileSidebar(false));
    sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
        if (mobileSidebar.matches) setMobileSidebar(false);
    }));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileSidebar.matches) setMobileSidebar(false);
    });
    mobileSidebar.addEventListener('change', () => setMobileSidebar(false));

    document.querySelectorAll('[data-image-dropzone]').forEach((dropzone) => {
        const input = dropzone.querySelector('[data-image-input]');
        const preview = dropzone.querySelector('[data-image-preview]');
        const title = dropzone.querySelector('[data-upload-title]');

        const processFile = (file) => {
            if (!file) return false;
            const validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif'];
            if (!validTypes.includes(file.type.toLowerCase())) {
                alert('Please select a valid image file (PNG, JPG, WEBP, or GIF).');
                if (input) input.value = '';
                return false;
            }
            const maxSizeBytes = 10 * 1024 * 1024; // 10MB
            if (file.size > maxSizeBytes) {
                const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                alert(`The selected file (${sizeMb} MB) exceeds the 10MB maximum limit. Please choose a smaller file.`);
                if (input) input.value = '';
                return false;
            }
            if (preview) {
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            }
            if (title) {
                title.textContent = file.name;
            }
            return true;
        };

        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (file && !processFile(file)) {
                input.value = '';
            }
        });

        ['dragenter', 'dragover'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragging');
        }));
        ['dragleave', 'drop'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
        }));
        dropzone.addEventListener('drop', (event) => {
            const file = event.dataTransfer?.files?.[0];
            if (!file || !input) return;
            if (processFile(file)) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
            }
        });
    });

    document.querySelectorAll('[data-member-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.memberModalOpen);
            document.querySelectorAll('dialog[data-member-modal][open]').forEach((modal) => {
                if (modal !== target) modal.close();
            });
            if (target && !target.open) target.showModal();
        });
    });
    document.querySelectorAll('dialog[data-member-modal]').forEach((modal) => {
        modal.querySelectorAll('[data-member-modal-close]').forEach((button) => button.addEventListener('click', () => modal.close()));
        modal.addEventListener('click', (event) => {
            const bounds = modal.getBoundingClientRect();
            const inside = event.clientX >= bounds.left && event.clientX <= bounds.right && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
            if (!inside) modal.close();
        });
    });
    document.querySelectorAll('dialog[data-member-modal][data-modal-auto-open]').forEach((modal) => {
        if (!modal.open) modal.showModal();
    });

    document.querySelectorAll('[data-telegram-button-builder]').forEach((builder) => {
        const list = builder.querySelector('[data-telegram-button-list]');
        const template = builder.querySelector('[data-telegram-button-template]');
        const empty = builder.querySelector('[data-telegram-buttons-empty]');
        let nextIndex = list?.querySelectorAll('[data-telegram-button-row]').length ?? 0;

        const refresh = () => {
            const rows = [...(list?.querySelectorAll('[data-telegram-button-row]') ?? [])];
            rows.forEach((row, index) => {
                const number = row.querySelector('[data-telegram-button-number]');
                if (number) number.textContent = String(index + 1);
            });
            if (empty) empty.hidden = rows.length > 0;
        };
        const bindRemove = (row) => row.querySelector('[data-remove-telegram-button]')?.addEventListener('click', () => {
            row.remove();
            refresh();
        });

        list?.querySelectorAll('[data-telegram-button-row]').forEach(bindRemove);
        builder.querySelector('[data-add-telegram-button]')?.addEventListener('click', () => {
            if (!list || !template || list.querySelectorAll('[data-telegram-button-row]').length >= 8) return;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
            const row = wrapper.firstElementChild;
            if (!row) return;
            list.appendChild(row);
            bindRemove(row);
            refresh();
            row.querySelector('input')?.focus();
        });
        refresh();
    });

    document.querySelectorAll('[data-telegram-schedule-toggle]').forEach((toggle) => {
        const form = toggle.closest('form');
        const field = form?.querySelector('[data-telegram-schedule-field]');
        const input = field?.querySelector('input');
        const refresh = () => {
            if (!field) return;
            field.hidden = !toggle.checked;
            if (input) input.required = toggle.checked;
        };
        toggle.addEventListener('change', refresh);
        refresh();
    });
});

/**
 * Colour theme. Dark is the default; the choice is stored per browser so it
 * survives navigation. The pre-paint script in the layout head applies the
 * saved value — this only handles switching it.
 */
document.addEventListener('DOMContentLoaded', () => {
    const STORAGE_KEY = 'lucy-admin-theme';
    const root = document.documentElement;

    const current = () => (root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

    const apply = (theme) => {
        if (theme === 'light') {
            root.setAttribute('data-theme', 'light');
        } else {
            root.setAttribute('data-theme', 'dark');
        }
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {
            /* storage unavailable (private mode) — the toggle still works for this page */
        }
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', String(theme === 'light'));
            button.title = theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme';
        });
    };

    apply(current());

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => apply(current() === 'light' ? 'dark' : 'light'));
    });
});

/**
 * Remember whether the desktop sidebar was collapsed, so the layout does not
 * reset on every page load.
 */
document.addEventListener('DOMContentLoaded', () => {
    const STORAGE_KEY = 'lucy-admin-sidebar';
    const wide = window.matchMedia('(min-width: 781px)');

    try {
        if (wide.matches && localStorage.getItem(STORAGE_KEY) === 'collapsed') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) { /* ignore */ }

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!wide.matches) return;
            try {
                localStorage.setItem(STORAGE_KEY, document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'expanded');
            } catch (e) { /* ignore */ }
        });
    });
});
