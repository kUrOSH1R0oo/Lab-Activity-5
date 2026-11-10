/**
 * To-do List (COMP016 Lab Activity 5): client-side behavior.
 *
 * The page works without JavaScript for the server-side features. This file
 * adds the conveniences: validation feedback, filters, search, inline editing,
 * confirm dialogs, theme toggle, toasts, and keyboard shortcuts.
 */
(function () {
    'use strict';

    var FILTER_KEY = 'todo.filter';
    var SCROLL_KEY = 'todo.scroll';
    var TOAST_MS = { success: 3000, error: 6000 };

    var root = document.documentElement;
    var addForm = document.getElementById('addForm');
    var titleInput = document.getElementById('nameInput');
    var counter = document.getElementById('counter');
    var searchInput = document.getElementById('searchInput');
    var taskItems = document.querySelectorAll('.task');
    var filterButtons = document.querySelectorAll('.filter');
    var emptyState = document.getElementById('emptyState');
    var noMatch = document.getElementById('noMatch');
    var toast = document.getElementById('toast');
    var toastMessage = document.getElementById('toastMessage');
    var toastClose = document.getElementById('toastClose');
    var dialog = document.getElementById('confirmDialog');
    var dialogText = document.getElementById('confirmText');
    var dialogOk = document.getElementById('confirmOk');
    var themeToggle = document.getElementById('themeToggle');

    var currentFilter = 'all';
    var toastTimer = null;

    /* Storage can be blocked (private mode), so never let it break the page. */
    var store = {
        get: function (key) {
            try { return window.sessionStorage.getItem(key); } catch (e) { return null; }
        },
        set: function (key, value) {
            try { window.sessionStorage.setItem(key, value); } catch (e) { /* ignore */ }
        },
        remove: function (key) {
            try { window.sessionStorage.removeItem(key); } catch (e) { /* ignore */ }
        }
    };

    /* ---------- Toast ---------- */

    function hideToast() {
        window.clearTimeout(toastTimer);
        toast.classList.remove('is-visible');
    }

    function showToast(message, type) {
        type = type || 'success';
        toastMessage.textContent = message;
        toast.dataset.type = type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(hideToast, TOAST_MS[type]);
    }

    toastClose.hidden = false;
    toastClose.addEventListener('click', hideToast);
    if (toast.classList.contains('is-visible')) {
        toastTimer = window.setTimeout(hideToast, TOAST_MS[toast.dataset.type] || TOAST_MS.success);
    }

    /* ---------- Theme ---------- */

    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

    function currentTheme() {
        return root.dataset.theme || (prefersDark.matches ? 'dark' : 'light');
    }

    function syncThemeLabel() {
        themeToggle.textContent = currentTheme() === 'dark' ? 'Light mode' : 'Dark mode';
    }

    themeToggle.hidden = false;
    syncThemeLabel();
    prefersDark.addEventListener('change', syncThemeLabel);
    themeToggle.addEventListener('click', function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.dataset.theme = next;
        // The server reads this cookie and renders the right theme with no flash.
        document.cookie = 'theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
        syncThemeLabel();
    });

    /* ---------- Character counter ---------- */

    function updateCounter() {
        var length = titleInput.value.length;
        var max = titleInput.maxLength;
        counter.textContent = length + '/' + max;
        counter.classList.toggle('is-near-limit', length >= max * 0.9);
    }

    titleInput.addEventListener('input', updateCounter);
    updateCounter();

    /* ---------- Filters and search ---------- */

    function applyFilters() {
        var query = searchInput.value.trim().toLowerCase();
        var visible = 0;

        taskItems.forEach(function (task) {
            var isDone = task.dataset.done === 'true';
            var matchesFilter = currentFilter === 'all' ||
                (currentFilter === 'done' && isDone) ||
                (currentFilter === 'active' && !isDone);
            var matchesSearch = task.dataset.title.indexOf(query) !== -1;
            var show = matchesFilter && matchesSearch;

            task.hidden = !show;
            if (show) { visible += 1; }
        });

        noMatch.hidden = !(taskItems.length > 0 && visible === 0);
    }

    function setFilter(name) {
        currentFilter = name;
        store.set(FILTER_KEY, name);
        filterButtons.forEach(function (button) {
            var active = button.dataset.filter === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        applyFilters();
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () { setFilter(button.dataset.filter); });
    });
    searchInput.addEventListener('input', applyFilters);
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && searchInput.value !== '') {
            searchInput.value = '';
            applyFilters();
            event.stopPropagation();
        }
    });

    var savedFilter = store.get(FILTER_KEY);
    setFilter(['all', 'active', 'done'].indexOf(savedFilter) !== -1 ? savedFilter : 'all');

    /* ---------- Inline editing ---------- */

    function closeEdits(returnFocus) {
        document.querySelectorAll('.task.is-editing').forEach(function (task) {
            task.classList.remove('is-editing');
            task.querySelector('.task-edit').hidden = true;
            if (returnFocus) { task.querySelector('[data-action="edit"]').focus(); }
        });
    }

    function openEdit(task) {
        closeEdits(false);
        task.classList.add('is-editing');
        var form = task.querySelector('.task-edit');
        form.hidden = false;
        form.elements.task_name.focus();
        form.elements.task_name.select();
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action]');
        if (!trigger) { return; }

        if (trigger.dataset.action === 'edit') {
            openEdit(trigger.closest('.task'));
        } else if (trigger.dataset.action === 'cancel-edit') {
            closeEdits(true);
        }
    });

    /* ---------- Confirm dialog ---------- */

    function confirmAction(message, label) {
        // Very old browsers without <dialog> fall back to the native prompt.
        if (typeof dialog.showModal !== 'function') {
            return Promise.resolve(window.confirm(message));
        }

        dialogText.textContent = message;
        dialogOk.textContent = label || 'Confirm';
        dialog.returnValue = '';
        dialog.showModal();

        return new Promise(function (resolve) {
            dialog.addEventListener('close', function () {
                resolve(dialog.returnValue === 'confirm');
            }, { once: true });
        });
    }

    // Clicking the dim backdrop cancels, like pressing Esc.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) { dialog.close(); }
    });

    /* ---------- Form submission ---------- */

    // Keep the reader's place in the list after a toggle, edit, or delete.
    function rememberScroll(form) {
        if (form !== addForm) { store.set(SCROLL_KEY, String(window.scrollY)); }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method === 'dialog') { return; }

        // Validate before the round trip. The server checks again.
        var name = form.elements.namedItem('task_name');
        var description = form.elements.namedItem('task_description');
        if (name && name.value.trim() === '') {
            event.preventDefault();
            showToast('Enter a task name.', 'error');
            name.focus();
            return;
        }
        if (description && description.value.trim() === '') {
            event.preventDefault();
            showToast('Enter a task description.', 'error');
            description.focus();
            return;
        }

        var message = form.dataset.confirm;
        if (message) {
            event.preventDefault();
            confirmAction(message, form.dataset.confirmLabel).then(function (confirmed) {
                if (confirmed) {
                    rememberScroll(form);
                    form.submit();
                }
            });
            return;
        }

        rememberScroll(form);
    });

    var savedScroll = store.get(SCROLL_KEY);
    if (savedScroll !== null) {
        store.remove(SCROLL_KEY);
        window.scrollTo(0, Number(savedScroll) || 0);
    }

    /* ---------- Keyboard shortcuts ---------- */

    function isTyping(target) {
        return target instanceof HTMLElement &&
            (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeEdits(true);
            return;
        }

        if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target) || dialog.open) {
            return;
        }

        if (event.key === 'n' || event.key === 'N') {
            event.preventDefault();
            titleInput.focus();
        } else if (event.key === '/') {
            event.preventDefault();
            searchInput.focus();
        }
    });
}());
