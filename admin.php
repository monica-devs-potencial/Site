<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

requireLogin();

// Only the "Flamengo" super-admin may access this panel
if ($_SESSION['username'] !== 'Flamengo') {
    http_response_code(403);
    exit('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Acesso negado</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:500px;margin:6rem auto;text-align:center;color:#1e293b}'
        . '.btn{display:inline-block;margin-top:1.5rem;padding:.6rem 1.4rem;background:#00a884;color:#fff;border-radius:8px;text-decoration:none}</style></head>'
        . '<body><h1>🚫 Acesso negado</h1><p>Esta página é restrita ao administrador do site.</p>'
        . '<a href="index.php" class="btn">← Voltar ao chat</a></body></html>');
}

$db  = getDB();
$msg = '';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // Register a new user
    if ($action === 'register_user') {
        $newUser   = trim($_POST['new_username'] ?? '');
        $newEmail  = trim($_POST['new_email']    ?? '');
        $newPass   = $_POST['new_password']     ?? '';
        $newDN     = trim($_POST['new_display_name'] ?? '');
        $newAdmin  = (int)($_POST['new_is_admin'] ?? 0);

        if ($newUser === '' || $newEmail === '' || $newPass === '') {
            $msg = '❌ Preencha usuário, e-mail e senha.';
        } elseif (mb_strlen($newUser) < 3 || mb_strlen($newUser) > 20) {
            $msg = '❌ O nome de usuário deve ter entre 3 e 20 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $newUser)) {
            $msg = '❌ O nome de usuário só pode conter letras, números e _.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $msg = '❌ E-mail inválido.';
        } elseif (mb_strlen($newPass) < 6) {
            $msg = '❌ A senha deve ter pelo menos 6 caracteres.';
        } else {
            $chk = $db->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$newUser]);
            if ($chk->fetch()) {
                $msg = '❌ Nome de usuário já existe.';
            } else {
                $chk2 = $db->prepare("SELECT id FROM users WHERE email = ?");
                $chk2->execute([$newEmail]);
                if ($chk2->fetch()) {
                    $msg = '❌ E-mail já cadastrado.';
                } else {
                    $db->prepare(
                        "INSERT INTO users (username, password, email, display_name, is_admin)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([
                        $newUser,
                        password_hash($newPass, PASSWORD_DEFAULT),
                        $newEmail,
                        $newDN !== '' ? $newDN : null,
                        $newAdmin,
                    ]);
                    $msg = '✅ Usuário ' . htmlspecialchars($newUser) . ' cadastrado com sucesso.';
                }
            }
        }
    }

    // Toggle user role
    if ($action === 'toggle_role') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = (int)($_POST['is_admin'] ?? 0);
        if ($uid > 0) {
            $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$role, $uid]);
            // Sync session if the current user's own role was changed (edge case)
            $msg = 'Papel atualizado.';
        }
    }

    // Delete user (cannot delete Flamengo)
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $checkStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $checkStmt->execute([$uid]);
            $uname = $checkStmt->fetchColumn();
            if ($uname === 'Flamengo') {
                $msg = '⛔ Não é possível excluir o administrador principal.';
            } else {
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
                $msg = 'Usuário excluído.';
            }
        }
    }

    // Delete any single message by table
    foreach (['messages', 'private_messages', 'group_messages'] as $table) {
        if ($action === 'delete_' . $table) {
            $mid = (int)($_POST['msg_id'] ?? 0);
            if ($mid > 0) {
                $db->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$mid]);
                $msg = 'Mensagem excluída.';
            }
        }
    }

    // Delete group
    if ($action === 'delete_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid > 0) {
            $db->prepare("DELETE FROM group_messages WHERE group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM group_members  WHERE group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM chat_groups    WHERE id = ?")->execute([$gid]);
            $msg = 'Grupo e mensagens excluídos.';
        }
    }

    // Edit a message content
    foreach (['messages', 'private_messages', 'group_messages'] as $table) {
        if ($action === 'edit_' . $table) {
            $mid     = (int)($_POST['msg_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            if ($mid > 0) {
                $db->prepare("UPDATE `$table` SET content = ?, edited_at = NOW() WHERE id = ?")
                   ->execute([$content, $mid]);
                $msg = 'Mensagem editada.';
            }
        }
    }

    header('Location: admin.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// ── Data for display ──────────────────────────────────────────────────────────

$users = $db->query(
    "SELECT id, username, display_name, email, is_admin, last_seen, created_at
     FROM users ORDER BY created_at ASC"
)->fetchAll();

// Online = last_seen within past 5 minutes
$onlineUsers = $db->query(
    "SELECT id FROM users WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
)->fetchAll(PDO::FETCH_COLUMN);
$onlineSet = array_flip($onlineUsers);

$publicMsgs = $db->query(
    "SELECT m.id, m.username, m.content, m.image_path, m.audio_path,
            m.deleted_at, m.edited_at, m.created_at
     FROM messages m ORDER BY m.id DESC LIMIT 200"
)->fetchAll();

$privateMsgs = $db->query(
    "SELECT pm.id, uf.username AS from_user, ut.username AS to_user,
            pm.content, pm.image_path, pm.audio_path,
            pm.deleted_at, pm.edited_at, pm.created_at
     FROM private_messages pm
     JOIN users uf ON uf.id = pm.from_user_id
     JOIN users ut ON ut.id = pm.to_user_id
     ORDER BY pm.id DESC LIMIT 200"
)->fetchAll();

$groups = $db->query(
    "SELECT g.id, g.name, g.created_at,
            u.username AS created_by,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
            (SELECT COUNT(*) FROM group_messages gmsg WHERE gmsg.group_id = g.id) AS msg_count
     FROM chat_groups g
     JOIN users u ON u.id = g.created_by
     ORDER BY g.created_at DESC"
)->fetchAll();

$groupMsgs = $db->query(
    "SELECT gm.id, g.name AS group_name, u.username,
            gm.content, gm.image_path, gm.audio_path,
            gm.deleted_at, gm.edited_at, gm.created_at
     FROM group_messages gm
     JOIN chat_groups g ON g.id = gm.group_id
     JOIN users u ON u.id = gm.from_user_id
     ORDER BY gm.id DESC LIMIT 200"
)->fetchAll();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin – Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body class="admin-page">

<header class="admin-header">
    <span class="admin-header-title">⚙️ Painel de Administração</span>
    <span class="admin-header-sub">Logado como <strong>Flamengo</strong></span>
    <div class="admin-header-actions">
        <a href="index.php" class="btn btn-outline admin-back-btn">← Voltar ao chat</a>
        <form method="post" action="logout.php" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <button type="submit" class="btn btn-outline admin-logout-btn">Sair</button>
        </form>
    </div>
</header>

<?php if ($msg): ?>
    <div class="admin-flash"><?= $msg ?></div>
<?php endif; ?>

<main class="admin-main">

    <!-- ── Tabs ── -->
    <nav class="admin-tabs" role="tablist">
        <button class="admin-tab active" data-tab="users">👥 Usuários (<?= count($users) ?>)</button>
        <button class="admin-tab" data-tab="public">💬 Msgs Públicas (<?= count($publicMsgs) ?>)</button>
        <button class="admin-tab" data-tab="private">🔒 Msgs Privadas (<?= count($privateMsgs) ?>)</button>
        <button class="admin-tab" data-tab="groups">👥 Grupos (<?= count($groups) ?>)</button>
        <button class="admin-tab" data-tab="groupmsgs">📨 Msgs de Grupos (<?= count($groupMsgs) ?>)</button>
        <button class="admin-tab" data-tab="maintenance">🧹 Manutenção</button>
    </nav>

    <!-- ── Maintenance / cleanup ── -->
    <section class="admin-section" id="tab-maintenance" hidden>
        <div class="admin-register-box">
            <h3 class="admin-section-title">🧹 Limpeza de Mensagens Antigas</h3>
            <p style="font-size:.88rem;color:var(--wa-muted);margin-bottom:1rem">
                Remove mensagens apagadas há mais de 7 dias, mensagens com mais de
                <strong><?= MESSAGE_MAX_DAYS ?> dias</strong> de idade,
                e mantém no máximo <strong><?= number_format(MESSAGE_MAX_PUBLIC) ?></strong> mensagens públicas,
                <strong><?= number_format(MESSAGE_MAX_DM) ?></strong> por conversa privada e
                <strong><?= number_format(MESSAGE_MAX_GROUP) ?></strong> por grupo.
                A limpeza também acontece automaticamente em segundo plano a cada ~50 mensagens enviadas.
            </p>
            <div id="cleanup-result" class="admin-flash" style="display:none"></div>
            <button type="button" id="cleanup-btn" class="btn btn-primary" style="min-width:180px">
                🧹 Limpar agora
            </button>
        </div>
    </section>

    <!-- ── Users ── -->
    <section class="admin-section" id="tab-users">

        <!-- Register new user -->
        <div class="admin-register-box">
            <h3 class="admin-section-title">➕ Cadastrar Novo Usuário</h3>
            <form method="post" class="admin-register-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="register_user">
                <div class="admin-register-row">
                    <div class="form-group">
                        <label for="new-username">Usuário *</label>
                        <input type="text" id="new-username" name="new_username"
                               placeholder="3–20 chars, letras/números/_" maxlength="20" required>
                    </div>
                    <div class="form-group">
                        <label for="new-display-name">Nome Completo</label>
                        <input type="text" id="new-display-name" name="new_display_name"
                               placeholder="Opcional" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="new-email">E-mail *</label>
                        <input type="email" id="new-email" name="new_email"
                               placeholder="seu@email.com" required>
                    </div>
                    <div class="form-group">
                        <label for="new-password">Senha *</label>
                        <input type="password" id="new-password" name="new_password"
                               placeholder="Mínimo 6 caracteres" required>
                    </div>
                    <div class="form-group">
                        <label for="new-is-admin">Papel</label>
                        <select id="new-is-admin" name="new_is_admin">
                            <option value="0">👤 Usuário</option>
                            <option value="1">👑 Admin</option>
                        </select>
                    </div>
                    <div class="form-group form-group--btn">
                        <button type="submit" class="btn btn-primary">Cadastrar</button>
                    </div>
                </div>
            </form>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th><th>Usuário</th><th>Nome</th><th>E-mail</th>
                    <th>Papel</th><th>Online</th><th>Cadastro</th><th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td>
                        <?php if ($u['username'] !== 'Flamengo'): ?>
                        <form method="post" class="admin-inline-form">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_role">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <?php if ($u['is_admin']): ?>
                                <input type="hidden" name="is_admin" value="0">
                                <button type="submit" class="btn-role admin-role">👑 Admin</button>
                            <?php else: ?>
                                <input type="hidden" name="is_admin" value="1">
                                <button type="submit" class="btn-role user-role">👤 Usuário</button>
                            <?php endif; ?>
                        </form>
                        <?php else: ?>
                            <span class="badge-superadmin">🔱 Super Admin</span>
                        <?php endif; ?>
                    </td>
                    <td><?= isset($onlineSet[$u['id']]) ? '<span class="dot-online" title="Online agora">🟢</span>' : '<span title="Offline">⚫</span>' ?></td>
                    <td><?= htmlspecialchars(substr($u['created_at'], 0, 16)) ?></td>
                    <td>
                        <?php if ($u['username'] !== 'Flamengo'): ?>
                        <form method="post" class="admin-inline-form"
                              onsubmit="return confirm('Excluir usuário <?= htmlspecialchars(addslashes($u['username'])) ?>? Esta ação é irreversível.')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn-danger-sm">🗑️ Excluir</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- ── Public messages ── -->
    <section class="admin-section" id="tab-public" hidden>
        <?php if (!$publicMsgs): ?>
            <p class="admin-empty">Nenhuma mensagem.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Usuário</th><th>Conteúdo</th><th>Criado em</th><th>Status</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($publicMsgs as $m): ?>
                <tr class="<?= $m['deleted_at'] ? 'row-deleted' : '' ?>">
                    <td><?= $m['id'] ?></td>
                    <td><?= htmlspecialchars($m['username']) ?></td>
                    <td class="admin-msg-content">
                        <?php if ($m['image_path']): ?><em>[imagem: <?= htmlspecialchars($m['image_path']) ?>]</em><?php endif; ?>
                        <?php if ($m['audio_path']): ?><em>[áudio]</em><?php endif; ?>
                        <span class="msg-text"><?= htmlspecialchars(mb_strimwidth($m['content'], 0, 120, '…')) ?></span>
                    </td>
                    <td><?= htmlspecialchars(substr($m['created_at'], 0, 16)) ?></td>
                    <td><?= $m['deleted_at'] ? '<span class="badge-deleted">Apagado</span>' : ($m['edited_at'] ? '<span class="badge-edited">Editado</span>' : '—') ?></td>
                    <td class="admin-actions-cell">
                        <?php if (!$m['deleted_at']): ?>
                        <button type="button" class="btn-edit-sm"
                                onclick="openEditModal('messages',<?= $m['id'] ?>,<?= json_encode($m['content']) ?>)">✏️</button>
                        <?php endif; ?>
                        <form method="post" class="admin-inline-form"
                              onsubmit="return confirm('Excluir mensagem #<?= $m['id'] ?>?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_messages">
                            <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn-danger-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- ── Private messages ── -->
    <section class="admin-section" id="tab-private" hidden>
        <?php if (!$privateMsgs): ?>
            <p class="admin-empty">Nenhuma mensagem privada.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>De</th><th>Para</th><th>Conteúdo</th><th>Criado em</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($privateMsgs as $m): ?>
                <tr class="<?= $m['deleted_at'] ? 'row-deleted' : '' ?>">
                    <td><?= $m['id'] ?></td>
                    <td><?= htmlspecialchars($m['from_user']) ?></td>
                    <td><?= htmlspecialchars($m['to_user']) ?></td>
                    <td class="admin-msg-content">
                        <?php if ($m['image_path']): ?><em>[imagem]</em><?php endif; ?>
                        <?php if ($m['audio_path']): ?><em>[áudio]</em><?php endif; ?>
                        <span class="msg-text"><?= htmlspecialchars(mb_strimwidth($m['content'], 0, 120, '…')) ?></span>
                    </td>
                    <td><?= htmlspecialchars(substr($m['created_at'], 0, 16)) ?></td>
                    <td class="admin-actions-cell">
                        <?php if (!$m['deleted_at']): ?>
                        <button type="button" class="btn-edit-sm"
                                onclick="openEditModal('private_messages',<?= $m['id'] ?>,<?= json_encode($m['content']) ?>)">✏️</button>
                        <?php endif; ?>
                        <form method="post" class="admin-inline-form"
                              onsubmit="return confirm('Excluir mensagem #<?= $m['id'] ?>?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_private_messages">
                            <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn-danger-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- ── Groups ── -->
    <section class="admin-section" id="tab-groups" hidden>
        <?php if (!$groups): ?>
            <p class="admin-empty">Nenhum grupo criado.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Nome</th><th>Criado por</th><th>Membros</th><th>Msgs</th><th>Criado em</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><?= $g['id'] ?></td>
                    <td><?= htmlspecialchars($g['name']) ?></td>
                    <td><?= htmlspecialchars($g['created_by']) ?></td>
                    <td><?= $g['member_count'] ?></td>
                    <td><?= $g['msg_count'] ?></td>
                    <td><?= htmlspecialchars(substr($g['created_at'], 0, 16)) ?></td>
                    <td>
                        <form method="post" class="admin-inline-form"
                              onsubmit="return confirm('Excluir grupo «<?= htmlspecialchars(addslashes($g['name'])) ?>» e todas suas mensagens?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                            <button type="submit" class="btn-danger-sm">🗑️ Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- ── Group messages ── -->
    <section class="admin-section" id="tab-groupmsgs" hidden>
        <?php if (!$groupMsgs): ?>
            <p class="admin-empty">Nenhuma mensagem de grupo.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Grupo</th><th>Usuário</th><th>Conteúdo</th><th>Criado em</th><th>Status</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($groupMsgs as $m): ?>
                <tr class="<?= $m['deleted_at'] ? 'row-deleted' : '' ?>">
                    <td><?= $m['id'] ?></td>
                    <td><?= htmlspecialchars($m['group_name']) ?></td>
                    <td><?= htmlspecialchars($m['username']) ?></td>
                    <td class="admin-msg-content">
                        <?php if ($m['image_path']): ?><em>[imagem]</em><?php endif; ?>
                        <?php if ($m['audio_path']): ?><em>[áudio]</em><?php endif; ?>
                        <span class="msg-text"><?= htmlspecialchars(mb_strimwidth($m['content'], 0, 120, '…')) ?></span>
                    </td>
                    <td><?= htmlspecialchars(substr($m['created_at'], 0, 16)) ?></td>
                    <td><?= $m['deleted_at'] ? '<span class="badge-deleted">Apagado</span>' : ($m['edited_at'] ? '<span class="badge-edited">Editado</span>' : '—') ?></td>
                    <td class="admin-actions-cell">
                        <?php if (!$m['deleted_at']): ?>
                        <button type="button" class="btn-edit-sm"
                                onclick="openEditModal('group_messages',<?= $m['id'] ?>,<?= json_encode($m['content']) ?>)">✏️</button>
                        <?php endif; ?>
                        <form method="post" class="admin-inline-form"
                              onsubmit="return confirm('Excluir mensagem #<?= $m['id'] ?>?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_group_messages">
                            <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn-danger-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

</main>

<!-- Edit message modal -->
<div id="admin-edit-modal" class="admin-modal" hidden>
    <div class="admin-modal-backdrop" id="admin-modal-backdrop"></div>
    <div class="admin-modal-box">
        <button type="button" id="admin-modal-close" class="admin-modal-close">✕</button>
        <div class="admin-modal-title">✏️ Editar mensagem</div>
        <form method="post" id="admin-edit-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="admin-edit-action">
            <input type="hidden" name="msg_id" id="admin-edit-msg-id">
            <textarea name="content" id="admin-edit-content" class="admin-edit-textarea" rows="5"></textarea>
            <div class="admin-modal-actions">
                <button type="submit" class="btn btn-primary">💾 Salvar</button>
                <button type="button" id="admin-modal-cancel" class="btn btn-outline">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.admin-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.admin-tab').forEach(function (b) { b.classList.remove('active'); });
        document.querySelectorAll('.admin-section').forEach(function (s) { s.hidden = true; });
        btn.classList.add('active');
        const tab = document.getElementById('tab-' + btn.dataset.tab);
        if (tab) tab.hidden = false;
    });
});

// Edit modal
const editModal    = document.getElementById('admin-edit-modal');
const editAction   = document.getElementById('admin-edit-action');
const editMsgId    = document.getElementById('admin-edit-msg-id');
const editContent  = document.getElementById('admin-edit-content');
const editBackdrop = document.getElementById('admin-modal-backdrop');
const editClose    = document.getElementById('admin-modal-close');
const editCancel   = document.getElementById('admin-modal-cancel');

function openEditModal(table, id, content) {
    editAction.value  = 'edit_' + table;
    editMsgId.value   = id;
    editContent.value = content;
    editModal.hidden  = false;
    editContent.focus();
}

function closeEditModal() { editModal.hidden = true; }

if (editBackdrop) editBackdrop.addEventListener('click', closeEditModal);
if (editClose)    editClose.addEventListener('click', closeEditModal);
if (editCancel)   editCancel.addEventListener('click', closeEditModal);
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && editModal && !editModal.hidden) closeEditModal();
});

// Cleanup button
const cleanupBtn    = document.getElementById('cleanup-btn');
const cleanupResult = document.getElementById('cleanup-result');
const csrfMeta      = <?= json_encode(generateCsrfToken()) ?>;

if (cleanupBtn) {
    cleanupBtn.addEventListener('click', function () {
        cleanupBtn.disabled = true;
        cleanupBtn.textContent = '⏳ Limpando…';
        if (cleanupResult) { cleanupResult.style.display = 'none'; }

        fetch('api/cleanup.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfMeta, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfMeta),
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (cleanupResult) {
                cleanupResult.textContent = data.message || (data.error ? '❌ ' + data.error : 'Pronto.');
                cleanupResult.style.display = '';
            }
        })
        .catch(function () {
            if (cleanupResult) { cleanupResult.textContent = '❌ Erro de rede.'; cleanupResult.style.display = ''; }
        })
        .finally(function () {
            cleanupBtn.disabled = false;
            cleanupBtn.textContent = '🧹 Limpar agora';
        });
    });
}
</script>
</body>
</html>
