<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

render_header('Knowledge Exchange', 'home');
$user = auth_user();
?>
<main>
    <section class="bg-gradient-to-br from-slate-800 to-blue-600 text-white text-center py-20 px-4 shadow-xl">
        <h1 class="text-5xl md:text-6xl font-black mb-4">Share Knowledge, Empower Peers</h1>
        <p class="text-xl md:text-2xl font-light max-w-3xl mx-auto mb-8">
            Exchange study materials and resources organized for your university, department, course, and subject.
        </p>
        <a href="<?= h(app_url($user === null ? 'register.php' : 'dashboard.php')) ?>" class="inline-block bg-yellow-400 text-gray-900 font-extrabold text-lg py-3 px-8 rounded-full shadow-lg hover:bg-yellow-300">
            <?= $user === null ? 'Get started' : 'Go to dashboard' ?>
        </a>
    </section>
    <section class="max-w-7xl mx-auto py-16 px-4 grid grid-cols-1 md:grid-cols-3 gap-8">
        <article class="bg-white p-6 rounded-xl shadow-lg text-center">
            <div class="text-blue-600 text-4xl mb-3" aria-hidden="true">📘</div>
            <h2 class="text-xl font-bold mb-2">Targeted materials</h2>
            <p class="text-gray-600">Find notes and learning resources tied to a verified academic hierarchy.</p>
        </article>
        <article class="bg-white p-6 rounded-xl shadow-lg text-center">
            <div class="text-green-600 text-4xl mb-3" aria-hidden="true">👨‍🏫</div>
            <h2 class="text-xl font-bold mb-2">Contributor uploads</h2>
            <p class="text-gray-600">Teachers and approved platform roles can share carefully categorized resources.</p>
        </article>
        <article class="bg-white p-6 rounded-xl shadow-lg text-center">
            <div class="text-red-600 text-4xl mb-3" aria-hidden="true">🔒</div>
            <h2 class="text-xl font-bold mb-2">Private file delivery</h2>
            <p class="text-gray-600">Files are validated, stored outside the public web root, and served by resource ID.</p>
        </article>
    </section>
</main>
<?php render_footer(); ?>
