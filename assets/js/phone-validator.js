/**
 * Phone Number Input Validator
 * 
 * Enforces strict validation on all phone number inputs:
 *   - Only allows numeric characters (0-9)
 *   - Enforces a maximum length of 11 digits
 *   - Prevents typing or pasting beyond the 11-digit limit
 * 
 * Auto-attaches to any input with:
 *   - type="tel"
 *   - name containing "phone"
 *   - class "phone-input"
 */
(function () {
    'use strict';

    const MAX_DIGITS = 11;

    /**
     * Determine whether an input element is a phone field.
     */
    function isPhoneInput(el) {
        if (el.tagName !== 'INPUT') return false;
        if (el.type === 'tel') return true;
        const name = (el.name || '').toLowerCase();
        if (name.includes('phone')) return true;
        if (el.classList.contains('phone-input')) return true;
        return false;
    }

    /**
     * Sanitize the value: strip non-digits and truncate to MAX_DIGITS.
     */
    function sanitize(value) {
        return value.replace(/[^0-9]/g, '').slice(0, MAX_DIGITS);
    }

    /**
     * Attach validation listeners to a single phone input.
     */
    function attachValidation(input) {
        if (input.dataset.phoneValidated) return; // prevent double-binding
        input.dataset.phoneValidated = 'true';

        // Set HTML attributes as a baseline
        input.setAttribute('maxlength', MAX_DIGITS);
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('pattern', '[0-9]*');

        // Sanitize any pre-filled value
        input.value = sanitize(input.value);

        // Block non-numeric keys (allow navigation/control keys)
        input.addEventListener('keydown', function (e) {
            // Allow: backspace, delete, tab, escape, enter, arrows, home, end
            const allowedKeys = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (allowedKeys.includes(e.key)) return;

            // Allow Ctrl/Cmd + A, C, V, X (select all, copy, paste, cut)
            if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())) return;

            // Block if not a digit
            if (!/^[0-9]$/.test(e.key)) {
                e.preventDefault();
                return;
            }

            // Block if already at max length (and no text is selected)
            const currentDigits = input.value.replace(/[^0-9]/g, '');
            const selectionLength = input.selectionEnd - input.selectionStart;
            if (currentDigits.length >= MAX_DIGITS && selectionLength === 0) {
                e.preventDefault();
            }
        });

        // Sanitize on input (catches paste via keyboard, autofill, etc.)
        input.addEventListener('input', function () {
            const pos = input.selectionStart;
            const before = input.value;
            input.value = sanitize(input.value);
            // Try to restore cursor position intelligently
            if (input.value.length < before.length) {
                const diff = before.length - input.value.length;
                input.setSelectionRange(Math.max(0, pos - diff), Math.max(0, pos - diff));
            }
        });

        // Handle paste explicitly to ensure sanitization
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text');
            const digitsOnly = pasteData.replace(/[^0-9]/g, '');

            const currentValue = input.value;
            const selStart = input.selectionStart;
            const selEnd = input.selectionEnd;

            // Build new value: replace selected text with pasted digits
            const newValue = currentValue.slice(0, selStart) + digitsOnly + currentValue.slice(selEnd);
            input.value = sanitize(newValue);

            // Place cursor after inserted text
            const newPos = Math.min(selStart + digitsOnly.length, MAX_DIGITS);
            input.setSelectionRange(newPos, newPos);

            // Trigger input event for any listeners
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // Handle drag-and-drop
        input.addEventListener('drop', function (e) {
            e.preventDefault();
            const dropData = e.dataTransfer.getData('text');
            const digitsOnly = dropData.replace(/[^0-9]/g, '');
            input.value = sanitize(input.value + digitsOnly);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    /**
     * Scan the DOM and attach validation to all phone inputs.
     */
    function initAll() {
        document.querySelectorAll('input').forEach(function (input) {
            if (isPhoneInput(input)) {
                attachValidation(input);
            }
        });
    }

    // Initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Observe DOM for dynamically added inputs (modals, AJAX forms, etc.)
    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return; // element nodes only
                if (node.tagName === 'INPUT' && isPhoneInput(node)) {
                    attachValidation(node);
                }
                // Also check children of added nodes
                if (node.querySelectorAll) {
                    node.querySelectorAll('input').forEach(function (input) {
                        if (isPhoneInput(input)) {
                            attachValidation(input);
                        }
                    });
                }
            });
        });
    });

    observer.observe(document.body || document.documentElement, {
        childList: true,
        subtree: true
    });
})();
