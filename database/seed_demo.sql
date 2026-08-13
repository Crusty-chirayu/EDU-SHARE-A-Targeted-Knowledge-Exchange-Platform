-- Public, non-sensitive academic fixtures for local development and automated tests.
-- No users, contact details, password hashes, favorites, or material records are included.
INSERT INTO universities (id, name) VALUES
    (1, 'Demo Institute of Technology');

INSERT INTO departments (id, university_id, name) VALUES
    (1, 1, 'Computer Science');

INSERT INTO courses (id, department_id, name) VALUES
    (1, 1, 'Bachelor of Computer Applications');

INSERT INTO subjects (id, name, course_id, department_id, semester) VALUES
    (1, 'Programming Fundamentals', 1, 1, 1),
    (2, 'Database Systems', 1, 1, 2);
