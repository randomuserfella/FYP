<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once 'config/db.php';
require_once 'includes/lecturer_header.php';
$user_id = (int)$_SESSION['user_id'];
?>
<style>
.chat-wrap{display:flex;height:calc(100vh - 118px);border-radius:16px;overflow:hidden;border:1px solid var(--border);background:var(--bg-surface)}
/* Sidebar */
.chat-sidebar{width:265px;min-width:265px;border-right:1px solid var(--border);display:flex;flex-direction:column;background:var(--bg-surface)}
.chat-sidebar-head{padding:14px 14px 10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.chat-sidebar-title{font-size:14px;font-weight:700;color:var(--text-main);display:flex;align-items:center;gap:7px}
.sidebar-actions{display:flex;gap:5px}
.icon-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:all 0.15s}
.icon-btn:hover{background:rgba(124,106,247,0.1);border-color:#7c6af7;color:#7c6af7}
.chat-search{padding:8px 10px;border-bottom:1px solid var(--border)}
.chat-search input{width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12px;color:var(--text-main);outline:none}
.chat-search input:focus{border-color:var(--accent)}
.chat-rooms{flex:1;overflow-y:auto;padding:6px}
.room-section-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;padding:8px 8px 4px}
.room-item{display:flex;align-items:center;gap:9px;padding:9px 8px;border-radius:10px;cursor:pointer;transition:background 0.15s;margin-bottom:1px}
.room-item:hover{background:rgba(124,106,247,0.07)}
.room-item.active{background:rgba(124,106,247,0.14)}
.room-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.room-avatar.class-av{background:rgba(124,106,247,0.18)}
.room-avatar.dm-av{background:rgba(106,207,106,0.18)}
.room-avatar.group-av{background:rgba(247,186,106,0.18)}
.room-meta{flex:1;min-width:0}
.room-name{font-size:13px;font-weight:600;color:var(--text-main);white-space:normal;word-break:break-word;line-height:1.3}
.room-last{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.room-unread-bubble{min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#e05a5a;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.room-new-badge{padding:2px 7px;border-radius:9px;background:#7c6af7;color:#fff;font-size:9px;font-weight:700;letter-spacing:.3px;flex-shrink:0}
.invites-banner{display:none;align-items:center;gap:8px;margin:0 6px 6px;padding:9px 10px;border-radius:10px;background:rgba(247,106,106,0.12);border:1px solid rgba(247,106,106,0.35);color:#f76a6a;font-size:12px;font-weight:600;cursor:pointer}
.invites-banner.show{display:flex}
.invites-banner i.bi-bell-fill{font-size:13px}
.invites-banner i.bi-chevron-right{margin-left:auto;font-size:11px;opacity:.7}
/* Main */
.chat-main{flex:1;display:flex;flex-direction:column;min-width:0}
.chat-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--bg-surface)}
.chat-header-info{flex:1;min-width:0}
.chat-header-title{font-size:14px;font-weight:700;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-header-sub{font-size:11px;color:var(--text-muted)}
.chat-header-actions{display:flex;gap:5px;flex-shrink:0}
.chat-messages{flex:1;overflow-y:auto;padding:16px 16px 8px;display:flex;flex-direction:column;gap:8px}
.msg-row{display:flex;gap:8px;align-items:flex-end}
.msg-row.mine{flex-direction:row-reverse}
.msg-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;background:rgba(124,106,247,0.2)}
.msg-av.lec{background:rgba(247,106,106,0.2)}
.msg-bubble{max-width:100%;min-width:60px;padding:8px 12px;border-radius:14px;font-size:13px;line-height:1.5;color:var(--text-main);word-break:break-word;white-space:pre-wrap;overflow-wrap:anywhere}
.msg-bubble.other{background:var(--bg-input);border-bottom-left-radius:4px}
.msg-bubble.mine{background:#7c6af7;color:#fff;border-bottom-right-radius:4px}
.msg-sender{font-size:10px;color:var(--text-muted);margin-bottom:2px;font-weight:600}
.lec-badge{display:inline-block;background:rgba(247,106,106,0.18);color:#f76a6a;font-size:9px;padding:1px 5px;border-radius:4px;margin-left:3px;font-weight:700;vertical-align:middle}
.msg-time{font-size:10px;color:var(--text-muted);margin-top:2px}
.msg-row.mine .msg-time{text-align:right}
/* ── Selection mode ─────────────────────────────────────────────────── */
.msg-row{position:relative;border-radius:10px;padding:2px 4px;margin:0 -4px;transition:background 0.12s}
.msg-row.selected{background:rgba(124,106,247,0.13)}
.msg-check{width:22px;height:22px;border-radius:50%;border:2px solid var(--border);background:var(--bg-surface);flex-shrink:0;display:none;align-items:center;justify-content:center;cursor:pointer;transition:all 0.15s;font-size:12px;color:transparent}
.select-mode .msg-check{display:flex}
.msg-row.selected .msg-check{background:#7c6af7;border-color:#7c6af7;color:#fff}
.select-mode .msg-row{cursor:pointer;user-select:none}
.select-mode .msg-row:hover:not(.selected){background:rgba(124,106,247,0.06)}
.msg-row.mine .msg-check{order:1}
.sel-bar{padding:10px 12px;border-top:1px solid var(--border);background:var(--bg-surface);display:none;align-items:center;gap:8px;flex-shrink:0}
.sel-bar.show{display:flex}
.sel-count{font-size:13px;font-weight:600;color:var(--text-main);flex:1;white-space:nowrap}
.btn-sel{padding:6px 13px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;transition:all 0.15s}
.btn-sel:hover{border-color:#7c6af7;color:#7c6af7}
.btn-sel.del{color:#d9534f;border-color:rgba(217,83,79,0.35)}
.btn-sel.del:hover{background:rgba(217,83,79,0.08);border-color:#d9534f}
.btn-sel.cancel{background:rgba(124,106,247,0.1);border-color:rgba(124,106,247,0.3);color:#7c6af7}
.btn-sel.cancel:hover{background:rgba(124,106,247,0.18)}
.date-divider{text-align:center;font-size:11px;color:var(--text-muted);margin:4px 0;position:relative}
.date-divider::before,.date-divider::after{content:'';position:absolute;top:50%;width:42%;height:1px;background:var(--border)}
.date-divider::before{left:0}.date-divider::after{right:0}
.chat-input-bar{padding:10px 12px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:flex-end;background:var(--bg-surface)}
.chat-ta{flex:1;background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:9px 13px;font-size:13px;color:var(--text-main);resize:none;min-height:40px;max-height:110px;outline:none;transition:border-color 0.2s;font-family:inherit;line-height:1.4}
.chat-ta:focus{border-color:#7c6af7}
.btn-send{width:40px;height:40px;border-radius:12px;background:#7c6af7;border:none;color:#fff;font-size:17px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity 0.15s}
.btn-send:hover{opacity:0.85}.btn-send:disabled{opacity:0.4;cursor:not-allowed}
.no-room{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--text-muted);gap:8px;font-size:13px}
/* Members panel — fixed overlay so it's never clipped */
.members-panel{
    position:fixed; top:0; right:0; bottom:0;
    width:260px; max-width:85vw;
    border-left:1px solid var(--border);
    display:none; flex-direction:column;
    background:var(--bg-surface);
    z-index:800;
    box-shadow:-4px 0 24px rgba(0,0,0,0.18);
}
.members-panel.open{display:flex}
.members-head{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text-main);display:flex;align-items:center;justify-content:space-between}
.members-list{flex:1;overflow-y:auto;padding:8px}
.member-row{display:flex;align-items:center;gap:8px;padding:7px 6px;border-radius:8px}
.member-row:hover{background:rgba(124,106,247,0.05)}
.member-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;background:rgba(124,106,247,0.15);flex-shrink:0}
.member-av.lec{background:rgba(247,106,106,0.15)}
.member-name{flex:1;font-size:12px;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.member-role{font-size:10px;color:var(--text-muted)}
.btn-remove{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:13px;padding:2px 4px;border-radius:4px}
.btn-remove:hover{color:#f76a6a}
.add-member-btn{margin:6px 8px;padding:7px;border-radius:8px;border:1px dashed var(--border);background:transparent;color:var(--text-muted);font-size:12px;cursor:pointer;width:calc(100% - 16px);transition:all 0.15s}
.add-member-btn:hover{border-color:#7c6af7;color:#7c6af7}
/* Info popover (explains sidebar icon buttons) */
.info-popover-wrap{position:relative}
.info-popover{
    position:absolute;top:calc(100% + 8px);left:0;
    width:260px;max-width:min(260px, 85vw);
    background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;
    box-shadow:0 8px 24px rgba(0,0,0,0.18);
    padding:10px;z-index:120;display:none;
}
.info-popover.show{display:block}
.info-popover-row{display:flex;align-items:flex-start;gap:9px;padding:6px 4px;border-radius:8px;cursor:pointer;position:relative}
.popover-row-badge{position:absolute;top:6px;right:4px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:#f76a6a;color:#fff;font-size:9px;font-weight:700;display:none;align-items:center;justify-content:center;line-height:1}
.popover-row-badge.show{display:flex}
.info-popover-row:hover{background:rgba(124,106,247,0.06)}
.info-popover-icon{width:26px;height:26px;border-radius:7px;background:rgba(124,106,247,0.12);color:#7c6af7;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.info-popover-text{flex:1;min-width:0}
.info-popover-title{font-size:12px;font-weight:700;color:var(--text-main)}
.info-popover-desc{font-size:11px;color:var(--text-muted);margin-top:1px;line-height:1.35}
/* Invite badge (pending cross-institution requests) */
.icon-btn{position:relative}
.invite-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:#f76a6a;color:#fff;font-size:9px;font-weight:700;display:none;align-items:center;justify-content:center;line-height:1}
.invite-badge.show{display:flex}
.invite-row{display:flex;align-items:flex-start;gap:9px;padding:10px;border-radius:10px;border:1px solid var(--border);margin-bottom:8px;background:var(--bg-input)}
.invite-row-av{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:rgba(124,106,247,0.18)}
.invite-row-av.lec{background:rgba(247,106,106,0.18)}
.invite-row-body{flex:1;min-width:0}
.invite-row-name{font-size:13px;font-weight:700;color:var(--text-main)}
.invite-row-meta{font-size:11px;color:var(--text-muted);margin-top:1px}
.invite-row-msg{font-size:12px;color:var(--text-main);margin-top:6px;padding:7px 9px;background:var(--bg-surface);border-radius:8px;border:1px solid var(--border)}
.invite-row-btns{display:flex;gap:6px;margin-top:8px}
.btn-invite{flex:1;padding:6px 0;border-radius:7px;border:none;font-size:12px;font-weight:600;cursor:pointer;transition:opacity 0.15s}
.btn-invite:hover{opacity:0.85}
.btn-invite.accept{background:#28a745;color:#fff}
.btn-invite.deny{background:transparent;color:#d9534f;border:1px solid rgba(217,83,79,0.4)}
/* Modals */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9000;display:none;align-items:center;justify-content:center}
.overlay.show{display:flex}
.modal-box{background:var(--bg-surface);border:1px solid var(--border);border-radius:16px;padding:24px;width:360px;max-width:95vw;max-height:80vh;overflow-y:auto}
.modal-title{font-size:16px;font-weight:700;color:var(--text-main);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.modal-input{width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:13px;color:var(--text-main);outline:none;margin-bottom:12px;box-sizing:border-box}
.modal-input:focus{border-color:#7c6af7}
.user-pick-list{max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;margin-bottom:12px}
.user-pick-row{display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;transition:background 0.1s;border-bottom:1px solid var(--border)}
.user-pick-row:last-child{border-bottom:none}
.user-pick-row:hover{background:rgba(124,106,247,0.07)}
.user-pick-row.selected{background:rgba(124,106,247,0.13)}
.user-pick-row input{accent-color:#7c6af7}
.modal-btns{display:flex;gap:8px;justify-content:flex-end}
.btn-modal-cancel{padding:8px 16px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;font-size:13px}
.btn-modal-ok{padding:8px 18px;border-radius:8px;border:none;background:#7c6af7;color:#fff;cursor:pointer;font-size:13px;font-weight:600}
/* Focus mode (default): back btn hidden until room opens */
.mobile-back-btn { display: none; }
@media (max-width: 480px) { .layout-toggle-btn { display: none !important; } }

/* Split mode: sidebar always visible */
.chat-wrap.split-mode .chat-sidebar {
    width: 265px; min-width: 265px;
    position: static; transform: none !important; transition: none;
}
.chat-wrap.split-mode .chat-main {
    position: static; transform: none !important; transition: none;
}
.chat-wrap.split-mode .mobile-back-btn { display: none !important; }

/* ── CHAT MOBILE (phone: swipe panels, no split layout) ── */
@media (max-width: 480px) {
    .chat-wrap {
        height: calc(100vh - 118px);
        position: relative;
        overflow: hidden;
    }
    .chat-sidebar {
        width: 100% !important; min-width: 100% !important;
        position: absolute !important;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 2;
        transition: transform 0.25s ease !important;
    }
    .chat-main {
        position: absolute !important;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 1;
        transform: translateX(100%) !important;
        transition: transform 0.25s ease !important;
    }
    .members-panel { display: none !important; }

    .chat-wrap.room-open .chat-sidebar { transform: translateX(-100%) !important; }
    .chat-wrap.room-open .chat-main   { transform: translateX(0) !important; z-index: 2; }

    .mobile-back-btn {
        display: flex !important;
        align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 8px;
        border: 1px solid var(--border); background: transparent;
        color: var(--text-muted); cursor: pointer;
        font-size: 16px; flex-shrink: 0; margin-right: 4px;
    }
    .mobile-back-btn:hover { background: rgba(124,106,247,0.1); color: #7c6af7; }
}

/* Tablet/Desktop focus mode behaviour */
@media (min-width: 481px) {
    .chat-wrap:not(.split-mode) .chat-sidebar {
        transition: width 0.25s ease, min-width 0.25s ease;
    }
    .chat-wrap:not(.split-mode) .chat-main {
        transition: flex 0.25s ease;
    }
    .chat-wrap:not(.split-mode):not(.room-open) .chat-sidebar {
        width: 265px; min-width: 265px; position: static; transform: none;
    }
    .chat-wrap:not(.split-mode):not(.room-open) .chat-main {
        position: static; transform: none;
    }
    .chat-wrap:not(.split-mode).room-open {
        position: relative;
    }
    .chat-wrap:not(.split-mode).room-open .chat-sidebar {
        width: 0; min-width: 0; overflow: hidden;
        position: static; transform: none; border-right: none;
    }
    .chat-wrap:not(.split-mode).room-open .chat-main {
        position: static; transform: none; flex: 1;
    }
    .chat-wrap:not(.split-mode).room-open .mobile-back-btn { display: flex !important; }
}
</style>

<div class="page-topbar">
    <div class="page-title">💬 Chat</div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub" style="margin-bottom:10px">Class room, group chats & direct messages</div>

<div class="chat-wrap">
    <!-- Room sidebar -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-head">
            <span class="chat-sidebar-title"><i class="bi bi-chat-dots-fill" style="color:#7c6af7"></i> Messages</span>
            <div class="sidebar-actions">
                <button class="icon-btn" title="New direct message" onclick="openDmModal()"><i class="bi bi-person-plus"></i></button>
                <div class="info-popover-wrap">
                    <button class="icon-btn" title="More options" onclick="toggleInfoPopover(event)">
                        <i class="bi bi-three-dots"></i>
                        <span class="invite-badge" id="inviteBadge">0</span>
                    </button>
                    <div class="info-popover" id="chatInfoPopover">
                        <div class="info-popover-row" onclick="closeInfoPopoverThen(openInvitesModal)">
                            <div class="info-popover-icon"><i class="bi bi-bell-fill"></i></div>
                            <div class="info-popover-text">
                                <div class="info-popover-title">Invites</div>
                                <div class="info-popover-desc">Accept or deny cross-institution chat requests</div>
                            </div>
                            <span class="popover-row-badge" id="invitesRowBadge">0</span>
                        </div>
                        <div class="info-popover-row" onclick="closeInfoPopoverThen(openGroupModal)">
                            <div class="info-popover-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="info-popover-text">
                                <div class="info-popover-title">New Group Chat</div>
                                <div class="info-popover-desc">Create a room with multiple members</div>
                            </div>
                        </div>
                        <div class="info-popover-row" onclick="closeInfoPopoverThen(openClassModal)">
                            <div class="info-popover-icon"><i class="bi bi-building-add"></i></div>
                            <div class="info-popover-text">
                                <div class="info-popover-title">Create Class Room</div>
                                <div class="info-popover-desc">Start a class chat for your institution</div>
                            </div>
                        </div>
                        <div class="info-popover-row" onclick="closeInfoPopoverThen(toggleChatLayout)">
                            <div class="info-popover-icon"><i class="bi bi-layout-split"></i></div>
                            <div class="info-popover-text">
                                <div class="info-popover-title">Layout Toggle</div>
                                <div class="info-popover-desc">Switch between split view and focus view</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="chat-search"><input id="roomSearch" placeholder="🔍 Search rooms…" oninput="filterRooms(this.value)"></div>
        <div class="invites-banner" id="invitesBanner" onclick="openInvitesModal()">
            <i class="bi bi-bell-fill"></i>
            <span id="invitesBannerText">You have new invites</span>
            <i class="bi bi-chevron-right"></i>
        </div>
        <div class="chat-rooms" id="roomList"><div style="padding:20px;color:var(--text-muted);font-size:13px">Loading…</div></div>
    </div>

    <!-- Chat area -->
    <div class="chat-main" id="chatMain">
        <div class="no-room">
            <i class="bi bi-chat-square-text" style="font-size:44px;opacity:0.2"></i>
            <div>Select a room to start chatting</div>
            <div style="font-size:12px;opacity:0.6">or start a new direct message</div>
        </div>
    </div>

    <!-- Members panel (toggled) -->
    <div class="members-panel" id="membersPanel"></div>
</div>

<!-- Invites Modal -->
<div class="overlay" id="invitesOverlay">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-bell-fill" style="color:#7c6af7"></i> Chat Invites</div>
        <div id="invitesList"><div style="padding:12px;font-size:12px;color:var(--text-muted)">Loading…</div></div>
        <div class="modal-btns" style="margin-top:8px">
            <button class="btn-modal-cancel" onclick="closeOverlay('invitesOverlay')">Close</button>
        </div>
    </div>
</div>

<!-- DM Modal -->
<div class="overlay" id="dmOverlay">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-person-lines-fill" style="color:#7c6af7"></i> New Direct Message</div>
        <input class="modal-input" id="dmSearch" placeholder="Search members by name…" oninput="filterUserPick('dmSearch','dmUserList')">
        <!-- Cross-institution toggle (matches student chat) -->
        <label class="cross-toggle" style="margin-bottom:8px">
            <input type="checkbox" id="dmCrossToggle" onchange="toggleCrossInstitution()">
            Search other institutions
        </label>
        <!-- Institution picker — shown only when checkbox ticked -->
        <div id="dmInstWrap" style="display:none;position:relative;margin-bottom:10px">
            <input class="modal-input" id="dmInstSearch"
                   placeholder="Type institution name…"
                   oninput="filterInstSuggestions()"
                   onkeydown="handleInstSearchKeydown(event)"
                   autocomplete="off"
                   style="margin-bottom:0">
            <div id="dmInstSuggestions"
                 style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);
                        background:var(--bg-surface);border:1px solid var(--border);
                        border-radius:10px;z-index:200;max-height:180px;overflow-y:auto;
                        box-shadow:0 4px 16px rgba(0,0,0,0.15)"></div>
            <div id="dmInstSelected" style="display:none;margin-top:6px;font-size:11px;
                 color:#7c6af7;padding:4px 8px;background:rgba(124,106,247,0.1);
                 border-radius:6px;display:flex;align-items:center;gap:6px">
                <i class="bi bi-building-check"></i>
                <span id="dmInstSelectedName"></span>
                <span onclick="clearInstFilter()" style="margin-left:auto;cursor:pointer;
                      color:var(--text-muted);font-size:13px">✕</span>
            </div>
        </div>
        <div class="user-pick-list" id="dmUserList"></div>
        <input class="modal-input" id="dmInviteMsg" placeholder="Optional message (e.g. why you'd like to chat)…" style="margin-top:8px" maxlength="255">
        <div style="font-size:11px;color:var(--text-muted);margin:-4px 0 4px;padding:0 2px">
            <i class="bi bi-info-circle"></i> Different-institution recipients must accept before the chat opens.
        </div>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('dmOverlay')">Cancel</button>
            <button class="btn-modal-ok" onclick="startDm()">Start Chat</button>
        </div>
    </div>
</div>

<!-- Group Modal -->
<div class="overlay" id="groupOverlay">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-people-fill" style="color:#7c6af7"></i> New Group Chat</div>
        <input class="modal-input" id="groupName" placeholder="Group name…">
        <input class="modal-input" id="groupSearch" placeholder="Search members to add…" oninput="filterUserPick('groupSearch','groupUserList')">
        <div class="user-pick-list" id="groupUserList"></div>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('groupOverlay')">Cancel</button>
            <button class="btn-modal-ok" onclick="createGroup()">Create</button>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div class="overlay" id="addMemberOverlay">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-person-plus" style="color:#7c6af7"></i> Add Member</div>
        <input class="modal-input" id="addMemberSearch" placeholder="Search…" oninput="filterUserPick('addMemberSearch','addMemberList')">
        <div class="user-pick-list" id="addMemberList"></div>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('addMemberOverlay')">Cancel</button>
            <button class="btn-modal-ok" onclick="addMemberConfirm()">Add</button>
        </div>
    </div>
</div>

<!-- Clear Messages Confirm Modal -->
<div class="overlay" id="clearOverlay">
    <div class="modal-box" style="max-width:340px">
        <div class="modal-title" style="color:#d9534f"><i class="bi bi-trash3-fill"></i> Clear Messages</div>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px">This will permanently delete <strong>all messages</strong> in this room. This cannot be undone.</p>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('clearOverlay')">Cancel</button>
            <button class="btn-modal-ok" style="background:#d9534f" onclick="clearMessages()">Yes, Clear All</button>
        </div>
    </div>
</div>

<!-- Create Class Room Modal -->
<div class="overlay" id="classOverlay">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-building-add" style="color:#7c6af7"></i> Create Class Room</div>
        <input class="modal-input" id="className" placeholder="Class name (e.g. CS301 — Web Dev)…">
        <input class="modal-input" id="classUniversity" placeholder="Institution / university name…">
        <div style="font-size:12px;color:var(--text-muted);margin:-4px 0 8px;padding:0 2px">
            <i class="bi bi-info-circle"></i> Students at that institution will be auto-enrolled. You can also pick students manually below.
        </div>
        <input class="modal-input" id="classSearch" placeholder="Search students to add (optional)…" oninput="filterUserPick('classSearch','classUserList')">
        <div class="user-pick-list" id="classUserList" style="max-height:160px"></div>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('classOverlay')">Cancel</button>
            <button class="btn-modal-ok" onclick="createClass()"><i class="bi bi-check2"></i> Create Class</button>
        </div>
    </div>
</div>

<!-- Delete Messages Modal (delete for me vs delete for everyone) -->
<div class="overlay" id="deleteMsgOverlay">
    <div class="modal-box" style="max-width:340px">
        <div class="modal-title" style="color:#d9534f"><i class="bi bi-trash3-fill"></i> Delete <span id="deleteMsgCount">0</span> Message(s)</div>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px">Choose how you'd like to delete <span id="deleteMsgCount2">these messages</span>.</p>
        <div class="modal-btns" style="flex-wrap:wrap">
            <button class="btn-modal-cancel" onclick="closeOverlay('deleteMsgOverlay')">Cancel</button>
            <button class="btn-modal-ok" style="background:var(--bg-input);color:var(--text-main);border:1px solid var(--border)" onclick="confirmDeleteMessages('me')">Delete for Me</button>
            <button class="btn-modal-ok" id="deleteForEveryoneBtn" style="background:#d9534f" onclick="confirmDeleteMessages('everyone')"><i class="bi bi-trash3-fill"></i> Delete for Everyone</button>
        </div>
    </div>
</div>

<!-- Delete Room Confirm Modal -->
<div class="overlay" id="deleteRoomOverlay">
    <div class="modal-box" style="max-width:340px">
        <div class="modal-title" style="color:#d9534f"><i class="bi bi-door-closed-fill"></i> Delete Room</div>
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 6px">You are about to permanently delete:</p>
        <div id="deleteRoomName" style="font-size:14px;font-weight:700;color:var(--text-main);background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:16px"></div>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 20px">This will remove <strong>all messages and members</strong> from this room. This cannot be undone.</p>
        <div class="modal-btns">
            <button class="btn-modal-cancel" onclick="closeOverlay('deleteRoomOverlay')">Cancel</button>
            <button class="btn-modal-ok" style="background:#d9534f" onclick="deleteRoom()"><i class="bi bi-trash3-fill"></i> Yes, Delete Room</button>
        </div>
    </div>
</div>

<script>
const API       = 'chat_api.php';
const ME        = <?= $user_id ?>;
let activeRoom  = null;
let activeType  = null;
let lastMsgId   = 0;
let pollTimer   = null;
let allRooms    = [];
let allUsers    = [];
let canManage   = false;
let inSelectMode = false;
let _prevUnreadTotal = null; // null = not loaded yet, so first load never dings

// ── Notification sound (Web Audio API — no external file needed) ────────────
let _chatAudioCtx = null;
function chatDing() {
    try {
        if (!_chatAudioCtx) _chatAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (_chatAudioCtx.state === 'suspended') _chatAudioCtx.resume();
        const ctx = _chatAudioCtx;
        function tone(freq, start, dur, vol, type) {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.type = type;
            osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
            gain.gain.setValueAtTime(0, ctx.currentTime + start);
            gain.gain.linearRampToValueAtTime(vol, ctx.currentTime + start + 0.008);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + dur);
            osc.start(ctx.currentTime + start);
            osc.stop(ctx.currentTime + start + dur + 0.05);
        }
        // Two-note soft "pop-ping", distinct from the timer's sine fanfare
        tone(1046.5, 0.00, 0.12, 0.35, 'triangle'); // C6
        tone(1568.0, 0.09, 0.22, 0.30, 'triangle'); // G6
    } catch (e) { /* Web Audio unavailable — fail silently */ }
}

// ── Rooms ────────────────────────────────────────────────────────────────────
async function loadRooms() {
    const d = await api('rooms');
    if (!d.ok) return;
    allRooms = d.rooms;
    const total = allRooms.reduce((sum, r) => sum + (parseInt(r.unread_count) || 0), 0);
    if (_prevUnreadTotal !== null && total > _prevUnreadTotal) chatDing();
    _prevUnreadTotal = total;
    renderRooms(allRooms);
}

function renderRooms(rooms) {
    const list = document.getElementById('roomList');
    list.innerHTML = '';
    const classes = rooms.filter(r => r.type === 'class');
    const groups  = rooms.filter(r => r.type === 'group');
    const dms     = rooms.filter(r => r.type === 'direct');

    function section(label, items, avClass, icon) {
        if (!items.length) return;
        const lbl = document.createElement('div');
        lbl.className = 'room-section-label'; lbl.textContent = label;
        list.appendChild(lbl);
        items.forEach(r => {
            const el = document.createElement('div');
            el.className = 'room-item' + (activeRoom === r.id ? ' active' : '');
            el.dataset.id = r.id;
            el.innerHTML = `<div class="room-avatar ${avClass}">${r.type==='direct'?avatarHtml(r.other_avatar, icon):icon}</div>
                <div class="room-meta">
                    <div class="room-name">${esc(r.name)}</div>
                    <div class="room-last">${r.last_msg ? esc(r.last_msg.substring(0,38))+'…' : 'No messages yet'}</div>
                </div>
                ${(r.unread_count > 0 && activeRoom !== r.id) ? `<div class="room-unread-bubble">${r.unread_count > 9 ? '9+' : r.unread_count}</div>`
                    : (r.is_new && activeRoom !== r.id ? `<div class="room-new-badge">NEW</div>` : '')}`;
            el.onclick = () => openRoom(r.id, r.name, r.type, r.other_avatar);
            list.appendChild(el);
        });
    }
    section('Class Room', classes, 'class-av', '🏫');
    section('Group Chats', groups, 'group-av', '👥');
    section('Direct Messages', dms, 'dm-av', '💬');
    if (!rooms.length) list.innerHTML = '<div style="padding:16px;font-size:12px;color:var(--text-muted)">No conversations yet.</div>';
}

function filterRooms(q) {
    const f = allRooms.filter(r => r.name.toLowerCase().includes(q.toLowerCase()));
    renderRooms(f);
}

// ── Open room ────────────────────────────────────────────────────────────────
async function openRoom(roomId, roomName, roomType, roomAvatar) {
    activeRoom = roomId; activeType = roomType;
    lastMsgId  = 0;
    inSelectMode = false;
    clearInterval(pollTimer);
    document.querySelectorAll('.room-item').forEach(el => el.classList.toggle('active', parseInt(el.dataset.id) === roomId));
    document.getElementById('membersPanel').className = 'members-panel';

    // Mobile: switch panel view
    document.querySelector('.chat-wrap').classList.add('room-open');

    const main = document.getElementById('chatMain');
    main.innerHTML = `
        <div class="chat-header">
            <button class="mobile-back-btn" onclick="closeMobileChat()" title="Back to rooms"><i class="bi bi-arrow-left"></i></button>
            <div class="room-avatar ${roomType==='class'?'class-av':roomType==='direct'?'dm-av':'group-av'}" style="width:34px;height:34px;font-size:14px">${roomType==='direct'?avatarHtml(roomAvatar, roomType==='class'?'🏫':'💬'):(roomType==='class'?'🏫':'👥')}</div>
            <div class="chat-header-info">
                <div class="chat-header-title">${esc(roomName)}</div>
                <div class="chat-header-sub" id="roomSub">…</div>
            </div>
            <div class="chat-header-actions">
                ${roomType!=='direct'?`<button class="icon-btn" title="Members" onclick="toggleMembers()" id="membersBtn"><i class="bi bi-people"></i></button>`:''}
                <button class="icon-btn" title="Select messages" id="selectBtn" onclick="enterSelectMode()"><i class="bi bi-check2-square"></i></button>
                <button class="icon-btn" title="Clear messages" id="clearBtn" onclick="openClearModal()" style="display:none;color:var(--col-err,#d9534f);border-color:rgba(217,83,79,0.3)"><i class="bi bi-trash3"></i></button>
                <button class="icon-btn" title="Delete room" id="deleteRoomBtn" onclick="openDeleteRoomModal()" style="display:none;color:#d9534f;border-color:rgba(217,83,79,0.4);background:rgba(217,83,79,0.06)"><i class="bi bi-door-closed"></i></button>
            </div>
        </div>
        <div class="chat-messages" id="msgList"></div>
        <div class="chat-input-bar" id="chatInputBar">
            <textarea class="chat-ta" id="msgInput" placeholder="Type a message…" rows="1"></textarea>
            <button class="btn-send" id="sendBtn" onclick="sendMsg()"><i class="bi bi-send-fill"></i></button>
        </div>
        <div class="sel-bar" id="selBar">
            <span class="sel-count" id="selCount">0 selected</span>
            <button class="btn-sel" onclick="copySelected()" id="selCopyBtn"><i class="bi bi-clipboard"></i> Copy</button>
            <button class="btn-sel del" onclick="openDeleteMessagesModal()" id="selDeleteBtn" style="display:none"><i class="bi bi-trash3"></i> Delete</button>
            <button class="btn-sel cancel" onclick="exitSelectMode()"><i class="bi bi-x-lg"></i> Cancel</button>
        </div>`;

    const ta = document.getElementById('msgInput');
    ta.addEventListener('input', () => { ta.style.height='auto'; ta.style.height=Math.min(ta.scrollHeight,110)+'px'; });
    ta.addEventListener('keydown', e => { if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg();} });

    await fetchMessages(false);
    pollTimer = setInterval(() => fetchMessages(true), 3000);
}

// ── Messages ─────────────────────────────────────────────────────────────────
async function fetchMessages(poll) {
    if (!activeRoom) return;
    const url = `${API}?action=messages&room_id=${activeRoom}` + (poll && lastMsgId ? `&after=${lastMsgId}` : '');
    const d   = await api(null, null, url);
    if (!d.ok) return;

    const list = document.getElementById('msgList');
    if (!list) return;

    // Lecturers can manage ALL room types (including class rooms)
    canManage = true;
    const clearBtn = document.getElementById('clearBtn');
    if (clearBtn) clearBtn.style.display = 'flex';
    const deleteRoomBtn = document.getElementById('deleteRoomBtn');
    if (deleteRoomBtn) deleteRoomBtn.style.display = 'flex';

    if (!poll) {
        const memberCount = d.members.length;
        const canViewMembers = (activeType !== 'direct');
        const subText = activeType === 'direct' ? 'Direct message' :
                        `${memberCount} member${memberCount !== 1 ? 's' : ''}`;
        document.getElementById('roomSub').innerHTML = canViewMembers
            ? `<span onclick="toggleMembers()" style="cursor:pointer;text-decoration:underline dotted;text-underline-offset:2px;color:inherit" title="View members">${subText}</span>`
            : subText;
        list.innerHTML = d.messages.length ? '' :
            '<div id="emptyHint" style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px">No messages yet. Say hi! 👋</div>';
    } else if (d.messages.length) {
        const hint = document.getElementById('emptyHint');
        if (hint) hint.remove();
    }

    let lastDate = '';
    d.messages.forEach(m => {
        if (lastMsgId < m.id) lastMsgId = parseInt(m.id);
        const mine    = m.sender_id == ME;
        const isLec   = m.sender_role === 'lecturer';
        const dateStr = m.created_at.substring(0,10);
        const timeStr = m.created_at.substring(11,16);
        if (!poll && dateStr !== lastDate) {
            lastDate = dateStr;
            const dv = document.createElement('div');
            dv.className = 'date-divider';
            dv.textContent = fmtDate(dateStr);
            list.appendChild(dv);
        }
        const row = document.createElement('div');
        row.className = 'msg-row' + (mine?' mine':'');
        row.dataset.id   = m.id;
        row.dataset.mine = mine ? '1' : '0';
        row.dataset.text = m.message;
        row.innerHTML = `
            <div class="msg-check"><i class="bi bi-check"></i></div>
            <div class="msg-av${isLec?' lec':''}">${avatarHtml(m.sender_avatar, isLec?'👩‍🏫':'🎓')}</div>
            <div style="max-width:66%;min-width:0;display:flex;flex-direction:column;align-items:${mine?'flex-end':'flex-start'}">
                ${!mine?`<div class="msg-sender">${esc(m.sender)}${isLec?'<span class="lec-badge">Lecturer</span>':''}</div>`:''}
                <div class="msg-bubble ${mine?'mine':'other'}">${esc(m.message)}</div>
                <div class="msg-time">${timeStr}</div>
            </div>`;
        row.addEventListener('click', () => { if (inSelectMode) toggleMsgSelect(row); });
        list.appendChild(row);
    });
    if (!poll || d.messages.length) list.scrollTop = list.scrollHeight;

    // Refresh members panel if open (pass no room/role so canManage isn't reset)
    if (document.getElementById('membersPanel').classList.contains('open'))
        renderMembersPanel(d.members, null, null);
}

// ── Clear messages ────────────────────────────────────────────────────────────
function openClearModal() { showOverlay('clearOverlay'); }

async function clearMessages() {
    closeOverlay('clearOverlay');
    const d = await api('clear_messages', { room_id: activeRoom });
    if (!d.ok) { alert(d.error || 'Could not clear messages'); return; }
    lastMsgId = 0;
    const list = document.getElementById('msgList');
    if (list) list.innerHTML = '<div id="emptyHint" style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px">No messages yet. Say hi! 👋</div>';
}

// ── Message selection (WhatsApp-style) ───────────────────────────────────────
function enterSelectMode() {
    inSelectMode = true;
    document.getElementById('msgList').classList.add('select-mode');
    document.getElementById('chatInputBar').style.display = 'none';
    document.getElementById('selBar').classList.add('show');
    document.getElementById('selectBtn').style.color = '#7c6af7';
    updateSelBar();
}

function exitSelectMode() {
    inSelectMode = false;
    document.getElementById('msgList').classList.remove('select-mode');
    document.querySelectorAll('.msg-row.selected').forEach(r => r.classList.remove('selected'));
    document.getElementById('chatInputBar').style.display = '';
    document.getElementById('selBar').classList.remove('show');
    document.getElementById('selectBtn').style.color = '';
    updateSelBar();
}

function toggleMsgSelect(row) {
    row.classList.toggle('selected');
    updateSelBar();
}

function updateSelBar() {
    const selected = document.querySelectorAll('#msgList .msg-row.selected');
    const count    = selected.length;
    const countEl  = document.getElementById('selCount');
    if (countEl) countEl.textContent = count === 0 ? 'Tap messages to select' : `${count} selected`;
    // Lecturers can delete any selected message
    const delBtn = document.getElementById('selDeleteBtn');
    if (delBtn) delBtn.style.display = count > 0 ? 'flex' : 'none';
    const copyBtn = document.getElementById('selCopyBtn');
    if (copyBtn) copyBtn.style.display = count > 0 ? 'flex' : 'none';
}

function copySelected() {
    const rows = [...document.querySelectorAll('#msgList .msg-row.selected')];
    if (!rows.length) return;
    const text = rows.map(r => r.dataset.text || '').join('\n');
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('selCopyBtn');
        if (btn) { btn.innerHTML = '<i class="bi bi-check2"></i> Copied!'; setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 1800); }
    }).catch(() => alert('Could not copy to clipboard'));
}

function openDeleteMessagesModal() {
    const rows = [...document.querySelectorAll('#msgList .msg-row.selected')];
    if (!rows.length) return;
    const count   = rows.length;
    const allMine = rows.every(r => r.dataset.mine === '1');
    document.getElementById('deleteMsgCount').textContent  = count;
    document.getElementById('deleteMsgCount2').textContent = `${count} message${count > 1 ? 's' : ''}`;
    // "Delete for everyone" only if you sent all of them — no moderator override,
    // even the room owner/lecturer can't remove someone else's message.
    const everyoneBtn = document.getElementById('deleteForEveryoneBtn');
    if (everyoneBtn) everyoneBtn.style.display = allMine ? 'inline-flex' : 'none';
    showOverlay('deleteMsgOverlay');
}

async function confirmDeleteMessages(scope) {
    closeOverlay('deleteMsgOverlay');
    const rows = [...document.querySelectorAll('#msgList .msg-row.selected')];
    if (!rows.length) return;
    const ids = rows.map(r => parseInt(r.dataset.id)).filter(Boolean);
    const d = await api('delete_messages', { room_id: activeRoom, ids, scope });
    if (!d.ok) { alert(d.error || 'Could not delete messages'); return; }
    rows.forEach(r => r.remove());
    exitSelectMode();
    const list = document.getElementById('msgList');
    if (list && !list.querySelector('.msg-row')) {
        list.innerHTML = '<div id="emptyHint" style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px">No messages yet.</div>';
    }
}

// ── Send ─────────────────────────────────────────────────────────────────────
async function sendMsg() {
    const ta  = document.getElementById('msgInput');
    const btn = document.getElementById('sendBtn');
    const msg = ta.value.trim();
    if (!msg || !activeRoom) return;
    btn.disabled = true;
    const d = await api('send', {room_id:activeRoom, message:msg});
    btn.disabled = false;
    if (!d.ok) { alert('Failed: '+d.error); return; }
    ta.value = ''; ta.style.height = 'auto';
    await fetchMessages(true);
}

// ── Members panel ─────────────────────────────────────────────────────────────
function toggleMembers() {
    const panel = document.getElementById('membersPanel');
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) refreshMembersPanel();
}

async function refreshMembersPanel() {
    const d = await api(null, null, `${API}?action=room_members&room_id=${activeRoom}`);
    if (d.ok) {
        // Lecturers always have full manage rights over all room types
        canManage = true;
        renderMembersPanel(d.members);
    }
}

function renderMembersPanel(members) {
    const panel = document.getElementById('membersPanel');
    panel.innerHTML = `
        <div class="members-head">
            <span>Members (${members.length})</span>
            <button class="icon-btn" onclick="document.getElementById('membersPanel').classList.remove('open')" style="width:24px;height:24px;font-size:12px"><i class="bi bi-x"></i></button>
        </div>
        <div class="members-list" id="membersList"></div>
        ${canManage ? `<button class="add-member-btn" onclick="openAddMemberModal()"><i class="bi bi-person-plus"></i> Add Member</button>` : ''}`;
    const ml = document.getElementById('membersList');
    members.forEach(m => {
        const row = document.createElement('div');
        row.className = 'member-row';
        row.innerHTML = `
            <div class="member-av${m.role==='lecturer'?' lec':''}">${m.role==='lecturer'?'👩‍🏫':'🎓'}</div>
            <div style="flex:1;min-width:0">
                <div class="member-name">${esc(m.name)}${m.id==ME?' <span style="color:#7c6af7;font-size:10px">(you)</span>':''}</div>
                <div style="margin-top:2px;display:flex;align-items:center;gap:4px;flex-wrap:wrap">
                    <span style="display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;border-radius:4px;background:rgba(124,106,247,0.15);color:#7c6af7">${m.role.toUpperCase()}</span>
                    ${m.university ? `<span style="font-size:10px;color:var(--text-muted)">${esc(m.university)}</span>` : ''}
                </div>
            </div>
            ${canManage && m.id != ME ? `<button class="btn-remove" title="Remove" onclick="removeMember(${m.id}, '${esc(m.name)}')"><i class="bi bi-person-dash"></i></button>` : ''}`;
        ml.appendChild(row);
    });
}

async function removeMember(uid, name) {
    if (!confirm(`Remove ${name} from this group?`)) return;
    const d = await api('remove_member', {room_id:activeRoom, user_id:uid});
    if (!d.ok) { alert(d.error); return; }
    refreshMembersPanel();
}

// ── DM Modal ─────────────────────────────────────────────────────────────────
async function openDmModal() {
    document.getElementById('dmUserList').innerHTML = '<div style="padding:12px;font-size:12px;color:var(--text-muted)">Loading...</div>';
    document.getElementById('dmSearch').value = '';
    document.getElementById('dmInviteMsg').value = '';
    // Reset institution filter
    document.getElementById('dmCrossToggle').checked = false;
    document.getElementById('dmInstWrap').style.display = 'none';
    document.getElementById('dmInstSearch').value = '';
    document.getElementById('dmInstSuggestions').style.display = 'none';
    document.getElementById('dmInstSelected').style.display = 'none';
    document.getElementById('dmInstSelectedName').textContent = '';
    _selectedInst = null;
    showOverlay('dmOverlay');
    var rec  = await api(null, null, API + '?action=recommended_users');
    var data = await api('users');
    if (!data.ok) {
        document.getElementById('dmUserList').innerHTML = '<div style="padding:12px;font-size:12px;color:#f76a6a">Error: ' + (data.error || 'Could not load users') + '</div>';
        return;
    }
    allUsers = data.users || [];
    if (rec.ok && rec.users && rec.users.length) {
        document.getElementById('dmSearch').placeholder = 'Search your university members…';
        renderUserPickWithRec('dmUserList', allUsers, rec.users, false);
    } else {
        renderUserPick('dmUserList', allUsers, false);
    }
}

async function startDm() {
    const sel = document.querySelectorAll('#dmUserList .user-pick-row.selected');
    if (!sel.length) { alert('Select a person first'); return; }
    const uid = parseInt(sel[0].dataset.id);
    const msg = document.getElementById('dmInviteMsg').value.trim();
    const d   = await api('create_direct', {other_id: uid, message: msg});
    if (!d.ok) { alert(d.error); return; }
    closeOverlay('dmOverlay');
    if (d.invite_sent) {
        alert(d.already_pending ? 'You already have a pending invite to this person.' : 'Invite sent! They\'ll need to accept before you can chat.');
        return;
    }
    await loadRooms();
    // Find and open the room
    const room = allRooms.find(r => r.id == d.room_id);
    if (room) openRoom(room.id, room.name, room.type, room.other_avatar);
}

// ── Invites ──────────────────────────────────────────────────────────────────
async function refreshInviteBadge() {
    const d = await api(null, null, API + '?action=nav_badges');
    const badge    = document.getElementById('inviteBadge');
    const rowBadge = document.getElementById('invitesRowBadge');
    const banner   = document.getElementById('invitesBanner');
    if (!d || !d.ok) return;
    const invites = d.invites || 0;
    if (invites > 0) { badge.textContent = invites > 9 ? '9+' : invites; badge.classList.add('show'); }
    else { badge.classList.remove('show'); }
    if (rowBadge) {
        if (invites > 0) { rowBadge.textContent = invites > 9 ? '9+' : invites; rowBadge.classList.add('show'); }
        else { rowBadge.classList.remove('show'); }
    }
    if (banner) {
        if (invites > 0) {
            document.getElementById('invitesBannerText').textContent =
                invites === 1 ? 'You have 1 pending invite' : `You have ${invites} pending invites`;
            banner.classList.add('show');
        } else {
            banner.classList.remove('show');
        }
    }
}

async function openInvitesModal() {
    const list = document.getElementById('invitesList');
    list.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--text-muted)">Loading…</div>';
    showOverlay('invitesOverlay');
    const d = await api(null, null, API + '?action=list_invites');
    if (!d || !d.ok) { list.innerHTML = '<div style="padding:12px;font-size:12px;color:#f76a6a">Could not load invites.</div>'; return; }
    renderInvites(d.invites || []);
}

function renderInvites(invites) {
    const list = document.getElementById('invitesList');
    if (!invites.length) {
        list.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted)">No pending invites.</div>';
        return;
    }
    list.innerHTML = '';
    invites.forEach(inv => {
        const isLec = inv.sender_role === 'lecturer';
        const row = document.createElement('div');
        row.className = 'invite-row';
        row.innerHTML = `
            <div class="invite-row-av${isLec?' lec':''}">${isLec?'👩‍🏫':'🎓'}</div>
            <div class="invite-row-body">
                <div class="invite-row-name">${esc(inv.sender_name)}${isLec?'<span class="lec-badge">Lecturer</span>':''}</div>
                <div class="invite-row-meta">${inv.sender_university ? esc(inv.sender_university) : 'Unknown institution'} · wants to start a direct chat with you</div>
                ${inv.message ? `<div class="invite-row-msg">${esc(inv.message)}</div>` : ''}
                <div class="invite-row-btns">
                    <button class="btn-invite accept" onclick="respondInvite(${inv.id},'accept')"><i class="bi bi-check-lg"></i> Accept</button>
                    <button class="btn-invite deny" onclick="respondInvite(${inv.id},'deny')"><i class="bi bi-x-lg"></i> Deny</button>
                </div>
            </div>`;
        list.appendChild(row);
    });
}

async function respondInvite(inviteId, decision) {
    const d = await api('respond_invite', {invite_id: inviteId, decision: decision});
    if (!d || !d.ok) { alert((d && d.error) || 'Could not process invite'); return; }
    await refreshInviteBadge();
    const fresh = await api(null, null, API + '?action=list_invites');
    if (fresh && fresh.ok) renderInvites(fresh.invites || []);
    if (decision === 'accept' && d.room_id) {
        await loadRooms();
        const room = allRooms.find(r => r.id == d.room_id);
        if (room) { closeOverlay('invitesOverlay'); openRoom(room.id, room.name, room.type, room.other_avatar); }
    }
}

// ── Group Modal ───────────────────────────────────────────────────────────────
async function openGroupModal() {
    allUsers = (await api('users')).users || [];
    renderUserPick('groupUserList', allUsers, true);
    document.getElementById('groupName').value = '';
    document.getElementById('groupSearch').value = '';
    showOverlay('groupOverlay');
}

async function createGroup() {
    const name = document.getElementById('groupName').value.trim();
    if (!name) { alert('Enter a group name'); return; }
    const sel  = [...document.querySelectorAll('#groupUserList .user-pick-row.selected')].map(el => parseInt(el.dataset.id));
    const d    = await api('create_group', {name, members: sel});
    if (!d.ok) { alert(d.error); return; }
    closeOverlay('groupOverlay');
    await loadRooms();
    const room = allRooms.find(r => r.id == d.room_id);
    if (room) openRoom(room.id, room.name, room.type, room.other_avatar);
}

// ── Add Member Modal ──────────────────────────────────────────────────────────
async function openAddMemberModal() {
    const users = (await api(null, null, `${API}?action=users&room_id=${activeRoom}`)).users || [];
    allUsers = users;
    renderUserPick('addMemberList', users, false);
    document.getElementById('addMemberSearch').value = '';
    showOverlay('addMemberOverlay');
}

// ── Create Class Modal ────────────────────────────────────────────────────────
async function openClassModal() {
    document.getElementById('className').value = '';
    document.getElementById('classUniversity').value = '';
    document.getElementById('classSearch').value = '';
    document.getElementById('classUserList').innerHTML = '<div style="padding:12px;font-size:12px;color:var(--text-muted)">Loading students…</div>';
    showOverlay('classOverlay');
    const data = await api('users');
    const students = (data.users || []).filter(u => u.role === 'student');
    allUsers = students;
    renderUserPick('classUserList', students, true);
}

async function createClass() {
    const name  = document.getElementById('className').value.trim();
    const univ  = document.getElementById('classUniversity').value.trim();
    if (!name) { alert('Enter a class name'); return; }
    const sel   = [...document.querySelectorAll('#classUserList .user-pick-row.selected')].map(el => parseInt(el.dataset.id));
    const d     = await api('create_class', { name, university: univ, members: sel });
    if (!d.ok) { alert(d.error || 'Could not create class'); return; }
    closeOverlay('classOverlay');
    await loadRooms();
    const room = allRooms.find(r => r.id == d.room_id);
    if (room) openRoom(room.id, room.name, room.type, room.other_avatar);
}

// ── Delete Room ───────────────────────────────────────────────────────────────
function openDeleteRoomModal() {
    const title = document.querySelector('.chat-header-title')?.textContent || 'this room';
    document.getElementById('deleteRoomName').textContent = title;
    showOverlay('deleteRoomOverlay');
}

async function deleteRoom() {
    closeOverlay('deleteRoomOverlay');
    const d = await api('delete_room', { room_id: activeRoom });
    if (!d.ok) { alert(d.error || 'Could not delete room'); return; }
    // Return to empty state
    activeRoom = null; activeType = null;
    clearInterval(pollTimer);
    document.querySelector('.chat-wrap').classList.remove('room-open');
    document.getElementById('membersPanel').className = 'members-panel';
    document.getElementById('chatMain').innerHTML = `
        <div class="no-room">
            <i class="bi bi-chat-square-text" style="font-size:44px;opacity:0.2"></i>
            <div>Room deleted.</div>
            <div style="font-size:12px;opacity:0.6">Select another room or create a new one.</div>
        </div>`;
    await loadRooms();
}

async function addMemberConfirm() {
    const sel = document.querySelectorAll('#addMemberList .user-pick-row.selected');
    if (!sel.length) { alert('Select a person to add'); return; }
    for (const el of sel) {
        const uid = parseInt(el.dataset.id);
        const d   = await api('add_member', {room_id:activeRoom, user_id:uid});
        if (!d.ok) { alert(d.error); return; }
    }
    closeOverlay('addMemberOverlay');
    refreshMembersPanel();
}

// ── User picker ───────────────────────────────────────────────────────────────
function renderUserPick(listId, users, multi) {
    const list = document.getElementById(listId);
    list.innerHTML = '';
    if (!users.length) {
        list.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--text-muted)">No users found.</div>'; return;
    }
    users.forEach(u => {
        const row = document.createElement('div');
        row.className = 'user-pick-row';
        row.dataset.id = u.id;
        row.innerHTML = `
            <input type="${multi?'checkbox':'radio'}" name="user_pick">
            <div class="member-av${u.role==='lecturer'?' lec':''}" style="width:26px;height:26px;font-size:11px">${u.role==='lecturer'?'👩‍🏫':'🎓'}</div>
            <div><div style="font-size:13px;color:var(--text-main)">${esc(u.name)}</div><div style="font-size:11px;color:var(--text-muted)">${u.role}</div></div>`;
        row.onclick = () => {
            if (!multi) list.querySelectorAll('.user-pick-row').forEach(r => r.classList.remove('selected'));
            row.classList.toggle('selected');
            row.querySelector('input').checked = row.classList.contains('selected');
        };
        list.appendChild(row);
    });
}

function renderUserPickWithRec(listId, allUsers, recUsers, multi) {
    const list = document.getElementById(listId);
    const recIds = new Set(recUsers.map(u => u.id));
    const others = allUsers.filter(u => !recIds.has(u.id));
    let html = '';
    if (recUsers.length) {
        html += '<div style="padding:6px 10px 2px;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">⭐ SUGGESTED</div>';
        recUsers.forEach(u => {
            html += `<div class="user-pick-row" data-id="${u.id}" onclick="toggleUserPick(this,'${listId}',${multi})">
                <div class="pick-av ${u.role==='lecturer'?'lec':''}">${u.role==='lecturer'?'👩‍🏫':'🎓'}</div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:500">${esc(u.name)}</div>
                    <div style="font-size:11px;color:var(--text-muted)">${u.role.charAt(0).toUpperCase()+u.role.slice(1)}${u.university?' · '+esc(u.university):''}</div>
                </div>
            </div>`;
        });
    }
    if (others.length) {
        html += '<div style="padding:6px 10px 2px;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">ALL MEMBERS</div>';
        others.forEach(u => {
            html += `<div class="user-pick-row" data-id="${u.id}" onclick="toggleUserPick(this,'${listId}',${multi})">
                <div class="pick-av ${u.role==='lecturer'?'lec':''}">${u.role==='lecturer'?'👩‍🏫':'🎓'}</div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:500">${esc(u.name)}</div>
                    <div style="font-size:11px;color:var(--text-muted)">${u.role.charAt(0).toUpperCase()+u.role.slice(1)}${u.university?' · '+esc(u.university):''}</div>
                </div>
            </div>`;
        });
    }
    if (!html) html = '<div style="padding:12px;font-size:12px;color:var(--text-muted)">No users found.</div>';
    list.innerHTML = html;
}

// ── Institution picker (shared with student chat) ─────────────────────────────
var _allInstitutions = [
    // Test institutions (FYP demo)
    'Test University Alpha',
    'Test University Beta',
    'Test University Gamma',
    'Test University Delta',
    'Test University Epsilon',
    // Public Universities
    'Universiti Malaya (UM)',
    'Universiti Putra Malaysia (UPM)',
    'Universiti Kebangsaan Malaysia (UKM)',
    'Universiti Teknologi Malaysia (UTM)',
    'Universiti Sains Malaysia (USM)',
    'Universiti Teknologi MARA (UiTM)',
    'International Islamic University Malaysia (IIUM)',
    'Universiti Utara Malaysia (UUM)',
    'Universiti Teknikal Malaysia Melaka (UTeM)',
    'Universiti Malaysia Sarawak (UNIMAS)',
    'Universiti Malaysia Sabah (UMS)',
    'Universiti Pendidikan Sultan Idris (UPSI)',
    'Universiti Tun Hussein Onn Malaysia (UTHM)',
    'Universiti Malaysia Perlis (UniMAP)',
    'Universiti Malaysia Pahang (UMP)',
    'Universiti Sultan Zainal Abidin (UniSZA)',
    'Universiti Tun Abdul Razak (UNIRAZAK)',
    'Universiti Pertahanan Nasional Malaysia (UPNM)',
    'Universiti Kuala Lumpur (UniKL)',
    // Private Universities & Colleges
    "Taylor's University",
    'Sunway University',
    'Multimedia University (MMU)',
    'UCSI University',
    'Asia Pacific University (APU)',
    'INTI International University',
    'KDU University College',
    'HELP University',
    'MAHSA University',
    'SEGi University',
    'SEGi College Kota Damansara',
    'SEGi College Kuala Lumpur',
    'SEGi College Subang Jaya',
    'SEGi College Penang',
    'SEGi College Sarawak',
    'SEGi University Online',
    'New Era University College',
    'Linton University College',
    'Management & Science University (MSU)',
    'Limkokwing University of Creative Technology',
    'British American College (BAC)',
    'Raffles University',
    'Monash University Malaysia',
    'University of Nottingham Malaysia',
    'Curtin University Malaysia',
    'Swinburne University of Technology Sarawak',
    'Manipal International University',
    'Xiamen University Malaysia',
    'Perdana University',
    'Albukhary International University',
    'Wawasan Open University (WOU)',
    'Open University Malaysia (OUM)',
    'Universiti Widad Malaysia',
    'Al-Madinah International University (MEDIU)',
    'KL Infrastructure University College (KLIUC)',
    'Nilai University',
    'Tunku Abdul Rahman University of Management & Technology (TAR UMT)',
];
var _selectedInst = null;
var _instMatches   = [];   // current ranked suggestion list (for Enter-key selection)
var _filterTimers  = {};

// ── Typo-tolerant institution matching ────────────────────────────────────────
function _levenshtein(a, b) {
    if (a === b) return 0;
    var al = a.length, bl = b.length;
    if (!al) return bl;
    if (!bl) return al;
    var prev = []; for (var i = 0; i <= bl; i++) prev[i] = i;
    for (var x = 1; x <= al; x++) {
        var cur = [x];
        for (var y = 1; y <= bl; y++) {
            var cost = a[x-1] === b[y-1] ? 0 : 1;
            cur[y] = Math.min(prev[y] + 1, cur[y-1] + 1, prev[y-1] + cost);
        }
        prev = cur;
    }
    return prev[bl];
}
function _normalizeInst(s) {
    return s.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
}
// Scores how well a (possibly misspelled) query matches an institution name.
function _instMatchScore(query, inst) {
    var qNorm = _normalizeInst(query);
    var iNorm = _normalizeInst(inst);
    if (!qNorm) return 0;
    if (iNorm.indexOf(qNorm) !== -1) return 100;
    var qWords = qNorm.split(' ');
    var iWords = iNorm.split(' ');
    var matched = 0;
    for (var i = 0; i < qWords.length; i++) {
        var qw = qWords[i], hit = false;
        for (var j = 0; j < iWords.length; j++) {
            var iw = iWords[j];
            if (iw.indexOf(qw) !== -1 || qw.indexOf(iw) !== -1) { hit = true; break; }
            var tolerance = Math.max(1, Math.floor(iw.length * 0.34));
            if (_levenshtein(qw, iw) <= tolerance) { hit = true; break; }
        }
        if (hit) matched++;
    }
    if (matched === 0) return 0;
    return Math.round((matched / qWords.length) * 90);
}

// Pressing Enter in the institution search jumps straight to the best match.
function handleInstSearchKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (_instMatches.length) pickInstitution(_instMatches[0]);
    }
}

function toggleCrossInstitution() {
    var checked = document.getElementById('dmCrossToggle').checked;
    var wrap    = document.getElementById('dmInstWrap');
    wrap.style.display = checked ? 'block' : 'none';
    if (!checked) {
        // Collapsed — clear institution filter and reload same-uni users
        clearInstFilter(true);
    } else {
        document.getElementById('dmInstSearch').focus();
    }
}

function filterInstSuggestions() {
    var q   = document.getElementById('dmInstSearch').value.trim();
    var box = document.getElementById('dmInstSuggestions');
    _selectedInst = null;
    document.getElementById('dmInstSelected').style.display = 'none';
    document.getElementById('dmUserList').innerHTML =
        '<div style="padding:12px;font-size:12px;color:var(--text-muted)">Pick an institution above to see its members.</div>';
    if (!q) { box.style.display = 'none'; _instMatches = []; return; }
    var scored = _allInstitutions
        .map(function(i) { return { inst: i, score: _instMatchScore(q, i) }; })
        .filter(function(m) { return m.score > 0; })
        .sort(function(a, b) { return b.score - a.score; });
    _instMatches = scored.map(function(m) { return m.inst; });
    if (!scored.length) {
        box.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted)">No institutions found.</div>';
        box.style.display = 'block'; return;
    }
    box.innerHTML = '';
    scored.forEach(function(m) {
        var inst = m.inst;
        var row = document.createElement('div');
        row.style.cssText = 'padding:9px 12px;font-size:13px;color:var(--text-main);cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.1s';
        row.textContent = inst;
        row.onmouseover = function() { row.style.background = 'rgba(124,106,247,0.08)'; };
        row.onmouseout  = function() { row.style.background = ''; };
        row.onclick     = function() { pickInstitution(inst); };
        box.appendChild(row);
    });
    if (box.lastChild) box.lastChild.style.borderBottom = 'none';
    box.style.display = 'block';
}

async function pickInstitution(instName) {
    _selectedInst = instName;
    document.getElementById('dmInstSearch').value = instName;
    document.getElementById('dmInstSuggestions').style.display = 'none';
    var selDiv = document.getElementById('dmInstSelected');
    document.getElementById('dmInstSelectedName').textContent = instName;
    selDiv.style.display = 'flex';
    document.getElementById('dmUserList').innerHTML =
        '<div style="padding:12px;font-size:12px;color:var(--text-muted)">Loading members…</div>';
    var q   = document.getElementById('dmSearch').value.trim();
    var url = API + '?action=users&cross=1&univ=' + encodeURIComponent(instName) + '&q=' + encodeURIComponent(q);
    var data = await api(null, null, url);
    if (!data || !data.ok) {
        document.getElementById('dmUserList').innerHTML =
            '<div style="padding:12px;font-size:12px;color:#f76a6a">Could not load members.</div>';
        return;
    }
    allUsers = data.users || [];
    if (!allUsers.length) {
        document.getElementById('dmUserList').innerHTML =
            '<div style="padding:12px;font-size:12px;color:var(--text-muted)">No members found at this institution.</div>';
        return;
    }
    renderUserPick('dmUserList', allUsers, false);
}

function clearInstFilter(reloadAll) {
    _selectedInst = null;
    document.getElementById('dmInstSearch').value = '';
    document.getElementById('dmInstSuggestions').style.display = 'none';
    document.getElementById('dmInstSelected').style.display = 'none';
    document.getElementById('dmInstSelectedName').textContent = '';
    if (reloadAll) { filterUserPick('dmSearch', 'dmUserList'); }
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('dmInstWrap');
    if (wrap && !wrap.contains(e.target)) {
        var s = document.getElementById('dmInstSuggestions');
        if (s) s.style.display = 'none';
    }
});

function filterUserPick(searchId, listId) {
    clearTimeout(_filterTimers[searchId]);
    _filterTimers[searchId] = setTimeout(async function() {
        var q     = document.getElementById(searchId).value.trim();
        var multi = (listId === 'groupUserList' || listId === 'classUserList');
        var roomParam = (listId === 'addMemberList' && activeRoom) ? '&room_id=' + activeRoom : '';
        if (listId === 'dmUserList' && _selectedInst) {
            await pickInstitution(_selectedInst); return;
        }
        var url  = API + '?action=users&q=' + encodeURIComponent(q) + roomParam;
        var data = await api(null, null, url);
        if (!data || !data.ok) {
            document.getElementById(listId).innerHTML =
                '<div style="padding:12px;font-size:12px;color:#f76a6a">Error: ' + (data && data.error ? data.error : 'Could not load users.') + '</div>';
            return;
        }
        var users = data.users || [];
        allUsers  = users;
        renderUserPick(listId, users, multi);
    }, 250);
}

// ── Layout toggle (split vs focus) ───────────────────────────────────────────
const LAYOUT_KEY = 'pt_chat_layout';
let chatLayout = localStorage.getItem(LAYOUT_KEY) || 'focus';

function applyChatLayout() {
    const wrap = document.querySelector('.chat-wrap');
    const btn  = document.getElementById('layoutToggleBtn');
    if (chatLayout === 'split') {
        wrap.classList.add('split-mode');
        if (btn) { btn.classList.add('active'); btn.title = 'Switch to focus layout'; btn.innerHTML = '<i class="bi bi-layout-sidebar-reverse"></i>'; }
    } else {
        wrap.classList.remove('split-mode');
        if (btn) { btn.classList.remove('active'); btn.title = 'Switch to split layout'; btn.innerHTML = '<i class="bi bi-layout-split"></i>'; }
    }
}

function toggleChatLayout() {
    chatLayout = (chatLayout === 'split') ? 'focus' : 'split';
    localStorage.setItem(LAYOUT_KEY, chatLayout);
    applyChatLayout();
}

// ── Mobile panel switch ───────────────────────────────────────────────────────
function closeMobileChat() {
    document.querySelector('.chat-wrap').classList.remove('room-open');
    activeRoom = null; activeType = null;
    clearInterval(pollTimer);
    document.querySelectorAll('.room-item').forEach(el => el.classList.remove('active'));
}

// ── Overlay helpers ───────────────────────────────────────────────────────────
function showOverlay(id) { document.getElementById(id).classList.add('show'); }
function closeOverlay(id) { document.getElementById(id).classList.remove('show'); }
document.addEventListener('click', e => {
    ['dmOverlay','groupOverlay','addMemberOverlay','clearOverlay','classOverlay','deleteRoomOverlay','deleteMsgOverlay','invitesOverlay'].forEach(id => {
        if (e.target.id === id) closeOverlay(id);
    });
});

// ── Overflow menu (more options: invites, group, class room, layout) ──────────
function toggleInfoPopover(e) {
    e.stopPropagation();
    document.getElementById('chatInfoPopover').classList.toggle('show');
}
function closeInfoPopoverThen(fn) {
    document.getElementById('chatInfoPopover').classList.remove('show');
    fn();
}
document.addEventListener('click', e => {
    const pop = document.getElementById('chatInfoPopover');
    if (pop && pop.classList.contains('show') && !pop.contains(e.target) && !e.target.closest('.info-popover-wrap')) {
        pop.classList.remove('show');
    }
});

// ── API helper ────────────────────────────────────────────────────────────────
async function api(action, body, url) {
    try {
        let res;
        if (url) { res = await fetch(url); }
        else if (body) { res = await fetch(API, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,action})}); }
        else { res = await fetch(`${API}?action=${action}`); }
        return await res.json();
    } catch(e) { return {ok:false,error:e.message}; }
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>'); }
function avatarHtml(avatar, fallbackEmoji) {
    return avatar
        ? `<img src="uploads/avatars/${encodeURIComponent(avatar)}" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;display:block">`
        : fallbackEmoji;
}
function fmtDate(d) {
    const t=new Date().toISOString().substring(0,10), y=new Date(Date.now()-86400000).toISOString().substring(0,10);
    return d===t?'Today':d===y?'Yesterday':d;
}

loadRooms();
setInterval(loadRooms, 4000);
refreshInviteBadge();
setInterval(refreshInviteBadge, 4000);
applyChatLayout();
</script>
<?php require_once 'includes/lecturer_footer.php'; ?>
