document.addEventListener('DOMContentLoaded', () => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const progressTextEl = document.querySelector('[data-progress-text]');

    document.querySelectorAll('form[data-toggle-completion]').forEach((form) => {
        const checkbox = form.querySelector('input[type="checkbox"]');
        const bodyEl = form.querySelector('[data-step-body]');
        if (!checkbox) {
            return;
        }

        checkbox.addEventListener('change', async (event) => {
            event.preventDefault();
            const previousState = !checkbox.checked;

            try {
                const response = await fetch(form.action, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Toggle failed');
                }

                const data = await response.json();
                checkbox.checked = data.is_completed;

                if (bodyEl) {
                    bodyEl.classList.toggle('line-through', data.is_completed);
                    bodyEl.classList.toggle('text-gray-400', data.is_completed);
                }

                if (progressTextEl) {
                    progressTextEl.textContent = `${data.completed} of ${data.total} completed`;
                }
            } catch (err) {
                checkbox.checked = previousState;
                form.submit();
            }
        });
    });
});
