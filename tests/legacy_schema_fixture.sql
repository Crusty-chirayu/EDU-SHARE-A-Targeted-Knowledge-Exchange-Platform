-- Minimal synthetic representation of the historical schema for migration testing only.
CREATE TABLE universities (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE departments (
    id INT NOT NULL AUTO_INCREMENT,
    university_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT departments_ibfk_1 FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE courses (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    university_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_course_dept FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
    CONSTRAINT fk_course_uni FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    course_id INT NOT NULL,
    department_id INT NOT NULL,
    semester INT NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT subjects_ibfk_1 FOREIGN KEY (course_id) REFERENCES courses (id),
    CONSTRAINT subjects_ibfk_2 FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(255) NOT NULL,
    gmail VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('student', 'teacher') NOT NULL,
    university_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    roll_number VARCHAR(50) DEFAULT NULL,
    branch VARCHAR(100) DEFAULT NULL,
    year INT DEFAULT NULL,
    contact VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY gmail (gmail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE materials (
    id INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    type VARCHAR(50) DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    university_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    branch_for VARCHAR(100) NOT NULL,
    upload_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    course_id INT DEFAULT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    user_id INT NOT NULL,
    subject_id INT DEFAULT NULL,
    user_course_name VARCHAR(255) DEFAULT NULL,
    user_subject_name VARCHAR(255) DEFAULT NULL,
    branch_id INT DEFAULT NULL,
    semester INT DEFAULT NULL,
    upload_group_id VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT materials_ibfk_1 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE,
    CONSTRAINT materials_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE material_favorites (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    material_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    favorited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_material_unique (user_id, material_id),
    CONSTRAINT material_favorites_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT material_favorites_ibfk_2 FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE university_favorites (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    university_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_university_unique (user_id, university_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_favorites (
    user_id INT NOT NULL,
    university_id INT NOT NULL,
    PRIMARY KEY (user_id, university_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO universities (id, name) VALUES (1, 'Legacy Synthetic University');
INSERT INTO departments (id, university_id, name) VALUES (1, 1, 'Legacy Computer Science');
INSERT INTO courses (id, name, university_id, department_id) VALUES (1, 'Legacy Course', 1, 1);
INSERT INTO subjects (id, name, course_id, department_id, semester) VALUES (1, 'Legacy Subject', 1, 1, 1);
INSERT INTO users (id, full_name, gmail, password, user_type, university_id, department_id)
VALUES (1, 'Legacy Synthetic Teacher', 'legacy-synthetic@example.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'teacher', 1, 1);
INSERT INTO materials
    (id, title, description, file_path, university_id, department_id, branch_for, course_id, user_id, subject_id, semester)
VALUES
    (1, 'Legacy Synthetic Material', 'Migration fixture', 'uploads/legacy-test.pdf', 1, 1, '', 1, 1, 1, 1);
INSERT INTO user_favorites (user_id, university_id) VALUES (1, 1);
