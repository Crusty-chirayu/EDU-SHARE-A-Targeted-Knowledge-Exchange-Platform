<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:667eea,100:764ba2&height=220&section=header&text=EDU-SHARE&fontSize=70&fontColor=ffffff&animation=fadeIn&fontAlignY=38&desc=Targeted%20Knowledge%20Exchange%20Platform&descAlignY=58&descSize=20" width="100%"/>

<a href="https://github.com/Crusty-chirayu/EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform">
  <img src="https://readme-typing-svg.demolab.com?font=Fira+Code&size=22&pause=1000&color=764ABA&center=true&vCenter=true&width=600&lines=Connecting+Students+%26+Teachers+Across+Campuses;Notes+%C2%B7+Papers+%C2%B7+Academic+Resources+%C2%B7+Open+Knowledge;University+%E2%86%92+Department+%E2%86%92+Course+%E2%86%92+Subject" alt="Typing SVG" />
</a>

<br/>

![Stars](https://img.shields.io/github/stars/Crusty-chirayu/EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform?style=for-the-badge&color=764ba2&labelColor=1a1b27)
![Forks](https://img.shields.io/github/forks/Crusty-chirayu/EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform?style=for-the-badge&color=667eea&labelColor=1a1b27)
![Issues](https://img.shields.io/github/issues/Crusty-chirayu/EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform?style=for-the-badge&color=f7b731&labelColor=1a1b27)
![License](https://img.shields.io/badge/license-MIT-brightgreen?style=for-the-badge&labelColor=1a1b27)
![Status](https://img.shields.io/badge/status-active%20development-success?style=for-the-badge&labelColor=1a1b27)

</div>

<br/>

## 📖 About The Project

**EDU-SHARE** is a web-based academic resource sharing platform designed to connect students and teachers **across different universities and colleges**. It gives users a structured, secure environment to upload, discover, and download study materials — notes, papers, and academic resources — without being boxed in by institutional walls.

Materials are organized in a clean hierarchy — **University → Department → Course → Subject** — so finding relevant content stays fast and intuitive no matter how large the archive grows. At its core, EDU-SHARE is a bet on open, collaborative learning: knowledge shouldn't stop at a campus gate.

<br/>

## 🧭 Table of Contents

- [About The Project](#-about-the-project)
- [Features](#-features)
- [Tech Stack](#-tech-stack)
- [System Architecture](#-system-architecture)
- [Project Status & Roadmap](#-project-status--roadmap)
- [Getting Started](#-getting-started)
- [Project Structure](#-project-structure)
- [Contributors](#-contributors)
- [Contributing](#-contributing)
- [License](#-license)

<br/>

## ✨ Features

<table>
<tr>
<td width="50%" valign="top">

**🔐 Accounts & Access**
- Secure registration & login (`register.php`, `login1.php`)
- Session-based auth with clean logout flow
- Dedicated admin panel for platform oversight
- Teacher profile pages, separate from student accounts

**📚 Resource Management**
- Upload notes, papers & academic resources
- Structured storage validated server-side
- One-click download of shared materials
- Delete/manage previously uploaded material

</td>
<td width="50%" valign="top">

**🔎 Discovery & Organization**
- Cascading filters: University → Department → Course → Subject
- Dynamic AJAX-driven dropdowns for fast browsing
- Browse teachers by university (`university_teachers.php`)

**⭐ Personalization**
- Favorite individual materials for quick access later
- Favorite entire universities to follow their content
- Personal dashboard summarizing your activity

</td>
</tr>
</table>

<br/>

## 🛠 Tech Stack

<div align="center">

![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Sessions](https://img.shields.io/badge/Security-Sessions%20%26%20Prepared%20Statements-2ea44f?style=for-the-badge&logo=letsencrypt&logoColor=white)

</div>

| Layer | Choice | Why |
|---|---|---|
| Frontend | HTML, CSS, JavaScript | Lightweight, zero build step, fast to iterate on |
| Backend | PHP | Simple deployment, tight MySQL integration |
| Database | MySQL | Relational structure fits University/Dept/Course/Subject hierarchy well |
| Security | Sessions + prepared statements | Session-based auth, SQL-injection-safe queries |

<br/>

## 🏗 System Architecture

```mermaid
flowchart TD
    A["👤 Student / Teacher"] --> B["🔐 Auth Layer<br/>login1.php · register.php · logout.php"]
    B --> C["🏠 Homepage / Dashboard<br/>homepage.php · mainhome.php · dashboard.php"]
    C --> D["🔎 Filter Engine<br/>get_universities · get_departments · get_courses · get_subjects"]
    C --> E["📤 Upload Flow<br/>upload.php → process_upload.php"]
    C --> F["📥 Resource Access<br/>download.php · delete_material.php"]
    C --> G["⭐ Favorites<br/>toggle_favorite.php · toggle_university_favorite.php"]
    C --> H["🧑‍🏫 Teacher Views<br/>teacher_profile.php · university_teachers.php"]
    D --> I[("🗄 MySQL Database<br/>project.sql")]
    E --> I
    F --> I
    G --> I
    H --> I
    C --> J["🛡 Admin Panel<br/>admin.php"]
    J --> I

    style A fill:#667eea,color:#fff
    style I fill:#764ba2,color:#fff
    style J fill:#f7b731,color:#1a1b27
```

<br/>

## 📊 Project Status & Roadmap

> Snapshot as of the handoff to full-stack collaboration — update the checkmarks as work lands.

```mermaid
pie showData
    title Feature Area Completion (edit me as work progresses)
    "Shipped" : 60
    "In Progress" : 15
    "Planned" : 25
```

### ✅ Shipped (built by [Rishav](https://github.com/rishav-84ya))
- [x] Auth: register, login, logout, sessions
- [x] Upload / download / delete flow for materials
- [x] University → Department → Course → Subject filter chain
- [x] Favorites for materials & universities
- [x] Admin panel, dashboard, teacher profile pages
- [x] Core MySQL schema (`project.sql`)

### 🚧 In Progress
- [ ] UI/UX overhaul — modern, consistent design system
- [ ] Input validation & error-handling pass across all PHP endpoints
- [ ] Responsive layout for mobile devices

### 🗺 Planned / Future Improvements
- [ ] Migrate raw SQL to prepared statements everywhere (security hardening pass)
- [ ] File preview before download (PDF/image inline viewer)
- [ ] Search bar with full-text search across materials
- [ ] Rating & review system for shared resources
- [ ] Email verification & password reset flow
- [ ] Notifications for new uploads in favorited universities
- [ ] REST API layer for a future mobile app
- [ ] Migrate to a PHP framework (Laravel) for maintainability
- [ ] Docker setup for one-command local environment
- [ ] Automated tests (PHPUnit) + CI pipeline

<sub>💡 This section is meant to evolve — check items off as they ship and add new ones as scope grows.</sub>

<br/>

## 🚀 Getting Started

### Prerequisites
- PHP 7.4+ with a local server (XAMPP / WAMP / MAMP, or built-in `php -S`)
- MySQL 5.7+ / MariaDB
- A browser

### Installation

```bash
# 1. Clone the repo
git clone https://github.com/Crusty-chirayu/EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform.git
cd EDU-SHARE-A-Targeted-Knowledge-Exchange-Platform

# 2. Import the database schema
mysql -u root -p < project.sql

# 3. Configure your database credentials
# open db_connect.php and set host / username / password / db name

# 4. Serve the app
php -S localhost:8000
```

Then open `http://localhost:8000/homepage.php` in your browser. 🎉

<br/>

## 📁 Project Structure

```
EDU-SHARE/
├── admin.php                      # Admin panel
├── dashboard.php                  # User dashboard
├── db_connect.php                 # MySQL connection config
├── delete_material.php            # Remove uploaded material
├── download.php                   # Download handler
├── favorites.php                  # Favorited materials view
├── get_courses.php                # AJAX: courses by department
├── get_departments.php            # AJAX: departments by university
├── get_subjects.php               # AJAX: subjects by course
├── get_universities.php           # AJAX: university list
├── homepage.php / mainhome.php    # Landing / main views
├── lib.php                        # Shared helper functions
├── login1.php / register.php      # Auth
├── logout.php                     # Session termination
├── process_upload.php             # Upload handler
├── project.sql                    # Database schema
├── teacher_profile.php            # Teacher profile page
├── toggle_favorite.php            # Favorite/unfavorite material
├── toggle_university_favorite.php # Favorite/unfavorite university
├── university_teachers.php        # Teachers by university
└── upload.php                     # Upload form
```

<br/>

## 👥 Contributors

<div align="center">

<table>
<tr>
<td align="center" width="50%">
<a href="https://github.com/rishav-84ya">
<img src="https://github.com/rishav-84ya.png" width="120px;" style="border-radius:50%;" alt="Rishav Chaurasiya"/>
<br /><sub><b>Rishav Chaurasiya</b></sub>
</a>
<br/>
<sub>🏗️ Original Creator & Backend Architect</sub>
<br/>
<sub>Designed and built EDU-SHARE from the ground up — auth, resource pipeline, filtering system, and database schema.</sub>
<br/><br/>
<a href="https://github.com/rishav-84ya"><img src="https://img.shields.io/badge/GitHub-rishav--84ya-181717?style=flat-square&logo=github"/></a>
</td>
<td align="center" width="50%">
<a href="https://github.com/Crusty-chirayu">
<img src="https://github.com/Crusty-chirayu.png" width="120px;" style="border-radius:50%;" alt="Chirayu"/>
<br /><sub><b>Chirayu</b></sub>
</a>
<br/>
<sub>💻 Full-Stack Developer</sub>
<br/>
<sub>Joining the project to drive full-stack development going forward — UI/UX, feature builds, hardening, and everything in between.</sub>
<br/><br/>
<a href="https://github.com/Crusty-chirayu"><img src="https://img.shields.io/badge/GitHub-Crusty--chirayu-181717?style=flat-square&logo=github"/></a>
</td>
</tr>
</table>

</div>

<br/>

## 🤝 Contributing

Contributions are what make open academic tooling like this thrive. To propose a change:

1. Fork the repo
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

Please open an issue first for larger changes so we can discuss direction before you sink time into it.

<br/>

## 📄 License

Distributed under the MIT License. See `LICENSE` for details.

<br/>

<div align="center">
<img src="https://capsule-render.vercel.app/api?type=waving&color=0:764ba2,100:667eea&height=120&section=footer" width="100%"/>

**⭐ Star this repo if EDU-SHARE is useful to you — it helps other students find it too.**
</div>
