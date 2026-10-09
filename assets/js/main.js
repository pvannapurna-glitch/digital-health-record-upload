/**
 * DEPA HealthExchange - Client-side scripts & interactions
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialize Bootstrap Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // 2. Demo Credential Quick-Fill Helper on Login Page
    const demoButtons = document.querySelectorAll('.btn-quick-login');
    if (demoButtons.length > 0) {
        demoButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const username = this.getAttribute('data-username');
                const password = this.getAttribute('data-password');
                const role = this.getAttribute('data-role');

                const userInput = document.getElementById('username');
                const passInput = document.getElementById('password');
                const roleSelect = document.getElementById('role_select');

                if (userInput) userInput.value = username;
                if (passInput) passInput.value = password;
                if (roleSelect && role) {
                    roleSelect.value = role;
                }

                // Add visual highlight
                userInput.classList.add('is-valid');
                passInput.classList.add('is-valid');
                setTimeout(() => {
                    userInput.classList.remove('is-valid');
                    passInput.classList.remove('is-valid');
                }, 1500);
            });
        });
    }

    // 3. Dynamic Search Filter for Data Tables
    const searchInputs = document.querySelectorAll('.table-search-input');
    searchInputs.forEach(input => {
        const targetTableId = input.getAttribute('data-table-target');
        const table = document.getElementById(targetTableId);
        if (!table) return;

        input.addEventListener('keyup', function () {
            const query = this.value.toLowerCase().trim();
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // 4. Set Minimum Date on Expiry Fields to today/now
    const expiryInputs = document.querySelectorAll('input[type="datetime-local"].min-today, input[type="date"].min-today');
    expiryInputs.forEach(input => {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        if (input.type === 'datetime-local') {
            input.min = now.toISOString().slice(0, 16);
        } else {
            input.min = now.toISOString().split('T')[0];
        }
    });

    // 5. Consent Revoke Confirmation Modal Trigger
    const revokeForms = document.querySelectorAll('.form-revoke-consent');
    revokeForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            const confirmMsg = "Are you sure you want to REVOKE this consent immediately? The requester will instantly lose access to your health record.";
            if (!confirm(confirmMsg)) {
                e.preventDefault();
            }
        });
    });

    // 6. Record Delete Confirmation
    const deleteForms = document.querySelectorAll('.form-delete-record');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            if (!confirm("Are you sure you want to permanently delete this health record? This action cannot be undone.")) {
                e.preventDefault();
            }
        });
    });
});
