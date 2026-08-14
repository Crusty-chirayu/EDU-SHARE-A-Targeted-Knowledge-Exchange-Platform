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

    document.querySelectorAll('[data-favorite-resource]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const data = await postJson(button.dataset.endpoint, {
                    resource_id: Number(button.dataset.favoriteResource)
                });
                const active = data.action === 'added';
                button.dataset.active = active ? 'true' : 'false';
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.textContent = active ? '♥ Favorited' : '♡ Favorite';
                showStatus(active ? 'Resource added to favorites.' : 'Resource removed from favorites.');
                if (button.dataset.removeOnUnfavorite === 'true' && !active) {
                    button.closest('[data-resource-card]')?.remove();
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

    document.querySelectorAll('[data-academic-chain]').forEach((container) => {
        const university = container.querySelector('[data-university-select]');
        const department = container.querySelector('[data-department-select]');
        const course = container.querySelector('[data-course-select]');
        const subject = container.querySelector('[data-subject-select]');
        const semester = container.querySelector('[data-semester-select]');

        async function loadDepartments() {
            if (!university || !department) return;
            replaceOptions(department, [], university.value ? 'Loading…' : 'Select department');
            if (course) replaceOptions(course, [], 'Select course/program');
            if (subject) replaceOptions(subject, [], 'Select course and semester');
            if (!university.value) return;
            department.disabled = true;
            try {
                const query = `?university_id=${encodeURIComponent(university.value)}`;
                const items = await fetchOptions(`${university.dataset.departmentsEndpoint}${query}`);
                replaceOptions(department, items, 'Select department');
            } catch (error) {
                replaceOptions(department, [], 'Unable to load departments');
                showStatus(error.message, true);
            }
        }

        async function loadCourses() {
            if (!department || !course) return;
            replaceOptions(course, [], department.value ? 'Loading…' : 'Select course/program');
            if (subject) replaceOptions(subject, [], 'Select course and semester');
            if (!department.value) return;
            course.disabled = true;
            try {
                const query = `?department_id=${encodeURIComponent(department.value)}`;
                const items = await fetchOptions(`${department.dataset.coursesEndpoint}${query}`);
                replaceOptions(course, items, 'Select course/program');
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
                const query = `?course_id=${encodeURIComponent(course.value)}&semester=${encodeURIComponent(semester.value)}`;
                const items = await fetchOptions(`${course.dataset.subjectsEndpoint}${query}`);
                replaceOptions(subject, items, 'Select subject');
            } catch (error) {
                replaceOptions(subject, [], 'Unable to load subjects');
                showStatus(error.message, true);
            }
        }

        university?.addEventListener('change', loadDepartments);
        department?.addEventListener('change', loadCourses);
        course?.addEventListener('change', loadSubjects);
        semester?.addEventListener('change', loadSubjects);

        const role = container.querySelector('#role');
        const year = container.querySelector('#year');
        function updateStudentRequirements() {
            if (!role) return;
            const student = role.value === 'student';
            if (course) course.required = student;
            if (year) year.required = student;
        }
        role?.addEventListener('change', updateStudentRequirements);
        updateStudentRequirements();
    });

    const files = document.querySelector('[data-upload-files]');
    files?.addEventListener('change', () => {
        const maximum = Number(files.dataset.maxFiles || 2);
        if (files.files.length > maximum) {
            files.value = '';
            showStatus(`Choose no more than ${maximum} files.`, true);
        }
    });
})();
