/* chat.js – client-side logic for the instant messaging site */

(function () {
    'use strict';

    // ── CSRF helper ───────────────────────────────────────────────────────────
    const CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    /**
     * Wraps fetch() and automatically injects the CSRF token into every POST
     * request — via FormData field for multipart bodies, and via header for all
     * POST requests.
     */
    function csrfFetch(url, options) {
        options = options || {};
        if (options.method && options.method.toUpperCase() === 'POST') {
            // Inject into FormData body when available
            if (options.body instanceof FormData) {
                options.body.append('_csrf', CSRF_TOKEN);
            }
            // Always set header (catches requests without a FormData body)
            options.headers = Object.assign({}, options.headers, {
                'X-CSRF-Token': CSRF_TOKEN
            });
        }
        return fetch(url, options);
    }

    const messagesEl        = document.getElementById('messages');
    const chatForm          = document.getElementById('chat-form');
    const messageInput      = document.getElementById('message-input');
    const usersListEl       = document.getElementById('users-list');
    const groupsListEl      = document.getElementById('groups-list');
    const emojiBtn          = document.getElementById('emoji-btn');
    const emojiPicker       = document.getElementById('emoji-picker');
    const emojiGrid         = document.getElementById('emoji-grid');
    const imageInput        = document.getElementById('image-input');
    const imagePreviewWrap  = document.getElementById('image-preview-wrap');
    const imagePreview      = document.getElementById('image-preview');
    const imageRemoveBtn    = document.getElementById('image-remove');
    const lightbox          = document.getElementById('lightbox');
    const lightboxImg       = document.getElementById('lightbox-img');
    const lightboxClose     = document.getElementById('lightbox-close');
    const profileModal      = document.getElementById('profile-modal');
    const profileModalClose = document.getElementById('profile-modal-close');
    const profileBackdrop   = document.getElementById('profile-modal-backdrop');
    const openProfileBtn    = document.getElementById('open-profile-btn');
    const openProfileBtn2   = document.getElementById('open-profile-btn2');
    const sidebarToggleBtn  = document.getElementById('sidebar-toggle-btn');
    const sidebarOverlay    = document.getElementById('sidebar-overlay');
    const waSidebar         = document.getElementById('wa-sidebar');
    const panelBackBtn      = document.getElementById('panel-back-btn');
    const panelAvatar       = document.getElementById('panel-avatar');
    const panelName         = document.getElementById('panel-name');
    const panelStatus       = document.getElementById('panel-status');
    const dmClearBtn        = document.getElementById('dm-clear-btn');
    const welcomeState      = document.getElementById('welcome-state');
    const groupSettingsBtn  = document.getElementById('group-settings-btn');

    // Compose elements
    const composeAttachments = document.getElementById('compose-attachments');

    // Audio recording elements
    const micBtn             = document.getElementById('mic-btn');
    const sendBtn            = document.querySelector('.send-btn');
    const audioRecordingWrap = document.getElementById('audio-recording-wrap');
    const audioCancelBtn     = document.getElementById('audio-cancel-btn');
    const audioSendBtn       = document.getElementById('audio-send-btn');
    const audioRecTimer      = document.getElementById('audio-rec-timer');
    const audioPreviewWrap   = document.getElementById('audio-preview-wrap');
    const audioPreviewPlayer = document.getElementById('audio-preview-player');
    const audioPreviewRemove = document.getElementById('audio-preview-remove');
    const fileInput          = document.getElementById('file-input');
    const filePreviewWrap    = document.getElementById('file-preview-wrap');
    const filePreviewName    = document.getElementById('file-preview-name');
    const filePreviewRemove  = document.getElementById('file-preview-remove');

    // Music player elements
    const musicPlayerBtn    = document.getElementById('music-player-btn');
    const musicPlayerBar    = document.getElementById('music-player-bar');
    const musicPlayerAudio  = document.getElementById('music-player-audio');
    const playerTrackTitle  = document.getElementById('player-track-title');
    const playerTrackArtist = document.getElementById('player-track-artist');
    const playerPlayPause   = document.getElementById('player-play-pause');
    const playerPlayIcon    = document.getElementById('player-play-icon');
    const playerPauseIcon   = document.getElementById('player-pause-icon');
    const playerPrev        = document.getElementById('player-prev');
    const playerNext        = document.getElementById('player-next');
    const playerVolume      = document.getElementById('player-volume');
    const playerMuteBtn     = document.getElementById('player-mute-btn');
    const playerVolIcon     = document.getElementById('player-vol-icon');
    const playerMuteIcon    = document.getElementById('player-mute-icon');

    // Fullscreen elements
    const fullscreenBtn        = document.getElementById('fullscreen-btn');
    const fullscreenEnterIcon  = document.getElementById('fullscreen-enter-icon');
    const fullscreenExitIcon   = document.getElementById('fullscreen-exit-icon');

    let lastMessageId   = 0;
    let lastDmId        = 0;
    let lastGroupMsgId  = 0;
    let pollTimer       = null;
    let consecutiveFail = 0;
    let statusBanner    = null;
    let isAdmin         = !!IS_ADMIN;
    let peerLastRead    = 0;
    let notifCtx        = null;
    let avatarMap       = {};  // username → avatar url (or null)
    let currentMode     = 'welcome';  // 'welcome' | 'dm' | 'group_chat'
    let dmPartner       = null;
    let currentGroupId  = null;
    let currentGroupRole = null;  // 'admin' | 'member'
    let groups          = [];     // cached group list

    // ── Profile modal ─────────────────────────────────────────────────────────

    function openProfileModal() {
        if (profileModal) {
            showProfileView();  // always start in view mode
            profileModal.hidden = false;
            document.body.style.overflow = 'hidden';
            if (profileModalClose) profileModalClose.focus();
        }
    }

    function closeProfileModal() {
        if (profileModal) {
            profileModal.hidden = true;
            document.body.style.overflow = '';
        }
    }

    if (openProfileBtn)  openProfileBtn.addEventListener('click', openProfileModal);
    if (openProfileBtn2) openProfileBtn2.addEventListener('click', openProfileModal);
    if (profileModalClose) profileModalClose.addEventListener('click', closeProfileModal);
    if (profileBackdrop)   profileBackdrop.addEventListener('click', closeProfileModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && profileModal && !profileModal.hidden) closeProfileModal();
    });

    // ── Profile edit (view ↔ edit toggle) ────────────────────────────────────

    const profileView       = document.getElementById('profile-view');
    const profileEdit       = document.getElementById('profile-edit');
    const profileEditBtn    = document.getElementById('profile-edit-btn');
    const profileCancelBtn  = document.getElementById('profile-cancel-btn');
    const profileSaveBtn    = document.getElementById('profile-save-btn');
    const profileEditForm   = document.getElementById('profile-edit-form');
    const profileEditMsg    = document.getElementById('profile-edit-msg');
    const profileAvatarInput    = document.getElementById('profile-avatar-input');
    const profileFormAvatarInput = document.getElementById('profile-form-avatar-input');
    const profileEditAvatarPreview = document.getElementById('profile-edit-avatar-preview');
    const profileEditAvatarInitial = document.getElementById('profile-edit-avatar-initial');

    // State for current profile data (updated on successful save)
    let selfDisplayName = typeof SELF_DISPLAY_NAME !== 'undefined' ? SELF_DISPLAY_NAME : '';
    let selfEmail       = typeof SELF_EMAIL       !== 'undefined' ? SELF_EMAIL       : '';
    let selfAvatarUrl   = typeof SELF_AVATAR_URL  !== 'undefined' ? SELF_AVATAR_URL  : '';
    let selfUsername    = typeof SELF_USERNAME    !== 'undefined' ? SELF_USERNAME    : CURRENT_USER;

    function showProfileView() {
        if (profileView) profileView.hidden = false;
        if (profileEdit) profileEdit.hidden = true;
        if (profileEditMsg) { profileEditMsg.hidden = true; profileEditMsg.textContent = ''; }
    }

    function showProfileEditMode() {
        if (profileView) profileView.hidden = true;
        if (profileEdit) profileEdit.hidden = false;
        const un = document.getElementById('profile-username-input');
        const dn = document.getElementById('profile-display-name-input');
        const em = document.getElementById('profile-email-input');
        if (un) un.value = selfUsername;
        if (dn) dn.value = selfDisplayName;
        if (em) em.value = selfEmail;
        if (profileEditMsg) { profileEditMsg.hidden = true; profileEditMsg.textContent = ''; }
        if (un) un.focus();
    }

    if (profileEditBtn)   profileEditBtn.addEventListener('click', showProfileEditMode);
    if (profileCancelBtn) profileCancelBtn.addEventListener('click', showProfileView);

    // Live avatar preview in edit mode
    if (profileAvatarInput) {
        profileAvatarInput.addEventListener('change', function () {
            const file = profileAvatarInput.files[0];
            if (!file) return;
            // Sync to the actual hidden form input
            if (profileFormAvatarInput) {
                const dt = new DataTransfer();
                dt.items.add(file);
                profileFormAvatarInput.files = dt.files;
            }
            const reader = new FileReader();
            reader.onload = function (ev) {
                if (profileEditAvatarInitial) profileEditAvatarInitial.style.display = 'none';
                if (profileEditAvatarPreview) {
                    profileEditAvatarPreview.src = ev.target.result;
                    profileEditAvatarPreview.hidden = false;
                } else {
                    // Create img if not present
                    const wrap = document.getElementById('profile-edit-avatar-wrap');
                    if (wrap) {
                        let img = wrap.querySelector('img.profile-avatar-img');
                        if (!img) {
                            img = document.createElement('img');
                            img.className = 'profile-avatar-img';
                            img.id = 'profile-edit-avatar-preview';
                            img.alt = 'Prévia';
                            wrap.insertBefore(img, wrap.firstChild);
                        }
                        img.src = ev.target.result;
                    }
                }
            };
            reader.readAsDataURL(file);
        });
    }

    // Submit profile edit form
    if (profileEditForm) {
        profileEditForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (profileSaveBtn) profileSaveBtn.disabled = true;

            const formData = new FormData(profileEditForm);
            // Merge avatar from the picker input
            if (profileFormAvatarInput && profileFormAvatarInput.files[0]) {
                formData.set('avatar', profileFormAvatarInput.files[0]);
            } else {
                formData.delete('avatar');
            }

            csrfFetch('api/profile.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        // Update local state
                        if (data.username) selfUsername = data.username;
                        selfDisplayName = data.display_name || '';
                        selfEmail       = data.email        || '';
                        if (data.avatar_url) selfAvatarUrl = data.avatar_url;

                        // Update view mode display
                        const vun = document.getElementById('profile-username');
                        const vdn = document.getElementById('profile-view-display-name');
                        const vem = document.getElementById('profile-view-email');
                        if (vun && data.username) vun.textContent = data.username;
                        if (vdn) vdn.textContent = selfDisplayName;
                        if (vem) vem.textContent = selfEmail ? '✉️ ' + selfEmail : '';

                        // Update all avatar images on page if new avatar
                        if (data.avatar_url) {
                            document.querySelectorAll('#open-profile-btn .avatar-img, #open-profile-btn2 .avatar-img, .profile-avatar-img').forEach(function (img) {
                                img.src = data.avatar_url + '?t=' + Date.now();
                            });
                            avatarMap[CURRENT_USER] = data.avatar_url;
                        }

                        // Clear password fields
                        ['profile-cur-pw', 'profile-new-pw', 'profile-conf-pw'].forEach(function (id) {
                            const el = document.getElementById(id);
                            if (el) el.value = '';
                        });
                        const pwDetails = document.querySelector('.profile-pw-section');
                        if (pwDetails) pwDetails.open = false;

                        showProfileMsg('✅ Perfil atualizado!', 'ok');
                        setTimeout(showProfileView, 1400);
                    } else {
                        showProfileMsg('❌ ' + (data.error || 'Erro ao salvar'), 'error');
                    }
                })
                .catch(function () {
                    showProfileMsg('❌ Erro de conexão', 'error');
                })
                .finally(function () {
                    if (profileSaveBtn) profileSaveBtn.disabled = false;
                });
        });
    }

    function showProfileMsg(text, type) {
        if (!profileEditMsg) return;
        profileEditMsg.textContent = text;
        profileEditMsg.className   = 'profile-edit-msg ' + type;
        profileEditMsg.hidden      = false;
    }

    // ── Mobile sidebar toggle ────────────────────────────────────────────────

    function openMobileSidebar() {
        if (waSidebar) waSidebar.classList.add('sidebar-open');
        if (sidebarOverlay) sidebarOverlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebar() {
        if (waSidebar) waSidebar.classList.remove('sidebar-open');
        if (sidebarOverlay) sidebarOverlay.hidden = true;
        document.body.style.overflow = '';
    }

    if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', openMobileSidebar);
    if (sidebarOverlay)   sidebarOverlay.addEventListener('click', closeMobileSidebar);

    // ── Welcome / idle state ──────────────────────────────────────────────────

    function showWelcomeState() {
        currentMode    = 'welcome';
        dmPartner      = null;
        currentGroupId = null;
        currentGroupRole = null;

        if (welcomeState) welcomeState.hidden = false;
        if (messagesEl)   messagesEl.hidden   = true;
        if (chatForm)     chatForm.hidden      = true;
        if (audioRecordingWrap) audioRecordingWrap.hidden = true;

        if (panelName)   panelName.textContent   = 'Bem-vindo(a)';
        if (panelStatus) panelStatus.textContent = 'Selecione uma conversa ou crie um grupo';
        if (panelAvatar) panelAvatar.textContent  = '💬';
        if (panelBackBtn) panelBackBtn.hidden = true;
        if (dmClearBtn)  dmClearBtn.hidden = true;
        if (groupSettingsBtn) groupSettingsBtn.hidden = true;

        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, 2000);
    }

    // ── Private chat (DM) mode ────────────────────────────────────────────────

    function openDM(username) {
        if (username === CURRENT_USER) return;
        currentMode      = 'dm';
        dmPartner        = username;
        currentGroupId   = null;
        currentGroupRole = null;
        lastDmId         = 0;

        if (welcomeState) welcomeState.hidden = true;
        if (messagesEl)   messagesEl.hidden   = false;
        if (chatForm)     chatForm.hidden      = false;

        // Update panel header
        if (panelName) {
            panelName.innerHTML = escapeHtml(username) +
                '<span class="panel-dm-badge">DM</span>';
        }
        if (panelAvatar) {
            const av = avatarMap[username];
            if (av) {
                panelAvatar.innerHTML = '<img src="' + escapeHtml(av) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';
            } else {
                panelAvatar.textContent = username.charAt(0).toUpperCase();
            }
        }
        if (panelBackBtn)     panelBackBtn.hidden     = false;
        if (dmClearBtn)       dmClearBtn.hidden       = false;
        if (groupSettingsBtn) groupSettingsBtn.hidden = true;

        // Clear messages and load DM history
        messagesEl.innerHTML = '';
        closeMobileSidebar();
        clearTimeout(pollTimer);
        loadMessages(true).finally(function () {
            pollTimer = setTimeout(poll, 2000);
        });

        // Update active state in user list
        usersListEl.querySelectorAll('.wa-user-item').forEach(function (li) {
            li.classList.toggle('dm-active', li.dataset.username === username);
        });
        groupsListEl.querySelectorAll('.wa-group-item').forEach(function (li) {
            li.classList.remove('dm-active');
        });

        messageInput.focus();
    }

    // ── Group chat mode ───────────────────────────────────────────────────────

    function openGroupChat(groupId, groupName, myRole) {
        currentMode      = 'group_chat';
        currentGroupId   = groupId;
        currentGroupRole = myRole || 'member';
        dmPartner        = null;
        lastGroupMsgId   = 0;

        if (welcomeState) welcomeState.hidden = true;
        if (messagesEl)   messagesEl.hidden   = false;
        if (chatForm)     chatForm.hidden      = false;

        if (panelName)   panelName.textContent   = groupName || 'Grupo';
        if (panelStatus) panelStatus.textContent = currentGroupRole === 'admin' ? '👑 Você é admin' : 'Grupo';
        if (panelAvatar) panelAvatar.textContent  = '👥';
        if (panelBackBtn)     panelBackBtn.hidden     = false;
        if (dmClearBtn)       dmClearBtn.hidden       = true;
        if (groupSettingsBtn) groupSettingsBtn.hidden = false;

        messagesEl.innerHTML = '';
        closeMobileSidebar();
        clearTimeout(pollTimer);
        loadMessages(true).finally(function () {
            pollTimer = setTimeout(poll, 2000);
        });

        usersListEl.querySelectorAll('.wa-user-item').forEach(function (li) {
            li.classList.remove('dm-active');
        });
        groupsListEl.querySelectorAll('.wa-group-item').forEach(function (li) {
            li.classList.toggle('dm-active', parseInt(li.dataset.groupId, 10) === groupId);
        });

        messageInput.focus();
    }

    if (panelBackBtn) panelBackBtn.addEventListener('click', showWelcomeState);

    // ── Delete all DM messages ────────────────────────────────────────────────

    if (dmClearBtn) {
        dmClearBtn.addEventListener('click', function () {
            if (!dmPartner) return;
            if (!confirm('Apagar todas as mensagens desta conversa? Esta ação não pode ser desfeita.')) return;

            const body = new FormData();
            body.append('with', dmPartner);

            csrfFetch('api/dm_delete_all.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        messagesEl.innerHTML = '';
                        lastDmId = 0;
                    } else {
                        showStatus('❌ ' + (data.error || 'Erro ao apagar conversa'));
                    }
                })
                .catch(function () {
                    showStatus('❌ Erro de conexão');
                });
        });
    }

    function initAudioCtx() {
        if (!notifCtx) {
            try {
                notifCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) { /* not available in this browser */ }
        }
    }

    document.addEventListener('click',   initAudioCtx);
    document.addEventListener('keydown', initAudioCtx);

    function playNotificationSound() {
        initAudioCtx();
        if (!notifCtx) return;
        try {
            if (notifCtx.state === 'suspended') notifCtx.resume();
            var t = notifCtx.currentTime;
            [
                { freq: 784, start: 0,    dur: 0.12 },
                { freq: 1047, start: 0.13, dur: 0.18 },
            ].forEach(function (note) {
                var osc  = notifCtx.createOscillator();
                var gain = notifCtx.createGain();
                osc.connect(gain);
                gain.connect(notifCtx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(note.freq, t + note.start);
                gain.gain.setValueAtTime(0, t + note.start);
                gain.gain.linearRampToValueAtTime(0.38, t + note.start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, t + note.start + note.dur);
                osc.start(t + note.start);
                osc.stop(t + note.start + note.dur + 0.01);
            });
        } catch (e) { /* silently ignore */ }
    }

    // ── Lightbox ──────────────────────────────────────────────────────────────

    function openLightbox(src) {
        lightboxImg.src = src;
        lightbox.hidden = false;
        document.body.style.overflow = 'hidden';
        lightboxClose.focus();
    }

    function closeLightbox() {
        lightbox.hidden = true;
        lightboxImg.src = '';
        document.body.style.overflow = '';
    }

    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) closeLightbox();
    });
    lightboxClose.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !lightbox.hidden) closeLightbox();
    });

    messagesEl.addEventListener('click', function (e) {
        const img = e.target.closest('.chat-image');
        if (img) openLightbox(img.src);
    });

    // ── Emoji list ────────────────────────────────────────────────────────────
    const EMOJIS = [
        '😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊',
        '😋','😎','😍','😘','🥰','😗','😙','😚','🙂','🤗',
        '🤩','🤔','🤨','😐','😑','😶','🙄','😏','😣','😥',
        '😮','🤐','😯','😪','😫','🥱','😴','😌','😛','😜',
        '😝','🤤','😒','😓','😔','😕','🙃','🤑','😲','☹️',
        '🙁','😖','😞','😟','😤','😢','😭','😦','😧','😨',
        '😩','🤯','😬','😰','😱','🥵','🥶','😳','🤪','😵',
        '😡','😠','🤬','😷','🤒','🤕','🤢','🤮','🤧','🥴',
        '😇','🥳','🥸','🤠','🤡','🤥','🤫','🤭','🧐','🤓',
        '👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞',
        '🤟','🤘','🤙','👈','👉','👆','👇','👍','👎','✊',
        '👊','🤛','🤜','👏','🙌','🤲','🤝','🙏','❤️','🧡',
        '💛','💚','💙','💜','🖤','🤍','🤎','💔','💯','🔥',
        '⭐','✨','🎉','🎊','🎈','🎁','🎀','🎂','🍕','🍔',
    ];

    EMOJIS.forEach(function (em) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'emoji-item';
        btn.textContent = em;
        btn.setAttribute('aria-label', em);
        btn.addEventListener('click', function () {
            const start = messageInput.selectionStart;
            const end   = messageInput.selectionEnd;
            const val   = messageInput.value;
            messageInput.value = val.slice(0, start) + em + val.slice(end);
            messageInput.selectionStart = messageInput.selectionEnd = start + em.length;
            messageInput.focus();
            autoResize();
        });
        emojiGrid.appendChild(btn);
    });

    emojiBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        emojiPicker.hidden = !emojiPicker.hidden;
    });

    function closePickerOnOutside(e) {
        if (!emojiPicker.hidden && !emojiPicker.contains(e.target) && !emojiBtn.contains(e.target)) {
            emojiPicker.hidden = true;
        }
    }
    document.addEventListener('click',      closePickerOnOutside);
    document.addEventListener('touchstart', closePickerOnOutside, { passive: true });

    // ── Attachments zone helper ───────────────────────────────────────────────

    function updateAttachmentsZone() {
        const hasImage = imagePreviewWrap && !imagePreviewWrap.hidden;
        const hasAudio = audioPreviewWrap && !audioPreviewWrap.hidden;
        const hasFile  = filePreviewWrap  && !filePreviewWrap.hidden;
        if (composeAttachments) {
            composeAttachments.hidden = !(hasImage || hasAudio || hasFile);
        }
    }

    // ── Image attach ──────────────────────────────────────────────────────────
    const attachImageBtn = document.getElementById('attach-image-btn');
    if (attachImageBtn) {
        attachImageBtn.addEventListener('click', function () { imageInput.click(); });
    }

    imageInput.addEventListener('change', function () {
        const file = imageInput.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (ev) {
            imagePreview.src = ev.target.result;
            if (imagePreviewWrap) imagePreviewWrap.hidden = false;
            updateAttachmentsZone();
        };
        reader.readAsDataURL(file);
    });

    imageRemoveBtn.addEventListener('click', function () {
        imageInput.value = '';
        imagePreview.src = '';
        if (imagePreviewWrap) imagePreviewWrap.hidden = true;
        updateAttachmentsZone();
    });

    // ── Audio recording ───────────────────────────────────────────────────────

    let mediaRecorder   = null;
    let audioChunks     = [];
    let audioBlob       = null;
    let fileAttachment  = null;   // general file (video, PDF, doc, …)
    let recTimerInterval = null;
    let recSeconds      = 0;
    const MAX_REC_SECS  = 120;

    function getAudioMime() {
        const candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/ogg',
            'audio/mp4',
        ];
        for (const mime of candidates) {
            if (MediaRecorder.isTypeSupported(mime)) return mime;
        }
        return '';
    }

    function formatTime(secs) {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function startRecTimer() {
        recSeconds = 0;
        audioRecTimer.textContent = formatTime(0);
        recTimerInterval = setInterval(function () {
            recSeconds++;
            audioRecTimer.textContent = formatTime(recSeconds);
            if (recSeconds >= MAX_REC_SECS) {
                stopRecording(true);
            }
        }, 1000);
    }

    function stopRecTimer() {
        clearInterval(recTimerInterval);
        recTimerInterval = null;
    }

    function showRecordingUI() {
        chatForm.hidden = true;
        audioRecordingWrap.hidden = false;
    }

    function showComposeUI() {
        if (currentMode !== 'welcome') chatForm.hidden = false;
        audioRecordingWrap.hidden = true;
    }

    function showAudioPreview(blob) {
        audioBlob = blob;
        const url = URL.createObjectURL(blob);
        audioPreviewPlayer.src = url;
        if (audioPreviewWrap) audioPreviewWrap.hidden = false;
        updateAttachmentsZone();
        updateSendMicVisibility();
    }

    function discardAudioPreview() {
        if (audioPreviewPlayer.src) {
            URL.revokeObjectURL(audioPreviewPlayer.src);
        }
        audioPreviewPlayer.src = '';
        audioBlob = null;
        if (audioPreviewWrap) audioPreviewWrap.hidden = true;
        updateAttachmentsZone();
        updateSendMicVisibility();
    }

    function updateSendMicVisibility() {
        const hasContent = messageInput.value.trim() !== '' ||
                           (imageInput.files && imageInput.files.length > 0) ||
                           audioBlob !== null ||
                           fileAttachment !== null;
        micBtn.hidden  = hasContent;
        sendBtn.hidden = !hasContent;
    }

    async function startRecording() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('❌ Seu navegador não suporta gravação de áudio.');
            return;
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (err) {
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                showStatus('❌ Permissão de microfone negada. Verifique as configurações do navegador.');
            } else {
                showStatus('❌ Não foi possível acessar o microfone.');
            }
            return;
        }

        const mime = getAudioMime();
        const options = mime ? { mimeType: mime } : {};

        try {
            mediaRecorder = new MediaRecorder(stream, options);
        } catch (_) {
            mediaRecorder = new MediaRecorder(stream);
        }

        audioChunks = [];
        mediaRecorder.addEventListener('dataavailable', function (e) {
            if (e.data && e.data.size > 0) audioChunks.push(e.data);
        });

        mediaRecorder.addEventListener('stop', function () {
            stream.getTracks().forEach(t => t.stop());
            const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
            showComposeUI();
            showAudioPreview(blob);
        });

        mediaRecorder.start(250);
        showRecordingUI();
        startRecTimer();
    }

    function stopRecording(send) {
        stopRecTimer();
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            if (!send) {
                mediaRecorder.addEventListener('stop', function onStop() {
                    mediaRecorder.removeEventListener('stop', onStop);
                    audioBlob = null;
                    discardAudioPreview();
                }, { once: true });
            }
            mediaRecorder.stop();
        } else {
            showComposeUI();
        }
    }

    micBtn.addEventListener('click', function () {
        discardAudioPreview();
        startRecording();
    });

    audioCancelBtn.addEventListener('click', function () {
        stopRecording(false);
        showComposeUI();
    });

    audioSendBtn.addEventListener('click', function () {
        stopRecording(true);
    });

    audioPreviewRemove.addEventListener('click', function () {
        discardAudioPreview();
    });

    // ── Auto-resize textarea ──────────────────────────────────────────────────

    function autoResize() {
        messageInput.style.height = 'auto';
        messageInput.style.height = messageInput.scrollHeight + 'px';
    }

    // ── Typing signal (debounced) ─────────────────────────────────────────────

    var typingDebounce = null;

    function sendTypingSignal() {
        if (typingDebounce) return;
        typingDebounce = setTimeout(function () { typingDebounce = null; }, 3000);
        csrfFetch('api/typing.php', { method: 'POST', credentials: 'same-origin' })
            .catch(function () { /* non-critical */ });
    }

    messageInput.addEventListener('input', function () {
        autoResize();
        updateSendMicVisibility();
        sendTypingSignal();
    });
    imageInput.addEventListener('change', updateSendMicVisibility);

    // ── General file attachment ───────────────────────────────────────────────
    const attachFileBtn = document.getElementById('attach-file-btn');
    if (attachFileBtn && fileInput) {
        attachFileBtn.addEventListener('click', function () { fileInput.click(); });
    }

    function showFilePreview(file) {
        fileAttachment = file;
        if (filePreviewName) filePreviewName.textContent = file.name;
        if (filePreviewWrap) filePreviewWrap.hidden = false;
        updateAttachmentsZone();
        updateSendMicVisibility();
    }

    function discardFilePreview() {
        fileAttachment = null;
        if (filePreviewName) filePreviewName.textContent = 'Arquivo';
        if (filePreviewWrap) filePreviewWrap.hidden = true;
        if (fileInput) fileInput.value = '';
        updateAttachmentsZone();
        updateSendMicVisibility();
    }

    if (filePreviewRemove) {
        filePreviewRemove.addEventListener('click', discardFilePreview);
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) return;
            // Route by MIME type
            if (file.type.startsWith('audio/')) {
                // Audio → existing audio preview mechanism
                showAudioPreview(file);
            } else {
                showFilePreview(file);
            }
            // Reset so the same file can be re-selected later
            fileInput.value = '';
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    function scrollToBottom(force) {
        if (force || (messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 60)) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    function showStatus(msg) {
        if (!statusBanner) {
            statusBanner = document.createElement('div');
            statusBanner.className = 'status-banner';
            document.getElementById('chat-panel').prepend(statusBanner);
        }
        statusBanner.textContent = msg;
        statusBanner.style.display = 'block';
    }

    function hideStatus() {
        if (statusBanner) statusBanner.style.display = 'none';
        consecutiveFail = 0;
    }

    // ── Avatar helpers ────────────────────────────────────────────────────────

    function getAvatarColor(username) {
        let hash = 0;
        for (let i = 0; i < username.length; i++) {
            hash = (username.charCodeAt(i) + ((hash << 5) - hash)) | 0;
        }
        const hue = Math.abs(hash) % 360;
        return 'hsl(' + hue + ',60%,42%)';
    }

    /**
     * Build an avatar element (span) for a given username.
     * Uses a photo if available in avatarMap, otherwise initial+color.
     * @param {string} username
     * @param {number} size  - pixel size
     * @returns {string} HTML string
     */
    function makeAvatarHtml(username, size) {
        const url     = avatarMap[username] || null;
        const initial = escapeHtml(username.charAt(0).toUpperCase());
        const color   = getAvatarColor(username);
        const style   = 'width:' + size + 'px;height:' + size + 'px;min-width:' + size + 'px;' +
                         'border-radius:50%;overflow:hidden;flex-shrink:0;' +
                         'display:inline-flex;align-items:center;justify-content:center;' +
                         'font-size:' + Math.round(size * 0.4) + 'px;font-weight:700;' +
                         'color:#fff;text-transform:uppercase;user-select:none;' +
                         'box-shadow:0 1px 3px rgba(0,0,0,.18);' +
                         (url ? '' : 'background:' + color + ';');
        if (url) {
            return '<span style="' + style + '">' +
                   '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(username) + '" ' +
                   'style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy"></span>';
        }
        return '<span style="' + style + '">' + initial + '</span>';
    }

    // ── Render a single message bubble ────────────────────────────────────────

    function renderMessage(msg) {
        const isOwn   = msg.username === CURRENT_USER;
        const deleted = !!msg.deleted_at;

        const div = document.createElement('div');
        div.className = 'message ' + (isOwn ? 'own' : 'other') + (deleted ? ' deleted' : '');
        div.dataset.id = msg.id;

        // Can delete: own messages always; group admins can delete anyone's message in group
        const canDelete = !deleted && (isOwn || (currentMode === 'group_chat' && currentGroupRole === 'admin') || isAdmin);
        const deleteBtn = canDelete
            ? '<button class="btn-delete-msg" data-id="' + escapeHtml(String(msg.id)) + '" title="Apagar mensagem">🗑️</button>'
            : '';

        // Edit button (owner only)
        const editBtn = (isOwn && !deleted && msg.content)
            ? '<button class="btn-edit-msg" data-id="' + escapeHtml(String(msg.id)) + '" title="Editar mensagem">✏️</button>'
            : '';

        let timeStr = msg.created_at;
        if (msg.ts) {
            const d = new Date(parseInt(msg.ts, 10) * 1000);
            timeStr = d.toLocaleString('pt-BR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false,
            });
        }
        const editedHtml = msg.edited_at ? '<span class="msg-edited">editado</span>' : '';
        const timeHtml = '<span class="bubble-time">' + editedHtml + escapeHtml(timeStr) + editBtn + deleteBtn +
            (isOwn && !deleted
                ? '<span class="msg-tick ' + (parseInt(msg.id, 10) <= peerLastRead ? 'msg-tick--read' : 'msg-tick--sent') + '">' +
                  (parseInt(msg.id, 10) <= peerLastRead ? '✓✓' : '✓') + '</span>'
                : '') +
            '</span>';

        let bodyHtml = '';

        if (deleted) {
            bodyHtml = '<div class="message-bubble deleted-bubble">🗑️ Mensagem apagada' + timeHtml + '</div>';
        } else {
            let contentHtml = '';
            if (msg.content) {
                contentHtml = '<div class="message-bubble">' + escapeHtml(msg.content) + timeHtml + '</div>';
            }
            let imageHtml = '';
            if (msg.image_path) {
                const src = escapeHtml('data/uploads/' + msg.image_path);
                imageHtml = '<div class="message-bubble message-image-bubble">' +
                    '<img src="' + src + '" class="chat-image" alt="imagem" loading="lazy">' +
                    timeHtml + '</div>';
            }
            let audioHtml = '';
            if (msg.audio_path) {
                const src = escapeHtml('data/uploads/' + msg.audio_path);
                audioHtml = '<div class="message-bubble message-audio-bubble">' +
                    '<span class="audio-bubble-icon" aria-hidden="true">🎤</span>' +
                    '<audio class="chat-audio" src="' + src + '" preload="metadata" controls></audio>' +
                    timeHtml + '</div>';
            }
            let fileHtml = '';
            if (msg.file_path) {
                const rawName = msg.file_path.replace(/^file_[0-9a-f]+_/, '');
                const displayName = escapeHtml(rawName);
                const src  = escapeHtml('data/uploads/' + msg.file_path);
                const ext  = rawName.split('.').pop().toLowerCase();
                const isVideo = ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mpg'].indexOf(ext) !== -1;
                const isAudio = ['mp3', 'ogg', 'm4a', 'wav'].indexOf(ext) !== -1;
                if (isVideo) {
                    fileHtml = '<div class="message-bubble message-video-bubble">' +
                        '<video class="chat-video" src="' + src + '" controls preload="metadata"></video>' +
                        timeHtml + '</div>';
                } else if (isAudio) {
                    fileHtml = '<div class="message-bubble message-audio-bubble">' +
                        '<span class="audio-bubble-icon" aria-hidden="true">🎵</span>' +
                        '<audio class="chat-audio" src="' + src + '" preload="metadata" controls></audio>' +
                        timeHtml + '</div>';
                } else {
                    const icon = ext === 'pdf' ? '📄' :
                                 ['doc','docx'].indexOf(ext) !== -1 ? '📝' :
                                 ['xls','xlsx'].indexOf(ext) !== -1 ? '📊' :
                                 ['ppt','pptx'].indexOf(ext) !== -1 ? '📋' :
                                 ['zip','rar','7z','gz','tar'].indexOf(ext) !== -1 ? '🗜️' : '📎';
                    fileHtml = '<div class="message-bubble message-file-bubble">' +
                        '<a href="' + src + '" class="chat-file-link" download="' + displayName + '" target="_blank" rel="noopener noreferrer">' +
                        '<span class="chat-file-icon">' + icon + '</span>' +
                        '<span class="chat-file-name">' + displayName + '</span>' +
                        '</a>' +
                        timeHtml + '</div>';
                }
            }
            bodyHtml = fileHtml + audioHtml + imageHtml + contentHtml;
        }

        // Sender name (others only)
        const avatarColor  = getAvatarColor(msg.username);
        const senderHtml = isOwn
            ? ''
            : '<div class="message-sender" style="color:' + avatarColor + '">' + escapeHtml(msg.username) + '</div>';

        // Avatar (others only — 28px, shows photo if available)
        const avatarHtml = isOwn
            ? ''
            : makeAvatarHtml(msg.username, 28);

        div.innerHTML =
            '<div class="message-inner">' +
                avatarHtml +
                '<div class="message-content">' +
                    senderHtml +
                    bodyHtml +
                '</div>' +
            '</div>';

        return div;
    }

    // ── Mark a rendered message element as deleted ────────────────────────────

    function markDeletedInDOM(msgDiv) {
        msgDiv.classList.add('deleted');
        msgDiv.querySelector('.btn-delete-msg')?.remove();
        msgDiv.querySelectorAll('.message-bubble, .message-image-bubble, .message-audio-bubble').forEach(b => b.remove());
        const msgContent = msgDiv.querySelector('.message-content') || msgDiv;
        const deletedBubble = document.createElement('div');
        deletedBubble.className = 'message-bubble deleted-bubble';
        deletedBubble.textContent = '🗑️ Mensagem apagada';
        msgContent.appendChild(deletedBubble);
    }

    // ── Admin / owner delete ─────────────────────────────────────────────────

    messagesEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-delete-msg');
        if (!btn) return;

        const id = btn.dataset.id;
        if (!id) return;

        const msgDiv = messagesEl.querySelector('.message[data-id="' + id + '"]');
        if (msgDiv) msgDiv.classList.remove('show-actions');

        if (!confirm('Apagar esta mensagem?')) return;

        const body = new FormData();
        body.append('id', id);

        const endpoint = currentMode === 'group_chat'
            ? 'api/group_delete.php'
            : (currentMode === 'dm' ? 'api/dm_message_delete.php' : 'api/delete.php');

        csrfFetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const msgDiv = messagesEl.querySelector('.message[data-id="' + id + '"]');
                    if (msgDiv) {
                        // In DM mode, remove message entirely (no tombstone)
                        if (currentMode === 'dm') {
                            msgDiv.remove();
                        } else {
                            markDeletedInDOM(msgDiv);
                        }
                    }
                } else {
                    showStatus('❌ ' + (data.error || 'Não foi possível apagar a mensagem'));
                }
            })
            .catch(function () {
                showStatus('❌ Erro ao apagar mensagem.');
            });
    });

    // ── Owner edit (inline) ───────────────────────────────────────────────────

    messagesEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-edit-msg');
        if (!btn) return;

        const id = btn.dataset.id;
        if (!id) return;

        const msgDiv = messagesEl.querySelector('.message[data-id="' + id + '"]');
        if (!msgDiv) return;

        msgDiv.classList.remove('show-actions');

        const bubble = msgDiv.querySelector('.message-bubble:not(.deleted-bubble):not(.message-image-bubble):not(.message-audio-bubble)');
        if (!bubble) return;

        if (bubble.querySelector('.edit-textarea')) return;

        const timeSpan = bubble.querySelector('.bubble-time');
        let originalText = '';
        bubble.childNodes.forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) originalText += node.textContent;
        });
        originalText = originalText.trim();

        const textarea = document.createElement('textarea');
        textarea.className = 'edit-textarea';
        textarea.value = originalText;
        textarea.rows = Math.max(2, Math.ceil(originalText.length / 40));

        const btnWrap = document.createElement('div');
        btnWrap.className = 'edit-actions';

        const saveBtn2 = document.createElement('button');
        saveBtn2.type = 'button';
        saveBtn2.className = 'edit-save';
        saveBtn2.textContent = 'Salvar';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'edit-cancel';
        cancelBtn.textContent = 'Cancelar';

        btnWrap.appendChild(saveBtn2);
        btnWrap.appendChild(cancelBtn);

        Array.from(bubble.childNodes).forEach(node => {
            if (node !== timeSpan) node.remove();
        });
        bubble.insertBefore(textarea, timeSpan);
        bubble.insertBefore(btnWrap, timeSpan);
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        function cancelEdit() {
            const textNode = document.createTextNode(originalText);
            bubble.insertBefore(textNode, textarea);
            textarea.remove();
            btnWrap.remove();
        }

        function confirmEdit() {
            const newContent = textarea.value.trim();
            if (!newContent) return;
            if (newContent === originalText) { cancelEdit(); return; }

            saveBtn2.disabled = true;
            cancelBtn.disabled = true;

            const body = new FormData();
            body.append('id', id);
            body.append('content', newContent);

            const editEndpoint = (currentMode === 'group_chat') ? 'api/group_edit.php' : 'api/edit.php';

            csrfFetch(editEndpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        const textNode = document.createTextNode(newContent);
                        bubble.insertBefore(textNode, textarea);
                        textarea.remove();
                        btnWrap.remove();
                        let editedSpan = bubble.querySelector('.msg-edited');
                        if (!editedSpan) {
                            editedSpan = document.createElement('span');
                            editedSpan.className = 'msg-edited';
                            editedSpan.textContent = 'editado';
                            if (timeSpan) timeSpan.insertBefore(editedSpan, timeSpan.firstChild);
                        }
                    } else {
                        showStatus('❌ ' + (data.error || 'Não foi possível editar a mensagem'));
                        cancelEdit();
                    }
                })
                .catch(function () {
                    showStatus('❌ Erro ao editar mensagem.');
                    cancelEdit();
                });
        }

        saveBtn2.addEventListener('click', confirmEdit);
        cancelBtn.addEventListener('click', cancelEdit);
        textarea.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); confirmEdit(); }
            if (ev.key === 'Escape') { cancelEdit(); }
        });
    });

    // ── Toggle action buttons on tap/click ────────────────────────────────────

    document.addEventListener('click', function (e) {
        if (!messagesEl.contains(e.target)) {
            messagesEl.querySelectorAll('.message.show-actions')
                .forEach(function (m) { m.classList.remove('show-actions'); });
        }
    });

    messagesEl.addEventListener('click', function (e) {
        if (e.target.closest('.btn-delete-msg, .btn-edit-msg, .edit-actions, .chat-image, .chat-audio')) return;

        const msgDiv = e.target.closest('.message');
        const wasOpen = msgDiv && msgDiv.classList.contains('show-actions');

        messagesEl.querySelectorAll('.message.show-actions')
            .forEach(function (m) { m.classList.remove('show-actions'); });

        if (msgDiv && !wasOpen) msgDiv.classList.add('show-actions');
    });

    // ── Update read-receipt ticks ─────────────────────────────────────────────

    function updateTicks() {
        messagesEl.querySelectorAll('.message.own').forEach(function (div) {
            const msgId = parseInt(div.dataset.id, 10);
            if (msgId > peerLastRead) return;
            const tick = div.querySelector('.msg-tick');
            if (!tick || tick.classList.contains('msg-tick--read')) return;
            tick.className = 'msg-tick msg-tick--read';
            tick.textContent = '✓✓';
        });
    }

    // ── Load messages ─────────────────────────────────────────────────────────

    function loadMessages(initial) {
        let url;

        if (currentMode === 'welcome') {
            // Nothing to load; just refresh user list
            return Promise.resolve();
        } else if (currentMode === 'dm' && dmPartner) {
            const since = initial ? 0 : lastDmId;
            url = 'api/dm_messages.php?with=' + encodeURIComponent(dmPartner) +
                  (since > 0 ? '&since=' + since : '');
        } else if (currentMode === 'group_chat' && currentGroupId) {
            const since = initial ? 0 : lastGroupMsgId;
            url = 'api/group_messages.php?group_id=' + currentGroupId +
                  (since > 0 ? '&since=' + since : '');
        } else {
            return Promise.resolve();
        }

        return csrfFetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (currentMode === 'dm') {
                    if (typeof data.peer_last_read !== 'undefined') {
                        const newPeer = parseInt(data.peer_last_read, 10);
                        if (newPeer > peerLastRead) {
                            peerLastRead = newPeer;
                            updateTicks();
                        }
                    }
                }

                const msgs = data.messages || [];
                if (msgs.length === 0) return;

                let newFromOthers = 0;
                const atBottom =
                    messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 60;

                const fragment = document.createDocumentFragment();
                msgs.forEach(msg => {
                    // Track highest seen ID for the right mode
                    if (currentMode === 'dm') {
                        lastDmId = Math.max(lastDmId, parseInt(msg.id, 10));
                    } else if (currentMode === 'group_chat') {
                        lastGroupMsgId = Math.max(lastGroupMsgId, parseInt(msg.id, 10));
                    } else {
                        lastMessageId = Math.max(lastMessageId, parseInt(msg.id, 10));
                    }

                    const existing = messagesEl.querySelector('.message[data-id="' + msg.id + '"]');
                    if (existing) {
                        if (msg.deleted_at && !existing.classList.contains('deleted')) {
                            // In DM mode, remove the element entirely; otherwise show tombstone
                            if (currentMode === 'dm') {
                                existing.remove();
                            } else {
                                markDeletedInDOM(existing);
                            }
                        } else if (msg.edited_at && !existing.classList.contains('deleted')) {
                            const bubble = existing.querySelector('.message-bubble:not(.deleted-bubble):not(.message-image-bubble):not(.message-audio-bubble)');
                            if (bubble && !bubble.querySelector('.edit-textarea')) {
                                const timeSpan = bubble.querySelector('.bubble-time');
                                Array.from(bubble.childNodes).forEach(node => {
                                    if (node !== timeSpan) node.remove();
                                });
                                bubble.insertBefore(document.createTextNode(msg.content || ''), timeSpan);
                                if (timeSpan && !timeSpan.querySelector('.msg-edited')) {
                                    const editedSpan = document.createElement('span');
                                    editedSpan.className = 'msg-edited';
                                    editedSpan.textContent = 'editado';
                                    timeSpan.insertBefore(editedSpan, timeSpan.firstChild);
                                }
                            }
                        }
                        return;
                    }

                    // In DM mode, skip rendering messages that are already deleted
                    if (currentMode === 'dm' && msg.deleted_at) return;

                    if (!initial && msg.username !== CURRENT_USER && !msg.deleted_at) {
                        newFromOthers++;
                    }

                    fragment.appendChild(renderMessage(msg));
                });
                messagesEl.appendChild(fragment);

                if (initial || atBottom) {
                    scrollToBottom(true);
                }

                if (newFromOthers > 0) {
                    playNotificationSound();
                }

                hideStatus();
            })
            .catch(() => {
                consecutiveFail++;
                if (consecutiveFail >= 3) {
                    showStatus('⚠️ Sem conexão – tentando reconectar…');
                }
            });
    }

    // ── Load online users ─────────────────────────────────────────────────────

    function loadUsers() {
        csrfFetch('api/users.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const users       = data.users   || [];
                const typingUsers = data.typing  || [];
                const onlineMap   = data.online  || {};
                // Update global avatar map
                if (data.avatars && typeof data.avatars === 'object') {
                    Object.assign(avatarMap, data.avatars);
                }

                usersListEl.innerHTML = '';
                users.forEach(function (username) {
                    const isOnline = !!onlineMap[username];
                    const li = document.createElement('li');
                    li.className = 'wa-user-item' +
                        (username === CURRENT_USER ? ' is-me' : '') +
                        (currentMode === 'dm' && username === dmPartner ? ' dm-active' : '') +
                        (isOnline ? ' user-online' : ' user-offline');
                    li.dataset.username = username;

                    const label = username === CURRENT_USER
                        ? escapeHtml(username) + ' <em style="font-weight:400;color:var(--wa-muted)">(você)</em>'
                        : escapeHtml(username);

                    // Online/offline dot
                    const dotHtml = '<span class="wa-user-dot ' + (isOnline ? 'dot-on' : 'dot-off') + '" title="' + (isOnline ? 'Online' : 'Offline') + '"></span>';

                    // DM button (only for other users)
                    const dmBtnHtml = username !== CURRENT_USER
                        ? '<button type="button" class="wa-user-dm-btn" title="Mensagem privada para ' + escapeHtml(username) + '" data-dm="' + escapeHtml(username) + '">' +
                          '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>' +
                          '</button>'
                        : '';

                    li.innerHTML =
                        makeAvatarHtml(username, 40) +
                        '<span class="wa-user-name">' + label + '</span>' +
                        dotHtml +
                        dmBtnHtml;

                    usersListEl.appendChild(li);
                });

                // DM button clicks
                usersListEl.querySelectorAll('.wa-user-dm-btn').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        openDM(btn.dataset.dm);
                    });
                });

                // Also allow clicking the user row itself
                usersListEl.querySelectorAll('.wa-user-item:not(.is-me)').forEach(function (li) {
                    li.addEventListener('click', function (e) {
                        if (e.target.closest('.wa-user-dm-btn')) return;
                        openDM(li.dataset.username);
                    });
                });

                // Update status line in DM mode
                if (panelStatus && currentMode === 'dm') {
                    const partnerOnline = !!onlineMap[dmPartner];
                    if (typingUsers.indexOf(dmPartner) !== -1) {
                        panelStatus.innerHTML =
                            '<span class="typing-label">digitando' +
                            '<span class="typing-dots"><span></span><span></span><span></span></span></span>';
                    } else {
                        const dotClass = partnerOnline ? 'dot-on' : 'dot-off';
                        const label    = partnerOnline ? 'Online' : 'Offline';
                        panelStatus.innerHTML =
                            '<span class="panel-status-dot ' + dotClass + '" aria-hidden="true"></span>' +
                            '<span>' + label + '</span>';
                    }
                }
            })
            .catch(function () { /* non-critical */ });
    }

    // ── Load groups ───────────────────────────────────────────────────────────

    function loadGroups() {
        csrfFetch('api/groups.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                groups = data.groups || [];
                renderGroupsList();
            })
            .catch(function () { /* non-critical */ });
    }

    function renderGroupsList() {
        groupsListEl.innerHTML = '';
        if (!groups.length) {
            groupsListEl.innerHTML = '<li class="wa-no-groups">Nenhum grupo ainda. Crie um!</li>';
            return;
        }
        groups.forEach(function (g) {
            const li = document.createElement('li');
            li.className = 'wa-group-item' +
                (currentMode === 'group_chat' && currentGroupId === g.id ? ' dm-active' : '');
            li.dataset.groupId = g.id;

            const roleIcon = g.role === 'admin' ? '<span class="group-admin-badge" title="Você é admin">👑</span>' : '';
            li.innerHTML =
                '<span class="wa-group-icon">👥</span>' +
                '<span class="wa-user-name">' + escapeHtml(g.name) + roleIcon + '</span>' +
                '<span class="wa-group-count">' + g.member_count + ' membros</span>';

            li.addEventListener('click', function () {
                openGroupChat(g.id, g.name, g.role);
            });

            groupsListEl.appendChild(li);
        });
    }

    // ── Send a message ────────────────────────────────────────────────────────

    chatForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const content  = messageInput.value.trim();
        const hasImage = imageInput.files && imageInput.files.length > 0;
        const hasAudio = audioBlob !== null;
        const hasFile  = fileAttachment !== null;

        if (!content && !hasImage && !hasAudio && !hasFile) return;

        emojiPicker.hidden = true;

        const btn = sendBtn;
        btn.disabled = true;
        messageInput.disabled = true;

        const body = new FormData();
        body.append('content', content);
        if (hasImage) {
            body.append('image', imageInput.files[0]);
        }
        if (hasAudio) {
            const mimeExtMap = {
                'audio/ogg': 'ogg', 'audio/webm': 'webm',
                'audio/mp4': 'mp4', 'audio/mpeg': 'mp3', 'audio/x-m4a': 'm4a',
            };
            const ext = mimeExtMap[audioBlob.type.split(';')[0]] || 'webm';
            body.append('audio', audioBlob, 'audio.' + ext);
        }
        if (hasFile) {
            body.append('file', fileAttachment, fileAttachment.name);
        }

        // Choose endpoint based on mode
        let endpoint;
        if (currentMode === 'dm' && dmPartner) {
            endpoint = 'api/dm_send.php';
            body.append('to', dmPartner);
        } else if (currentMode === 'group_chat' && currentGroupId) {
            endpoint = 'api/group_send.php';
            body.append('group_id', currentGroupId);
        } else {
            // No conversation open — shouldn't happen, but guard anyway
            return;
        }

        csrfFetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        })
            .then(r => {
                if (!r.ok) {
                    return r.json().catch(() => ({ error: 'Erro ' + r.status + ' no servidor' }));
                }
                return r.json();
            })
            .then(data => {
                if (data.ok) {
                    messageInput.value = '';
                    autoResize();
                    imageInput.value = '';
                    imagePreview.src = '';
                    if (imagePreviewWrap) imagePreviewWrap.hidden = true;
                    discardAudioPreview();
                    discardFilePreview();
                    updateAttachmentsZone();
                    updateSendMicVisibility();
                    clearTimeout(pollTimer);
                    poll();
                } else {
                    showStatus('❌ Falha ao enviar: ' + (data.error || 'erro desconhecido'));
                }
            })
            .catch(() => {
                showStatus('❌ Mensagem não enviada. Verifique sua conexão.');
            })
            .finally(() => {
                btn.disabled = false;
                messageInput.disabled = false;
                messageInput.focus();
            });
    });

    // ── Enter = newline; Ctrl/Cmd+Enter = submit ──────────────────────────────
    messageInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            chatForm.dispatchEvent(new Event('submit'));
        }
        // Plain Enter inserts a newline (default textarea behaviour – nothing to override)
    });

    // ── Polling loop ──────────────────────────────────────────────────────────

    function poll() {
        loadMessages(false).finally(() => {
            loadUsers();
            loadGroups();
            pollTimer = setTimeout(poll, 2000);
        });
    }

    // ── Music player ──────────────────────────────────────────────────────────

    let playlist        = [];   // [{ id, title, artist, url }]
    let playerIndex     = -1;   // current track index
    let playerPlaying   = false;

    // Wrap an index into [0, length) handling negative values
    function wrapIndex(index, length) {
        return ((index % length) + length) % length;
    }

    function playerLoadTrack(index) {
        if (!playlist.length) return;
        playerIndex = wrapIndex(index, playlist.length);
        const track = playlist[playerIndex];
        musicPlayerAudio.src = track.url;
        if (playerTrackTitle)  playerTrackTitle.textContent  = track.title;
        if (playerTrackArtist) playerTrackArtist.textContent = track.artist || '';
        // Highlight active track in profile list
        document.querySelectorAll('.playlist-track-item').forEach(function (el, i) {
            el.classList.toggle('is-playing', i === playerIndex);
        });
    }

    function playerUpdatePlayPauseIcon() {
        if (!playerPlayIcon || !playerPauseIcon) return;
        playerPlayIcon.style.display  = playerPlaying ? 'none'        : '';
        playerPauseIcon.style.display = playerPlaying ? ''            : 'none';
    }

    function playerPlay() {
        if (!playlist.length) return;
        if (playerIndex < 0) playerLoadTrack(0);
        musicPlayerAudio.play().then(function () {
            playerPlaying = true;
            playerUpdatePlayPauseIcon();
            if (musicPlayerBtn) musicPlayerBtn.classList.add('is-playing');
        }).catch(function (err) {
            // Autoplay policy or missing file — reflect paused state
            playerPlaying = false;
            playerUpdatePlayPauseIcon();
            if (musicPlayerBtn) musicPlayerBtn.classList.remove('is-playing');
            if (err && err.name !== 'AbortError') {
                showStatus('⚠️ Não foi possível reproduzir: ' + (err.message || err));
            }
        });
    }

    function playerPause() {
        musicPlayerAudio.pause();
        playerPlaying = false;
        playerUpdatePlayPauseIcon();
        if (musicPlayerBtn) musicPlayerBtn.classList.remove('is-playing');
    }

    function playerToggle() {
        if (playerPlaying) { playerPause(); } else { playerPlay(); }
    }

    if (musicPlayerAudio) {
        musicPlayerAudio.addEventListener('ended', function () {
            if (playlist.length > 1) {
                playerLoadTrack(playerIndex + 1);
                playerPlay();
            } else {
                playerPlaying = false;
                playerUpdatePlayPauseIcon();
                if (musicPlayerBtn) musicPlayerBtn.classList.remove('is-playing');
            }
        });
    }

    if (playerPlayPause) playerPlayPause.addEventListener('click', playerToggle);
    if (playerPrev)      playerPrev.addEventListener('click', function () {
        playerLoadTrack(playerIndex - 1); if (playerPlaying) playerPlay();
    });
    if (playerNext)      playerNext.addEventListener('click', function () {
        playerLoadTrack(playerIndex + 1); if (playerPlaying) playerPlay();
    });

    // Volume slider
    if (playerVolume && musicPlayerAudio) {
        playerVolume.addEventListener('input', function () {
            musicPlayerAudio.volume = playerVolume.value / 100;
            if (musicPlayerAudio.muted) {
                musicPlayerAudio.muted = false;
            }
            updateMuteIcon();
        });
    }

    // Mute toggle button
    function updateMuteIcon() {
        const isMuted = musicPlayerAudio && (musicPlayerAudio.muted || (playerVolume && parseInt(playerVolume.value, 10) === 0));
        if (playerVolIcon)  playerVolIcon.style.display  = isMuted ? 'none' : '';
        if (playerMuteIcon) playerMuteIcon.style.display = isMuted ? '' : 'none';
        if (playerMuteBtn)  playerMuteBtn.title = isMuted ? 'Ativar som' : 'Silenciar';
    }

    if (playerMuteBtn && musicPlayerAudio) {
        playerMuteBtn.addEventListener('click', function () {
            musicPlayerAudio.muted = !musicPlayerAudio.muted;
            updateMuteIcon();
        });
        updateMuteIcon();
    }

    if (musicPlayerBtn) {
        musicPlayerBtn.addEventListener('click', function () {
            if (!musicPlayerBar) return;
            const wasHidden = musicPlayerBar.hidden;
            musicPlayerBar.hidden = !wasHidden;
            if (!wasHidden && playerPlaying) playerPause();
        });
    }

    // ── Fullscreen toggle ─────────────────────────────────────────────────────

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function requestFullscreen(el) {
        if (el.requestFullscreen) {
            return el.requestFullscreen();
        } else if (el.webkitRequestFullscreen) {
            return el.webkitRequestFullscreen();
        }
        return Promise.reject(new Error('Fullscreen não suportado neste navegador'));
    }

    function exitFullscreen() {
        if (document.exitFullscreen) {
            return document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            return document.webkitExitFullscreen();
        }
        return Promise.reject(new Error('Fullscreen não suportado neste navegador'));
    }

    function updateFullscreenIcons() {
        const isFull = isFullscreen();
        if (fullscreenEnterIcon) fullscreenEnterIcon.style.display = isFull ? 'none' : '';
        if (fullscreenExitIcon)  fullscreenExitIcon.style.display  = isFull ? '' : 'none';
        if (fullscreenBtn) fullscreenBtn.title = isFull ? 'Sair da tela cheia' : 'Tela cheia';
    }

    // Hide fullscreen button when the API is completely unsupported
    if (fullscreenBtn) {
        const fsSupported = !!(document.documentElement.requestFullscreen ||
                               document.documentElement.webkitRequestFullscreen);
        if (!fsSupported) {
            fullscreenBtn.hidden = true;
        } else {
            fullscreenBtn.addEventListener('click', function () {
                if (!isFullscreen()) {
                    requestFullscreen(document.documentElement).catch(function (err) {
                        showStatus('⚠️ Tela cheia não permitida: ' + (err && err.message ? err.message : 'verifique as permissões do navegador'));
                    });
                } else {
                    exitFullscreen().catch(function () {});
                }
            });
            document.addEventListener('fullscreenchange', updateFullscreenIcons);
            document.addEventListener('webkitfullscreenchange', updateFullscreenIcons);
            updateFullscreenIcons();
        }
    }

    // ── Playlist management ───────────────────────────────────────────────────

    function renderPlaylist() {
        const list = document.getElementById('profile-playlist-list');
        if (!list) return;
        list.innerHTML = '';
        if (!playlist.length) {
            list.innerHTML = '<li class="playlist-empty-msg">Nenhuma música ainda</li>';
            return;
        }
        playlist.forEach(function (track, i) {
            const li = document.createElement('li');
            li.className = 'playlist-track-item' + (i === playerIndex ? ' is-playing' : '');
            li.innerHTML =
                '<button type="button" class="playlist-track-play" data-index="' + i + '" aria-label="Tocar ' + escapeHtml(track.title) + '">' +
                    '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>' +
                '</button>' +
                '<div class="playlist-track-info">' +
                    '<div class="playlist-track-name">' + escapeHtml(track.title) + '</div>' +
                    (track.artist ? '<div class="playlist-track-artist">' + escapeHtml(track.artist) + '</div>' : '') +
                '</div>' +
                '<button type="button" class="playlist-track-remove" data-id="' + track.id + '" aria-label="Remover ' + escapeHtml(track.title) + '">' +
                    '<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>' +
                '</button>';
            list.appendChild(li);
        });

        list.querySelectorAll('.playlist-track-play').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const idx = parseInt(btn.dataset.index, 10);
                if (idx === playerIndex && playerPlaying) {
                    playerPause();
                } else {
                    playerLoadTrack(idx);
                    playerPlay();
                    if (musicPlayerBar) musicPlayerBar.hidden = false;
                }
            });
        });

        list.querySelectorAll('.playlist-track-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const id = parseInt(btn.dataset.id, 10);
                if (!confirm('Remover esta música da playlist?')) return;
                const body = new FormData();
                body.append('action', 'remove');
                body.append('id', id);
                csrfFetch('api/playlist.php', { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            const removedIdx = playlist.findIndex(t => t.id === id);
                            playlist = playlist.filter(t => t.id !== id);
                            if (playerIndex >= playlist.length) playerIndex = playlist.length - 1;
                            if (removedIdx === playerIndex) {
                                playerPause();
                                if (playlist.length) { playerLoadTrack(playerIndex); } else { playerIndex = -1; }
                            }
                            renderPlaylist();
                        }
                    })
                    .catch(function () {});
            });
        });
    }

    function loadPlaylist() {
        csrfFetch('api/playlist.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    playlist = data.tracks || [];
                    renderPlaylist();
                }
            })
            .catch(function () {});
    }

    // Playlist add form
    const playlistAddForm     = document.getElementById('playlist-add-form');
    const playlistTitleInput  = document.getElementById('playlist-title-input');
    const playlistArtistInput = document.getElementById('playlist-artist-input');
    const playlistAudioFile   = document.getElementById('playlist-audio-file');
    const playlistFileName    = document.getElementById('playlist-file-name');
    const playlistAddMsg      = document.getElementById('playlist-add-msg');

    if (playlistAudioFile) {
        const playlistAudioBtn = document.getElementById('playlist-audio-btn');
        if (playlistAudioBtn) {
            playlistAudioBtn.addEventListener('click', function () { playlistAudioFile.click(); });
        }
        playlistAudioFile.addEventListener('change', function () {
            if (playlistFileName) {
                playlistFileName.textContent = playlistAudioFile.files[0]
                    ? playlistAudioFile.files[0].name
                    : 'Escolher áudio (MP3, M4A…)';
            }
        });
    }

    function showPlaylistMsg(text, type) {
        if (!playlistAddMsg) return;
        playlistAddMsg.textContent = text;
        playlistAddMsg.className = 'profile-edit-msg ' + type;
        playlistAddMsg.hidden = false;
        setTimeout(function () { playlistAddMsg.hidden = true; }, 4000);
    }

    if (playlistAddForm) {
        playlistAddForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const title = (playlistTitleInput && playlistTitleInput.value.trim()) || '';
            if (!title) { showPlaylistMsg('Informe o título da música.', 'error'); return; }
            if (!playlistAudioFile || !playlistAudioFile.files[0]) {
                showPlaylistMsg('Selecione um arquivo de áudio.', 'error'); return;
            }
            const btn = playlistAddForm.querySelector('#playlist-add-btn');
            if (btn) btn.disabled = true;

            const body = new FormData();
            body.append('action', 'add');
            body.append('title', title);
            body.append('artist', (playlistArtistInput && playlistArtistInput.value.trim()) || '');
            body.append('audio', playlistAudioFile.files[0]);

            csrfFetch('api/playlist.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.ok && data.track) {
                        playlist.push(data.track);
                        renderPlaylist();
                        if (playlistTitleInput)  playlistTitleInput.value  = '';
                        if (playlistArtistInput) playlistArtistInput.value = '';
                        if (playlistAudioFile)   playlistAudioFile.value   = '';
                        if (playlistFileName)    playlistFileName.textContent = 'Escolher áudio (MP3, M4A…)';
                        showPlaylistMsg('✅ Música adicionada!', 'ok');
                    } else {
                        showPlaylistMsg('❌ ' + (data.error || 'Erro ao adicionar'), 'error');
                    }
                })
                .catch(function () { showPlaylistMsg('❌ Erro de conexão', 'error'); })
                .finally(function () { if (btn) btn.disabled = false; });
        });
    }

    // Load playlist when profile modal opens
    if (profileModal) {
        const observer = new MutationObserver(function () {
            if (!profileModal.hidden) loadPlaylist();
        });
        observer.observe(profileModal, { attributes: true, attributeFilter: ['hidden'] });
    }

    // ── New Group modal ───────────────────────────────────────────────────────

    const newGroupBtn     = document.getElementById('new-group-btn');
    const newGroupModal   = document.getElementById('new-group-modal');
    const newGroupClose   = document.getElementById('new-group-close');
    const newGroupBackdrop = document.getElementById('new-group-backdrop');
    const newGroupForm    = document.getElementById('new-group-form');
    const newGroupMsg     = document.getElementById('new-group-msg');
    const newGroupNameInput = document.getElementById('new-group-name-input');
    const newGroupExtraInput = document.getElementById('new-group-extra-input');
    const newGroupMembersList = document.getElementById('new-group-members-list');

    function openNewGroupModal() {
        if (!newGroupModal) return;
        if (newGroupMsg) { newGroupMsg.hidden = true; newGroupMsg.textContent = ''; }
        if (newGroupNameInput) newGroupNameInput.value = '';
        if (newGroupExtraInput) newGroupExtraInput.value = '';
        // Populate checkboxes with online users (excluding self)
        if (newGroupMembersList) {
            newGroupMembersList.innerHTML = '';
            csrfFetch('api/users.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const users = (data.users || []).filter(u => u !== CURRENT_USER);
                    if (!users.length) {
                        newGroupMembersList.innerHTML = '<span style="color:var(--wa-muted);font-size:.85rem">Nenhum usuário online agora</span>';
                        return;
                    }
                    users.forEach(function (u) {
                        const label = document.createElement('label');
                        label.className = 'group-member-check-label';
                        label.innerHTML =
                            '<input type="checkbox" value="' + escapeHtml(u) + '">' +
                            makeAvatarHtml(u, 28) +
                            '<span>' + escapeHtml(u) + '</span>';
                        newGroupMembersList.appendChild(label);
                    });
                })
                .catch(function () {});
        }
        newGroupModal.hidden = false;
        document.body.style.overflow = 'hidden';
        if (newGroupNameInput) newGroupNameInput.focus();
    }

    function closeNewGroupModal() {
        if (newGroupModal) { newGroupModal.hidden = true; document.body.style.overflow = ''; }
    }

    if (newGroupBtn)    newGroupBtn.addEventListener('click', openNewGroupModal);
    if (newGroupClose)  newGroupClose.addEventListener('click', closeNewGroupModal);
    if (newGroupBackdrop) newGroupBackdrop.addEventListener('click', closeNewGroupModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && newGroupModal && !newGroupModal.hidden) closeNewGroupModal();
    });

    function showNewGroupMsg(text, type) {
        if (!newGroupMsg) return;
        newGroupMsg.textContent = text;
        newGroupMsg.className = 'profile-edit-msg ' + type;
        newGroupMsg.hidden = false;
    }

    if (newGroupForm) {
        newGroupForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = newGroupNameInput ? newGroupNameInput.value.trim() : '';
            if (!name) { showNewGroupMsg('Informe o nome do grupo.', 'error'); return; }

            // Collect checked members
            const checked = Array.from(newGroupMembersList.querySelectorAll('input[type=checkbox]:checked'))
                .map(cb => cb.value);
            // Plus any typed extras
            const extras = (newGroupExtraInput ? newGroupExtraInput.value : '')
                .split(',').map(s => s.trim()).filter(Boolean);
            const members = [...new Set([...checked, ...extras])];

            const submitBtn = newGroupForm.querySelector('button[type=submit]');
            if (submitBtn) submitBtn.disabled = true;

            const body = new FormData();
            body.append('action', 'create');
            body.append('name', name);
            body.append('members', members.join(','));

            csrfFetch('api/groups.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.ok && data.group) {
                        groups.unshift(data.group);
                        renderGroupsList();
                        closeNewGroupModal();
                        openGroupChat(data.group.id, data.group.name, data.group.role);
                    } else {
                        showNewGroupMsg('❌ ' + (data.error || 'Erro ao criar grupo'), 'error');
                    }
                })
                .catch(function () { showNewGroupMsg('❌ Erro de conexão', 'error'); })
                .finally(function () { if (submitBtn) submitBtn.disabled = false; });
        });
    }

    // ── Group settings modal ──────────────────────────────────────────────────

    const groupSettingsModal   = document.getElementById('group-settings-modal');
    const groupSettingsClose   = document.getElementById('group-settings-close');
    const groupSettingsBackdrop = document.getElementById('group-settings-backdrop');
    const groupSettingsTitle   = document.getElementById('group-settings-title');
    const groupSettingsMsg     = document.getElementById('group-settings-msg');
    const groupSettingsMembers = document.getElementById('group-settings-members');
    const groupRenameSection   = document.getElementById('group-rename-section');
    const groupRenameForm      = document.getElementById('group-rename-form');
    const groupRenameInput     = document.getElementById('group-rename-input');
    const groupAddMemberSection = document.getElementById('group-add-member-section');
    const groupAddMemberForm   = document.getElementById('group-add-member-form');
    const groupAddMemberInput  = document.getElementById('group-add-member-input');
    const groupLeaveBtn        = document.getElementById('group-leave-btn');
    const groupDeleteBtn       = document.getElementById('group-delete-btn');

    function openGroupSettingsModal() {
        if (!groupSettingsModal || !currentGroupId) return;
        if (groupSettingsMsg) { groupSettingsMsg.hidden = true; groupSettingsMsg.textContent = ''; }

        const gObj = groups.find(g => g.id === currentGroupId);
        if (groupSettingsTitle) groupSettingsTitle.textContent = '⚙️ ' + (gObj ? gObj.name : 'Grupo');
        if (groupRenameInput && gObj) groupRenameInput.value = gObj.name;

        const isAdmin = currentGroupRole === 'admin';
        if (groupRenameSection)   groupRenameSection.hidden   = !isAdmin;
        if (groupAddMemberSection) groupAddMemberSection.hidden = !isAdmin;
        if (groupDeleteBtn)       groupDeleteBtn.hidden       = !isAdmin;

        // Load members
        if (groupSettingsMembers) {
            groupSettingsMembers.innerHTML = '<li style="color:var(--wa-muted);font-size:.85rem;list-style:none">Carregando…</li>';
        }

        csrfFetch('api/groups.php?action=members&group_id=' + currentGroupId, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!groupSettingsMembers) return;
                groupSettingsMembers.innerHTML = '';
                (data.members || []).forEach(function (m) {
                    const li = document.createElement('li');
                    li.className = 'group-settings-member-item';
                    li.dataset.userId = m.id;
                    li.dataset.username = m.username;

                    const roleTag = m.role === 'admin'
                        ? '<span class="group-admin-badge">👑 Admin</span>'
                        : '<span class="group-member-tag">Membro</span>';

                    let actions = '';
                    if (isAdmin && m.username !== CURRENT_USER) {
                        const toggleLabel = m.role === 'admin' ? 'Remover admin' : 'Tornar admin';
                        actions =
                            '<button type="button" class="gm-role-btn" data-uid="' + m.id + '" data-role="' + (m.role === 'admin' ? 'member' : 'admin') + '">' + toggleLabel + '</button>' +
                            '<button type="button" class="gm-remove-btn" data-uid="' + m.id + '">Remover</button>';
                    }

                    li.innerHTML = makeAvatarHtml(m.username, 32) +
                        '<span class="gm-name">' + escapeHtml(m.username) + '</span>' +
                        roleTag +
                        (actions ? '<span class="gm-actions">' + actions + '</span>' : '');
                    groupSettingsMembers.appendChild(li);
                });

                // Role toggle
                groupSettingsMembers.querySelectorAll('.gm-role-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const body = new FormData();
                        body.append('action', 'set_role');
                        body.append('group_id', currentGroupId);
                        body.append('user_id', btn.dataset.uid);
                        body.append('role', btn.dataset.role);
                        csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(d => {
                                if (d.ok) {
                                    openGroupSettingsModal(); // refresh
                                    loadGroups();
                                } else {
                                    showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                                }
                            })
                            .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
                    });
                });

                // Remove member
                groupSettingsMembers.querySelectorAll('.gm-remove-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Remover este membro do grupo?')) return;
                        const body = new FormData();
                        body.append('action', 'remove_member');
                        body.append('group_id', currentGroupId);
                        body.append('user_id', btn.dataset.uid);
                        csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(d => {
                                if (d.ok) {
                                    openGroupSettingsModal(); // refresh
                                    loadGroups();
                                } else {
                                    showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                                }
                            })
                            .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
                    });
                });
            })
            .catch(function () {});

        groupSettingsModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeGroupSettingsModal() {
        if (groupSettingsModal) { groupSettingsModal.hidden = true; document.body.style.overflow = ''; }
    }

    function showGroupSettingsMsg(text, type) {
        if (!groupSettingsMsg) return;
        groupSettingsMsg.textContent = text;
        groupSettingsMsg.className = 'profile-edit-msg ' + type;
        groupSettingsMsg.hidden = false;
    }

    if (groupSettingsBtn) groupSettingsBtn.addEventListener('click', openGroupSettingsModal);
    if (groupSettingsClose) groupSettingsClose.addEventListener('click', closeGroupSettingsModal);
    if (groupSettingsBackdrop) groupSettingsBackdrop.addEventListener('click', closeGroupSettingsModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && groupSettingsModal && !groupSettingsModal.hidden) closeGroupSettingsModal();
    });

    // Rename group
    if (groupRenameForm) {
        groupRenameForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = groupRenameInput ? groupRenameInput.value.trim() : '';
            if (!name) return;
            const body = new FormData();
            body.append('action', 'rename');
            body.append('group_id', currentGroupId);
            body.append('name', name);
            csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        // Update local groups cache
                        const g = groups.find(g => g.id === currentGroupId);
                        if (g) { g.name = name; }
                        if (panelName) panelName.textContent = name;
                        renderGroupsList();
                        showGroupSettingsMsg('✅ Grupo renomeado!', 'ok');
                        if (groupSettingsTitle) groupSettingsTitle.textContent = '⚙️ ' + name;
                    } else {
                        showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                    }
                })
                .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
        });
    }

    // Add member
    if (groupAddMemberForm) {
        groupAddMemberForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const username = groupAddMemberInput ? groupAddMemberInput.value.trim() : '';
            if (!username) return;
            const body = new FormData();
            body.append('action', 'add_member');
            body.append('group_id', currentGroupId);
            body.append('username', username);
            csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        if (groupAddMemberInput) groupAddMemberInput.value = '';
                        openGroupSettingsModal(); // refresh member list
                        loadGroups();
                        showGroupSettingsMsg('✅ Membro adicionado!', 'ok');
                    } else {
                        showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                    }
                })
                .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
        });
    }

    // Leave group
    if (groupLeaveBtn) {
        groupLeaveBtn.addEventListener('click', function () {
            if (!confirm('Sair do grupo?')) return;
            const body = new FormData();
            body.append('action', 'leave');
            body.append('group_id', currentGroupId);
            csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        groups = groups.filter(g => g.id !== currentGroupId);
                        renderGroupsList();
                        closeGroupSettingsModal();
                        showWelcomeState();
                    } else {
                        showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                    }
                })
                .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
        });
    }

    // Delete group
    if (groupDeleteBtn) {
        groupDeleteBtn.addEventListener('click', function () {
            if (!confirm('Excluir o grupo e todas as mensagens? Esta ação não pode ser desfeita.')) return;
            const body = new FormData();
            body.append('action', 'delete');
            body.append('group_id', currentGroupId);
            csrfFetch('api/group_manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        groups = groups.filter(g => g.id !== currentGroupId);
                        renderGroupsList();
                        closeGroupSettingsModal();
                        showWelcomeState();
                    } else {
                        showGroupSettingsMsg('❌ ' + (d.error || 'Erro'), 'error');
                    }
                })
                .catch(function () { showGroupSettingsMsg('❌ Erro de conexão', 'error'); });
        });
    }

    // ── Initialise ────────────────────────────────────────────────────────────

    showWelcomeState();
    loadUsers();
    loadGroups();
    pollTimer = setTimeout(poll, 2000);
    autoResize();
    updateSendMicVisibility();

})();
