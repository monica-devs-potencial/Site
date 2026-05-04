<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db       = getDB();
$userStmt = $db->prepare(
    "SELECT username, display_name, email, avatar_path, created_at FROM users WHERE id = ?"
);
$userStmt->execute([$_SESSION['user_id']]);
$userRow = $userStmt->fetch();

$selfUsername    = htmlspecialchars($userRow['username'] ?? $_SESSION['username']);
$selfDisplayName = htmlspecialchars($userRow['display_name'] ?? '');
$selfEmail       = htmlspecialchars($userRow['email'] ?? '');
$selfAvatarUrl   = $userRow['avatar_path'] ? htmlspecialchars(AVATARS_URL . $userRow['avatar_path']) : '';
$selfInitial     = htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['username'], 0, 1)));
$selfMemberSince = $userRow['created_at'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <title>Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="chat-page">
<div class="wa-app">

    <!-- ── LEFT SIDEBAR ─────────────────────────────────────────────────── -->
    <aside class="wa-sidebar" id="wa-sidebar">

        <header class="wa-sidebar-header">
            <?php if ($selfAvatarUrl): ?>
                <button type="button" class="wa-self-avatar wa-self-avatar--btn" id="open-profile-btn"
                        title="Ver perfil">
                    <img src="<?= $selfAvatarUrl ?>" alt="Foto de <?= $selfUsername ?>" class="avatar-img">
                </button>
            <?php else: ?>
                <button type="button" class="wa-self-avatar wa-self-avatar--btn" id="open-profile-btn"
                        title="Ver perfil"><?= $selfInitial ?></button>
            <?php endif; ?>
              <span class="wa-sidebar-title"> 👤 Perfil</span>
            <!-- Music player toggle -->
            <button type="button" id="music-player-btn" class="wa-hdr-btn music-player-btn"
                    title="Minha playlist" aria-label="Abrir player de música">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                    <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
                </svg>
            </button>
            <?php if ($selfUsername === 'Flamengo'): ?>
            <a href="admin.php" class="wa-hdr-btn" title="Painel Admin" aria-label="Painel de administração">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                    <path d="M12 2a5 5 0 100 10A5 5 0 0012 2zm0 12c-5.33 0-8 2.67-8 4v2h16v-2c0-1.33-2.67-4-8-4z"/>
                </svg>
            </a>
            <?php endif; ?>
            <!-- Fullscreen toggle (desktop only) -->
            <button type="button" id="fullscreen-btn" class="wa-hdr-btn fullscreen-btn" title="Tela cheia" aria-label="Alternar tela cheia">
                <svg id="fullscreen-enter-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                    <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
                </svg>
                <svg id="fullscreen-exit-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" style="display:none">
                    <path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/>
                </svg>
            </button>
            <form method="post" action="logout.php" class="wa-logout-form" id="logout-form-header">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <button type="submit" class="wa-hdr-btn" title="Sair">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zm-14 5V5h8V3H3a2 2 0 00-2 2v14a2 2 0 002 2h8v-2H3v-7z"/>
                    </svg>
                </button>
            </form>
        </header>

        <!-- Mini music player bar (shown when playlist is active) -->
        <div id="music-player-bar" class="music-player-bar" hidden>
            <audio id="music-player-audio" preload="metadata"></audio>
            <div class="player-track-info">
                <span id="player-track-title" class="player-track-title">Sem músicas</span>
                <span id="player-track-artist" class="player-track-artist"></span>
            </div>
            <div class="player-controls">
                <button type="button" id="player-prev" class="player-btn" title="Anterior" aria-label="Música anterior">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
                        <path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/>
                    </svg>
                </button>
                <button type="button" id="player-play-pause" class="player-btn player-btn--main" title="Play/Pause" aria-label="Play ou Pause">
                    <svg id="player-play-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                    <svg id="player-pause-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" style="display:none">
                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                    </svg>
                </button>
                <button type="button" id="player-next" class="player-btn" title="Próxima" aria-label="Próxima música">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
                        <path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/>
                    </svg>
                </button>
                <span class="player-volume-wrap">
                    <button type="button" id="player-mute-btn" class="player-btn player-mute-btn" title="Mudo/Ativo" aria-label="Alternar mudo">
                        <svg id="player-vol-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
                            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/>
                        </svg>
                        <svg id="player-mute-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" style="display:none">
                            <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                        </svg>
                    </button>
                    <input type="range" id="player-volume" class="player-volume-slider" min="0" max="100" value="100" aria-label="Volume">
                </span>
            </div>
        </div>

        <!-- Scrollable sidebar content -->
        <div class="wa-sidebar-inner-scroll">

        <!-- Groups section -->
        <div class="wa-section-label">
            <span>👥 Grupos</span>
            <button type="button" id="new-group-btn" class="wa-section-action-btn" title="Criar novo grupo">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
                    <path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/>
                </svg>
                Novo Grupo
            </button>
        </div>
        <ul id="groups-list" class="wa-groups-list"></ul>

        <div class="wa-section-label">🟢 Conv ersas Privadas</div>

        <ul id="users-list" class="wa-users-list"></ul>

        </div><!-- /.wa-sidebar-inner-scroll -->

    </aside>

    <!-- ── RIGHT CHAT PANEL ─────────────────────────────────────────────── -->
    <section class="wa-panel" id="chat-panel">

        <!-- Chat header -->
        <header class="wa-panel-header">
            <!-- Back button (visible in DM or group mode) -->
            <button type="button" id="panel-back-btn" class="wa-hdr-btn panel-back-btn" title="Voltar" hidden>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                    
                </svg>
            </button>
            <!-- Mobile sidebar toggle (visible only on mobile) -->
            <button type="button" id="sidebar-toggle-btn" class="wa-hdr-btn sidebar-toggle-btn" title="Ver conversas">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
            </button>
          <div class="wa-panel-info">
                    
             <h3> <img src="celular-imagem.gif" alt=" celular-imagem " width="60" height="60">ZapGuys</h3>
               
                <div class="wa-panel-name" id="panel-name">Bem-vindo(a)</div>
                <div class="wa-panel-status" id="panel-status">Selecione uma conversa ou crie um grupo</div>
            </div>
          
      
            <div class="wa-panel-right">
                <!-- Group settings button (visible only in group_chat mode for admins) -->
                <button type="button" id="group-settings-btn" class="wa-hdr-btn" title="Configurações do grupo"
                        aria-label="Configurações do grupo" hidden>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                        <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.59-.22l-2.39.96a7.24 7.24 0 00-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87a.48.48 0 00.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 00-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                    </svg>
               
                </button>
              
                </div> 
       <h4> 
  <?php if ($selfAvatarUrl): ?>
                   
                    <button type="button" class="wa-panel-self-avatar wa-self-avatar--btn" id="open-profile-btn2"
                          
                            title="Ver perfil">
                            
                        <img src="<?= $selfAvatarUrl ?>" alt="Foto de <?= $selfUsername ?>" class="avatar-img">
                   
                    </button>

                <?php else: ?>
                    <button type="button" class="wa-panel-self-avatar wa-self-avatar--btn" id="open-profile-btn2"
                            title="Ver perfil"><?= $selfInitial ?> <img src=" seta-imagem.gif " alt=" seta-imagem.gif " width="30" height="30"> </button>
             <?php endif; ?> ...<img src=" seta-imagem.gif " alt=" seta-imagem.gif " width="20" height="9">
</h4>

        </header>
               



              
        <!-- Messages -->
        <div id="messages" class="wa-messages" hidden></div>

        <!-- Emoji picker (absolutely positioned above compose bar) -->
        <div id="emoji-picker" class="wa-emoji-picker" hidden>
            <div class="emoji-grid" id="emoji-grid"></div>
        </div>

        <!-- Audio recording bar (replaces compose while recording) -->
        <div id="audio-recording-wrap" class="compose-footer-bar" hidden>
            <button type="button" id="audio-cancel-btn" class="wa-compose-btn audio-cancel-btn"
                    title="Cancelar gravação" aria-label="Cancelar gravação">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            <span class="audio-rec-dot" aria-hidden="true"></span>
            <span class="audio-rec-label">Gravando</span>
            <span id="audio-rec-timer" class="audio-rec-timer">0:00</span>
            <button type="button" id="audio-send-btn" class="wa-send-btn" title="Enviar áudio" aria-label="Enviar áudio">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff" aria-hidden="true">
                    <path d="M2 21l21-9L2 3v7l15 2-15 2z"/>
                </svg>
            </button>
        </div>

        <!-- Compose bar (hidden until a conversation is selected) -->
        <form id="chat-form" class="wa-compose" enctype="multipart/form-data" hidden>

            <!-- Attachments zone — image chip and/or audio preview (hidden when empty) -->
            <div class="compose-attachments" id="compose-attachments" hidden>

                <!-- Image attachment chip -->
                <div id="image-preview-wrap" class="compose-attach-chip" hidden>
                    <img id="image-preview" src="" alt="preview" class="compose-attach-thumb">
                    <span class="compose-attach-label">Imagem selecionada</span>
                    <button type="button" id="image-remove" class="compose-attach-remove"
                            title="Remover imagem" aria-label="Remover imagem">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>

                <!-- General file attachment chip -->
                <div id="file-preview-wrap" class="compose-attach-chip compose-file-chip" hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="var(--wa-teal)" aria-hidden="true" style="flex-shrink:0">
                        <path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/>
                    </svg>
                    <span id="file-preview-name" class="compose-attach-label">Arquivo</span>
                    <button type="button" id="file-preview-remove" class="compose-attach-remove"
                            title="Remover arquivo" aria-label="Remover arquivo">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>
                <!-- Audio preview chip -->
                <div id="audio-preview-wrap" class="compose-attach-chip compose-audio-chip" hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="var(--wa-teal)" aria-hidden="true" style="flex-shrink:0">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"/>
                    </svg>
                    <audio id="audio-preview-player" class="compose-audio-player" controls></audio>
                    <button type="button" id="audio-preview-remove" class="compose-attach-remove"
                            title="Descartar áudio" aria-label="Descartar áudio">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>

            </div>

            <!-- Input row -->
            <div class="compose-input-row">

                <!-- Emoji toggle -->
                <button type="button" id="emoji-btn" class="wa-compose-btn" title="Emojis" aria-label="Emojis">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                        <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/>
                    </svg>
                </button>

                <!-- Attach image -->
                <button type="button" id="attach-image-btn" class="wa-compose-btn" title="Anexar imagem" aria-label="Anexar imagem">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                        <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                    </svg>
                </button>
                <input type="file" id="image-input" name="image" accept="image/*"
                       class="visually-hidden-input">

                <!-- Attach any file (audio, video, PDF, doc, …) -->
                <button type="button" id="attach-file-btn" class="wa-compose-btn" title="Anexar arquivo" aria-label="Anexar arquivo">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                        <path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/>
                    </svg>
                </button>
                <input type="file" id="file-input"
                       accept="audio/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z"
                       class="visually-hidden-input">

                <!-- Text input (Enter = newline, Ctrl/Cmd+Enter = send) -->
                <textarea
                    id="message-input"
                    class="wa-compose-input"
                    placeholder="Digite uma mensagem…"
                    maxlength="500"
                    autocomplete="off"
                    rows="1"
                ></textarea>

                <!-- Mic button (shown when no content) -->
                <button type="button" id="mic-btn" class="wa-send-btn mic-btn" title="Gravar áudio" aria-label="Gravar mensagem de voz">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff" aria-hidden="true">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93H2c0 4.42 3.27 8.12 7.5 8.77V21h1v-5.07c.17.02.33.07.5.07.17 0 .33-.05.5-.07V21h1v-4.23c4.23-.65 7.5-4.35 7.5-8.77h-2c0 4.08-3.06 7.44-7 7.93z"/>
                    </svg>
                </button>

                <!-- Send button (shown when there is content) -->
                <button type="submit" class="wa-send-btn send-btn" title="Enviar" aria-label="Enviar mensagem" hidden>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff" aria-hidden="true">
                        <path d="M2 21l21-9L2 3v7l15 2-15 2z"/>
                    </svg>
                </button>

            </div>
        </form>

    </section>
</div>

<!-- Mobile sidebar overlay -->
<div id="sidebar-overlay" class="sidebar-overlay" hidden></div>

<!-- Lightbox modal (opened when clicking a chat image) -->
<div id="lightbox" class="lightbox" hidden aria-modal="true" role="dialog" aria-label="Visualizar imagem">
    <button type="button" id="lightbox-close" class="lightbox-close" title="Fechar (Esc)">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
    </button>
    <img id="lightbox-img" src="" alt="Imagem ampliada" class="lightbox-img">
</div>

<!-- Profile modal -->
<div id="profile-modal" class="profile-modal" hidden role="dialog" aria-modal="true" aria-label="Perfil do usuário">
    <div class="profile-modal-backdrop" id="profile-modal-backdrop"></div>
    <div class="profile-modal-box">
        <button type="button" class="profile-modal-close" id="profile-modal-close" title="Fechar">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>

        <!-- VIEW MODE -->
        <div id="profile-view">
            <div class="profile-avatar-wrap" id="profile-avatar-wrap">
                <?php if ($selfAvatarUrl): ?>
                    <img src="<?= $selfAvatarUrl ?>" alt="Foto de <?= $selfUsername ?>" class="profile-avatar-img" id="profile-avatar-img">
                <?php else: ?>
                    <div class="profile-avatar-initial" id="profile-avatar-initial"><?= $selfInitial ?></div>
                <?php endif; ?>
            </div>
            <div class="profile-username" id="profile-username"><?= $selfUsername ?></div>
            <div class="profile-display-name" id="profile-view-display-name"><?= $selfDisplayName ?></div>
            <div class="profile-email" id="profile-view-email"><?= $selfEmail ? '✉️ ' . $selfEmail : '' ?></div>
            <?php if ($selfMemberSince): ?>
                <div class="profile-since">
                    📅 Membro desde <?= htmlspecialchars(
                        (new DateTime($selfMemberSince))->format('d/m/Y')
                    ) ?>
                </div>
            <?php endif; ?>
            <div class="profile-view-actions">
                <button type="button" id="profile-edit-btn" class="btn btn-primary profile-edit-open-btn">✏️ Editar perfil</button>
                <form method="post" action="logout.php" class="wa-logout-form">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-outline profile-logout-btn">Sair da conta</button>
                </form>
            </div>

            <!-- Playlist section (view mode) -->
            <div class="profile-playlist-section" id="profile-playlist-section">
                <div class="profile-section-title">🎵 Minha Playlist</div>
                <ul id="profile-playlist-list" class="profile-playlist-list">
                    <li class="playlist-empty-msg">Nenhuma música ainda</li>
                </ul>
                <form id="playlist-add-form" class="playlist-add-form" enctype="multipart/form-data">
                    <input type="text" id="playlist-title-input" class="playlist-text-input"
                           placeholder="Título da música *" maxlength="200" autocomplete="off">
                    <input type="text" id="playlist-artist-input" class="playlist-text-input"
                           placeholder="Artista (opcional)" maxlength="200" autocomplete="off">
                    <button type="button" id="playlist-audio-btn" class="btn btn-outline playlist-file-label" title="Selecionar arquivo de áudio">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
                        </svg>
                        <span id="playlist-file-name">Escolher áudio (MP3, M4A…)</span>
                    </button>
                    <input type="file" id="playlist-audio-file" accept="audio/*" class="visually-hidden-input">
                    <div id="playlist-add-msg" class="profile-edit-msg" hidden></div>
                    <button type="submit" id="playlist-add-btn" class="btn btn-primary">➕ Adicionar à playlist</button>
                </form>
            </div>
        </div>

        <!-- EDIT MODE -->
        <div id="profile-edit" hidden>
            <div class="profile-avatar-wrap profile-edit-avatar-wrap" id="profile-edit-avatar-wrap">
                <?php if ($selfAvatarUrl): ?>
                    <img src="<?= $selfAvatarUrl ?>" alt="Foto" class="profile-avatar-img" id="profile-edit-avatar-preview">
                <?php else: ?>
                    <div class="profile-avatar-initial" id="profile-edit-avatar-initial"><?= $selfInitial ?></div>
                <?php endif; ?>
                <label class="profile-avatar-change-btn" for="profile-avatar-input" title="Alterar foto">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                    </svg>
                    📷
                </label>
                <input type="file" id="profile-avatar-input" accept="image/*" class="visually-hidden-input">
            </div>
            <div id="profile-edit-msg" class="profile-edit-msg" hidden></div>
            <form id="profile-edit-form" class="profile-edit-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="profile-username-input">Nome de usuário</label>
                    <input type="text" id="profile-username-input" name="new_username"
                           value="<?= $selfUsername ?>"
                           placeholder="3–20 caracteres, letras/números/_" maxlength="20" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="profile-display-name-input">Nome</label>
                    <input type="text" id="profile-display-name-input" name="display_name"
                           value="<?= $selfDisplayName ?>"
                           placeholder="Como quer ser chamado(a)" maxlength="50">
                </div>
                <div class="form-group">
                    <label for="profile-email-input">E-mail</label>
                    <input type="email" id="profile-email-input" name="email"
                           value="<?= $selfEmail ?>"
                           placeholder="seu@email.com">
                </div>
                <input type="file" name="avatar" id="profile-form-avatar-input" class="visually-hidden-input" accept="image/*">

                <!-- Password change section -->
                <details class="profile-pw-section">
                    <summary class="profile-pw-summary">🔑 Alterar senha</summary>
                    <div class="profile-pw-fields">
                        <div class="form-group">
                            <label for="profile-cur-pw">Senha atual</label>
                            <input type="password" id="profile-cur-pw" name="current_password"
                                   placeholder="Digite sua senha atual" autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label for="profile-new-pw">Nova senha</label>
                            <input type="password" id="profile-new-pw" name="new_password"
                                   placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="profile-conf-pw">Confirmar nova senha</label>
                            <input type="password" id="profile-conf-pw" name="confirm_password"
                                   placeholder="Repita a nova senha" autocomplete="new-password">
                        </div>
                    </div>
                </details>

                <div class="profile-edit-actions">
                    <button type="submit" id="profile-save-btn" class="btn btn-primary">💾 Salvar</button>
                    <button type="button" id="profile-cancel-btn" class="btn btn-outline">Cancelar</button>
                </div>
            </form>
        </div>

    </div>
</div>

<!-- Group creation modal -->
<div id="new-group-modal" class="profile-modal" hidden role="dialog" aria-modal="true" aria-label="Criar novo grupo">
    <div class="profile-modal-backdrop" id="new-group-backdrop"></div>
    <div class="profile-modal-box group-modal-box">
        <button type="button" class="profile-modal-close" id="new-group-close" title="Fechar">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>
        <div class="group-modal-title">👥 Criar Novo Grupo</div>
        <div id="new-group-msg" class="profile-edit-msg" hidden></div>
        <form id="new-group-form" class="group-create-form">
            <div class="form-group">
                <label for="new-group-name-input">Nome do grupo *</label>
                <input type="text" id="new-group-name-input" placeholder="Ex: Família, Trabalho…"
                       maxlength="100" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label>Adicionar participantes (online agora)</label>
                <div id="new-group-members-list" class="group-members-checkboxes"></div>
            </div>
            <div class="form-group">
                <label for="new-group-extra-input">Ou digite nomes de usuário (separados por vírgula)</label>
                <input type="text" id="new-group-extra-input"
                       placeholder="user1, user2, …" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary btn-full">✅ Criar grupo</button>
        </form>
    </div>
</div>

<!-- Group settings modal -->
<div id="group-settings-modal" class="profile-modal" hidden role="dialog" aria-modal="true" aria-label="Configurações do grupo">
    <div class="profile-modal-backdrop" id="group-settings-backdrop"></div>
    <div class="profile-modal-box group-modal-box">
        <button type="button" class="profile-modal-close" id="group-settings-close" title="Fechar">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>
        <div class="group-modal-title" id="group-settings-title">⚙️ Configurações do Grupo</div>
        <div id="group-settings-msg" class="profile-edit-msg" hidden></div>

        <!-- Rename section (admin only) -->
        <div id="group-rename-section" class="group-settings-section" hidden>
            <form id="group-rename-form" class="group-rename-form">
                <input type="text" id="group-rename-input" placeholder="Novo nome do grupo"
                       maxlength="100" autocomplete="off">
                <button type="submit" class="btn btn-primary" style="white-space:nowrap">Renomear</button>
            </form>
        </div>

        <!-- Members list -->
        <div class="group-settings-section">
            <div class="group-settings-section-label">Membros</div>
            <ul id="group-settings-members" class="group-settings-member-list"></ul>
        </div>

        <!-- Add member (admin only) -->
        <div id="group-add-member-section" class="group-settings-section" hidden>
            <div class="group-settings-section-label">Adicionar membro</div>
            <form id="group-add-member-form" class="group-rename-form">
                <input type="text" id="group-add-member-input" placeholder="Nome de usuário"
                       maxlength="20" autocomplete="off">
                <button type="submit" class="btn btn-primary" style="white-space:nowrap">Adicionar</button>
            </form>
        </div>

        <!-- Leave / delete group buttons -->
        <div class="group-settings-section group-danger-section">
            <button type="button" id="group-leave-btn" class="btn btn-outline btn-danger-outline">🚪 Sair do grupo</button>
            <button type="button" id="group-delete-btn" class="btn btn-outline btn-danger-outline" hidden>🗑️ Excluir grupo</button>
        </div>
    </div>
</div>

<script>
    const CURRENT_USER       = <?= json_encode($_SESSION['username']) ?>;
    const IS_ADMIN           = <?= json_encode(isAdmin()) ?>;
    const SELF_DISPLAY_NAME  = <?= json_encode($userRow['display_name'] ?? '') ?>;
    const SELF_EMAIL         = <?= json_encode($userRow['email'] ?? '') ?>;
    const SELF_AVATAR_URL    = <?= json_encode($selfAvatarUrl) ?>;
    const SELF_INITIAL       = <?= json_encode($selfInitial) ?>;
    const SELF_USERNAME      = <?= json_encode($selfUsername) ?>;
</script>
<script src="js/chat.js"></script>
</body>
</html>
