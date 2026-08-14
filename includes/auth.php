<?php
declare(strict_types=1);

const APP_ROLES = ['student', 'teacher', 'moderator', 'admin'];

function reset_auth_cache(): void
{
    $GLOBALS['_auth_user_loaded'] = false;
    $GLOBALS['_auth_user'] = null;
}

function auth_user(): ?array
{
    if (($GLOBALS['_auth_user_loaded'] ?? false) === true) {
        return $GLOBALS['_auth_user'] ?? null;
    }

    $GLOBALS['_auth_user_loaded'] = true;
    $userId = positive_int($_SESSION['user_id'] ?? null);
    if ($userId === null) {
        $GLOBALS['_auth_user'] = null;
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, full_name, gmail, user_type, university_id, department_id, branch, year, contact, address
         FROM users WHERE id = ? LIMIT 1'
    );
    $statement->bind_param('i', $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;

    if ($user === null || !in_array($user['user_type'], APP_ROLES, true)) {
        unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['full_name']);
        $GLOBALS['_auth_user'] = null;
        return null;
    }

    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['full_name'] = $user['full_name'];
    $GLOBALS['_auth_user'] = $user;
    return $user;
}

function require_auth(bool $api = false): array
{
    $user = auth_user();
    if ($user !== null) {
        return $user;
    }

    if ($api || wants_json()) {
        abort_request(401, 'Authentication is required.');
    }

    flash('error', 'Please log in to continue.');
    redirect('login1.php', 302);
}

function role_can(?string $role, string $ability): bool
{
    $matrix = [
        'upload_resource' => ['teacher', 'moderator', 'admin'],
        'upload_material' => ['teacher', 'moderator', 'admin'], // legacy route ability alias
        'manage_academics' => ['admin'],
        'moderate_resources' => ['moderator', 'admin'],
        'moderate_materials' => ['moderator', 'admin'], // compatibility alias
        'delete_any_resource' => ['moderator', 'admin'],
        'delete_any_material' => ['moderator', 'admin'], // compatibility alias
    ];

    return $role !== null
        && isset($matrix[$ability])
        && in_array($role, $matrix[$ability], true);
}

function require_ability(string $ability, bool $api = false): array
{
    $user = require_auth($api);
    if (!role_can($user['user_type'], $ability)) {
        abort_request(403, 'You do not have permission to perform this action.');
    }
    return $user;
}

function can_view_resource(array $resource, ?array $user): bool
{
    return resource_access_policy()->canView($resource, $user);
}

function can_delete_resource(array $resource, array $user): bool
{
    return resource_access_policy()->canDelete($resource, $user);
}

function can_add_resource_version(array $resource, array $user): bool
{
    return resource_access_policy()->canAddVersion($resource, $user);
}

function can_update_resource(array $resource, array $user): bool
{
    return resource_access_policy()->canUpdate($resource, $user);
}

/** @deprecated P1.2 authorization uses can_view_resource(). */
function can_view_material(array $material, ?array $user): bool
{
    if (isset($material['owner_id'])) {
        return can_view_resource($material, $user);
    }
    $resourceShape = $material;
    $resourceShape['owner_id'] = $material['user_id'] ?? 0;
    $resourceShape['publication_status'] = ($material['status'] ?? 'pending') === 'published'
        ? 'published' : 'draft';
    $resourceShape['moderation_status'] = ($material['status'] ?? 'pending') === 'published'
        ? 'approved' : 'pending';
    $resourceShape['deletion_status'] = 'active';
    return can_view_resource($resourceShape, $user);
}

/** @deprecated P1.2 authorization uses can_delete_resource(). */
function can_delete_material(array $material, array $user): bool
{
    if (!isset($material['owner_id'])) {
        $material['owner_id'] = $material['user_id'] ?? 0;
        $material['deletion_status'] = 'active';
    }
    return can_delete_resource($material, $user);
}
