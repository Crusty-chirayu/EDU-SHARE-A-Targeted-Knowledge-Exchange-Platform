(() => {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function showStatus(message, isError = false) {
        const target = document.querySelector('[data-status-message]');
        if (!target) return;
        target.textContent = message;
        target.classList.toggle('text-red-700', isError);
        target.classList.toggle('text-green-700', !isError);
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        let data;
        try {
            data = await response.json();
        } catch (_) {
            throw new Error('The server returned an invalid response.');
        }
        if (!response.ok) {
            throw new Error(data.message || 'The request could not be completed.');
        }
        return data;
    }

    document.querySelectorAll('[data-favorite-material]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const data = await postJson(button.dataset.endpoint, {
                    material_id: Number(button.dataset.favoriteMaterial)
                });
                const active = data.action === 'added';
                button.dataset.active = active ? 'true' : 'false';
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.textContent = active ? '♥ Favorited' : '♡ Favorite';
                showStatus(active ? 'Material added to favorites.' : 'Material removed from favorites.');
                if (button.dataset.removeOnUnfavorite === 'true' && !active) {
                    button.closest('[data-material-card]')?.remove();
                }
            } catch (error) {
                showStatus(error.message, true);
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-favorite-university]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const data = await postJson(button.dataset.endpoint, {
                    university_id: Number(button.dataset.favoriteUniversity)
                });
                const active = data.action === 'added';
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.textContent = active ? '♥' : '♡';
                showStatus(active ? 'University added to favorites.' : 'University removed from favorites.');
            } catch (error) {
                showStatus(error.message, true);
            } finally {
                button.disabled = false;
            }
        });
    });

    function replaceOptions(select, items, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));
        items.forEach((item) => select.add(new Option(String(item.name), String(item.id))));
        select.disabled = false;
    }

    async function fetchOptions(url) {
        const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) throw new Error('Unable to load academic options.');
        return response.json();
    }

    const university = document.querySelector('[data-university-select]');
    const department = document.querySelector('[data-department-select]');
    if (university && department) {
        university.addEventListener('change', async () => {
            department.replaceChildren(new Option('Loading…', ''));
            department.disabled = true;
            if (!university.value) {
                replaceOptions(department, [], 'Select department');
                return;
            }
            try {
                const items = await fetchOptions(`${department.dataset.endpoint}?id=${encodeURIComponent(university.value)}`);
                replaceOptions(department, items, 'Select department');
            } catch (error) {
                replaceOptions(department, [], 'Unable to load departments');
                showStatus(error.message, true);
            }
        });
    }

    const uploadDepartment = document.querySelector('[data-upload-department]');
    const course = document.querySelector('[data-course-select]');
    const subject = document.querySelector('[data-subject-select]');
    const semester = document.querySelector('[data-semester-select]');

    async function loadCourses() {
        if (!uploadDepartment || !course || !subject) return;
        replaceOptions(subject, [], 'Select subject');
        if (!uploadDepartment.value) {
            replaceOptions(course, [], 'Select course');
            return;
        }
        course.disabled = true;
        try {
            const items = await fetchOptions(`${course.dataset.endpoint}?id=${encodeURIComponent(uploadDepartment.value)}`);
            replaceOptions(course, items, 'Select course');
        } catch (error) {
            replaceOptions(course, [], 'Unable to load courses');
            showStatus(error.message, true);
        }
    }

    async function loadSubjects() {
        if (!course || !subject || !semester) return;
        if (!course.value || !semester.value) {
            replaceOptions(subject, [], 'Select course and semester');
            return;
        }
        subject.disabled = true;
        try {
            const query = `?id=${encodeURIComponent(course.value)}&semester=${encodeURIComponent(semester.value)}`;
            const items = await fetchOptions(`${subject.dataset.endpoint}${query}`);
            replaceOptions(subject, items, 'Select subject');
        } catch (error) {
            replaceOptions(subject, [], 'Unable to load subjects');
            showStatus(error.message, true);
        }
    }

    uploadDepartment?.addEventListener('change', loadCourses);
    course?.addEventListener('change', loadSubjects);
    semester?.addEventListener('change', loadSubjects);

    const files = document.querySelector('[data-upload-files]');
    files?.addEventListener('change', () => {
        const maximum = Number(files.dataset.maxFiles || 2);
        if (files.files.length > maximum) {
            files.value = '';
            showStatus(`Choose no more than ${maximum} files.`, true);
        }
    });
})();
