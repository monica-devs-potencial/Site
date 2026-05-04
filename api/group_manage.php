<?php
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrfToken();

$action  = trim($_POST['action'] ?? '');
$groupId = (int)($_POST['group_id'] ?? 0);
$db      = getDB();
$myId    = (int)$_SESSION['user_id'];

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'group_id inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get my role in this group
$roleStmt = $db->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
$roleStmt->execute([$groupId, $myId]);
$myRole = $roleStmt->fetchColumn();

if ($myRole === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Você não pertence a este grupo'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isGroupAdmin = ($myRole === 'admin');

// ── leave ─────────────────────────────────────────────────────────────────────
if ($action === 'leave') {
    $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $myId]);

    // If the leaving user was admin, promote someone else if group is not empty
    if ($isGroupAdmin) {
        $nextAdmin = $db->prepare(
            "SELECT user_id FROM group_members WHERE group_id = ? ORDER BY joined_at ASC LIMIT 1"
        );
        $nextAdmin->execute([$groupId]);
        $nextId = $nextAdmin->fetchColumn();
        if ($nextId) {
            $db->prepare("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?")->execute([$groupId, $nextId]);
        } else {
            // Last member left — delete the group entirely
            $db->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([$groupId]);
            $db->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$groupId]);
        }
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── rename ────────────────────────────────────────────────────────────────────
if ($action === 'rename') {
    if (!$isGroupAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas administradores podem renomear o grupo'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->prepare("UPDATE chat_groups SET name = ? WHERE id = ?")->execute([$name, $groupId]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── delete group ──────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$isGroupAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas administradores podem excluir o grupo'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([$groupId]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── add member ────────────────────────────────────────────────────────────────
if ($action === 'add_member') {
    if (!$isGroupAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas administradores podem adicionar membros'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Usuário inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $uStmt->execute([$username]);
    $uid = $uStmt->fetchColumn();
    if (!$uid) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
       ->execute([$groupId, $uid]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── remove member ─────────────────────────────────────────────────────────────
if ($action === 'remove_member') {
    if (!$isGroupAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas administradores podem remover membros'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId === $myId) {
        http_response_code(400);
        echo json_encode(['error' => 'Use "sair do grupo" para remover a si mesmo'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $targetId]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── set role (admin / member) ─────────────────────────────────────────────────
if ($action === 'set_role') {
    if (!$isGroupAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas administradores podem alterar papéis'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $targetId = (int)($_POST['user_id'] ?? 0);
    $role     = trim($_POST['role'] ?? '');
    if (!in_array($role, ['admin', 'member'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Papel inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Prevent demoting self if only admin
    if ($targetId === $myId && $role === 'member') {
        $adminCount = $db->prepare(
            "SELECT COUNT(*) FROM group_members WHERE group_id = ? AND role = 'admin'"
        );
        $adminCount->execute([$groupId]);
        if ((int)$adminCount->fetchColumn() <= 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Promova outro membro a admin antes de rebaixar a si mesmo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $db->prepare("UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?")
       ->execute([$role, $groupId, $targetId]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
