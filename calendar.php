<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Calendar';
require_once 'config/db.php';
require_once 'includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<style>
/* ── Layout ─────────────────────────────── */
.cal-wrap{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:20px}
.view-pills{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.view-pills-left{display:flex;gap:6px;flex-wrap:wrap}
.view-pill{padding:7px 16px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);color:var(--text-muted);background:transparent;transition:all .15s}
.view-pill.active{background:#7c6af7;color:#fff;border-color:#7c6af7}
.btn-add-event{background:#7c6af7;color:#fff;border:none;border-radius:10px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px}
/* ── Legend ─────────────────────────────── */
.legend{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.legend-item{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
/* ── FullCalendar CSS variable overrides ── */
:root,
[data-theme="dark"] {
    --fc-border-color: var(--border);
    --fc-page-bg-color: var(--bg-base);
    --fc-neutral-bg-color: var(--bg-surface);
    --fc-list-event-hover-bg-color: rgba(124,106,247,0.07);
    --fc-today-bg-color: rgba(124,106,247,0.10);
    --fc-event-text-color: #fff;
    --fc-neutral-text-color: var(--text-muted);
    --fc-more-link-bg-color: var(--bg-surface);
    --fc-more-link-text-color: #7c6af7;
}
[data-theme="light"] {
    --fc-border-color: #e0e0f0;
    --fc-page-bg-color: #f4f4fb;
    --fc-neutral-bg-color: #fff;
    --fc-today-bg-color: rgba(124,106,247,0.07);
}
/* ── FullCalendar theme ─────────────────── */
.fc{color:var(--text-main)}
.fc-toolbar-title{font-size:17px!important;font-weight:700;color:var(--text-main)}
.fc-button-primary{background:rgba(124,106,247,0.15)!important;border:1px solid rgba(124,106,247,0.3)!important;color:#7c6af7!important;border-radius:8px!important;font-size:12px!important;font-weight:600!important;padding:5px 10px!important}
.fc-button-primary:hover{background:rgba(124,106,247,0.28)!important}
.fc-button-active,.fc-button-primary:not(:disabled):active{background:#7c6af7!important;color:#fff!important}

/* Day-of-week headers (Mon–Sun) */
.fc-col-header{background:var(--bg-surface)!important}
.fc-col-header-cell{background:var(--bg-surface)!important;border-color:var(--border)!important}
.fc-col-header-cell-cushion{color:var(--text-muted)!important;font-size:12px;font-weight:600;text-decoration:none!important}

/* Day number cells */
.fc-daygrid-day-number{color:var(--text-muted)!important;font-size:12px;text-decoration:none!important}
.fc-daygrid-day,.fc-daygrid-day-frame{background:var(--card-bg)!important}
.fc-daygrid-day:hover{background:rgba(124,106,247,0.05)!important}

/* Today */
.fc-day-today{background:rgba(124,106,247,0.10)!important}
.fc-day-today .fc-daygrid-day-number{color:#7c6af7!important;font-weight:700}

/* Past days */
.fc-day-past.fc-daygrid-day{background:var(--bg-base)!important;opacity:0.5;cursor:not-allowed!important}
.fc-day-past .fc-daygrid-day-number{opacity:0.5}
.fc-day-past:hover{background:var(--bg-base)!important}

/* Week/time grid */
.fc-timegrid-slot{border-color:var(--border)!important;background:var(--card-bg)!important}
.fc-timegrid-slot-minor{border-color:var(--border)!important;opacity:0.4}
.fc-timegrid-slot-label{color:var(--text-muted)!important;font-size:11px;background:var(--bg-surface)!important}
.fc-timegrid-axis{background:var(--bg-surface)!important}
.fc-timegrid-col{background:var(--card-bg)!important}

/* Grid borders */
.fc-scrollgrid,.fc-scrollgrid td,.fc-scrollgrid th{border-color:var(--border)!important}
.fc td,.fc th{border-color:var(--border)!important}

/* Selection highlight */
.fc-highlight{background:rgba(124,106,247,0.15)!important}

/* Events */
.fc-event{border-radius:5px!important;font-size:12px!important;font-weight:600!important;cursor:pointer;border-left-width:3px!important}

/* List view */
.fc-list-day-cushion,.fc-list-day th{background:var(--bg-surface)!important;color:var(--text-muted)!important}
.fc-list-table td{background:var(--card-bg)!important;border-color:var(--border)!important;color:var(--text-main)!important}
.fc-list-event:hover td{background:rgba(124,106,247,0.07)!important}
.fc-list-empty{background:var(--card-bg)!important;color:var(--text-muted)!important}

/* Misc */
.fc-daygrid-more-link{color:#7c6af7!important;font-size:11px;font-weight:600}
.fc-timegrid-now-indicator-line{border-color:#f76a6a!important}
.fc-timegrid-now-indicator-arrow{border-top-color:#f76a6a!important}
.fc-popover{background:var(--bg-surface)!important;border-color:var(--border)!important;color:var(--text-main)!important}
.fc-popover-header{background:var(--bg-surface)!important;color:var(--text-main)!important}
.fc-popover-body{background:var(--bg-surface)!important}
/* ── Mini popup (click preview) ─────────── */
.ev-popup{position:fixed;z-index:8000;background:#1a1a2e;border:1px solid #2e2e4a;border-radius:12px;padding:16px;min-width:240px;max-width:300px;box-shadow:0 12px 40px rgba(0,0,0,0.35);display:none}
.ev-popup.show{display:block}
.ev-popup-title{font-size:14px;font-weight:700;color:var(--text-main);margin-bottom:6px;display:flex;align-items:center;gap:8px}
.ev-popup-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.ev-popup-meta{font-size:12px;color:var(--text-muted);margin-bottom:4px;display:flex;align-items:center;gap:6px}
.ev-popup-notes{font-size:12px;color:var(--text-muted);background:rgba(124,106,247,0.07);border-radius:6px;padding:7px 9px;margin:8px 0;line-height:1.5}
.ev-popup-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.ev-popup-btn{padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text-muted)}
.ev-popup-btn.primary{background:#7c6af7;color:#fff;border-color:#7c6af7}
.ev-popup-btn.danger{color:#f76a6a;border-color:#f76a6a}
.ev-popup-btn.success{color:#6af7b8;border-color:#6af7b8}
.ev-popup-close{position:absolute;top:10px;right:12px;font-size:16px;cursor:pointer;color:var(--text-muted);background:none;border:none;line-height:1}
/* ── Main modal ─────────────────────────── */
.cal-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.72);z-index:9000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)}
.cal-modal-overlay.show{display:flex}
.cal-modal{background:#1a1a2e;border:1px solid #2e2e4a;border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 24px 80px rgba(0,0,0,0.7);animation:slideUp .18s ease;max-height:90vh;overflow-y:auto}[data-theme="light"] .cal-modal{background:#ffffff;border-color:#e0e0e0}
@keyframes slideUp{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.cal-modal h3{margin:0 0 20px;font-size:16px;color:var(--text-main);display:flex;align-items:center;gap:8px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;font-weight:600;letter-spacing:.3px}
.cal-input{width:100%;background:#12121e;border:1px solid #2e2e4a;color:var(--text-main);border-radius:8px;padding:9px 12px;font-size:14px;box-sizing:border-box;transition:border-color .15s}
.cal-input:focus{outline:none;border-color:#7c6af7}
.cal-input.error{border-color:#f76a6a}
.form-row{display:flex;gap:10px}
.form-row .form-group{flex:1;min-width:0}
textarea.cal-input{resize:vertical;min-height:70px;font-family:inherit}
/* Color picker */
.color-picker{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.color-swatch{width:26px;height:26px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:transform .12s}
.color-swatch:hover{transform:scale(1.15)}
.color-swatch.selected{border-color:#fff;box-shadow:0 0 0 2px #7c6af7}
/* Recurrence */
.recur-options{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.recur-pill{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);color:var(--text-muted);background:transparent}
.recur-pill.active{background:#7c6af7;color:#fff;border-color:#7c6af7}
/* Modal footer */
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--border)}
.btn-save{background:#7c6af7;color:#fff;border:none;border-radius:8px;padding:9px 22px;font-size:14px;font-weight:600;cursor:pointer}
.btn-cancel{background:transparent;color:var(--text-muted);border:1px solid var(--border);border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer}
.btn-delete{background:transparent;color:#f76a6a;border:1px solid #f76a6a;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer}
.btn-dup{background:transparent;color:#7c6af7;border:1px solid #7c6af7;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer}
/* Toast */
[data-theme="light"] .cal-modal{background:#ffffff!important;border-color:#e0e0e0!important} [data-theme="light"] .cal-input{background:#f5f5f5!important;border-color:#ddd!important;color:#111!important} [data-theme="light"] .ev-popup{background:#ffffff!important;border-color:#e0e0e0!important} [data-theme="light"] .cal-modal h3,[data-theme="light"] .cal-modal label{color:#111!important} .toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);background:#333;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;z-index:99999;transition:transform .25s;pointer-events:none}
.toast.show{transform:translateX(-50%) translateY(0)}
</style>

<div class="page-topbar">
    <div class="page-title">📅 Calendar</div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Drag to reschedule · Click slot to add · Click event to preview · <kbd>Del</kbd> to delete selected</div>

<div class="legend">
    <div class="legend-item"><div class="legend-dot" style="background:#f76a6a"></div>High</div>
    <div class="legend-item"><div class="legend-dot" style="background:#f7c46a"></div>Medium</div>
    <div class="legend-item"><div class="legend-dot" style="background:#6af7b8"></div>Low</div>
    <div class="legend-item"><div class="legend-dot" style="background:#7c6af7"></div>Custom</div>
    <div class="legend-item"><div class="legend-dot" style="background:#444"></div>Done</div>
    <div class="legend-item"><i class="bi bi-arrow-repeat" style="color:#7c6af7;font-size:11px"></i>Recurring</div>
</div>

<div class="view-pills">
    <div class="view-pills-left">
        <button class="view-pill active" onclick="setView('dayGridMonth',this)">📅 Month</button>
        <button class="view-pill" onclick="setView('timeGridWeek',this)">📆 Week</button>
        <button class="view-pill" onclick="setView('timeGridDay',this)">🗓 Day</button>
        <button class="view-pill" onclick="setView('listWeek',this)">📋 List</button>
    </div>
    <button class="btn-add-event" onclick="openAddModal(null,null,true)">+ Add Task</button>
</div>

<div class="cal-wrap"><div id="calendar"></div></div>

<!-- Mini event popup -->
<div class="ev-popup" id="evPopup">
    <button class="ev-popup-close" onclick="closePopup()">✕</button>
    <div class="ev-popup-title"><div class="ev-popup-dot" id="popDot"></div><span id="popTitle"></span></div>
    <div class="ev-popup-meta" id="popDate"><i class="bi bi-calendar3"></i><span></span></div>
    <div class="ev-popup-meta" id="popCourse" style="display:none"><i class="bi bi-book"></i><span></span></div>
    <div class="ev-popup-meta" id="popPriority"><i class="bi bi-flag"></i><span></span></div>
    <div class="ev-popup-notes" id="popNotes" style="display:none"></div>
    <div class="ev-popup-meta" id="popRecur" style="display:none"><i class="bi bi-arrow-repeat"></i><span></span></div>
    <div class="ev-popup-actions">
        <button class="ev-popup-btn primary" id="popEdit" onclick="">✏️ Edit</button>
        <button class="ev-popup-btn success" id="popToggle" onclick="">✓ Done</button>
        <button class="ev-popup-btn" id="popDup" onclick="">⧉ Copy</button>
        <button class="ev-popup-btn danger" id="popDel" onclick="">🗑 Delete</button>
    </div>
</div>

<!-- Main Modal -->
<div class="cal-modal-overlay" id="calModal">
<div class="cal-modal">
    <h3 id="modalTitle">📝 Add Task</h3>
    <input type="hidden" id="editId">

    <div class="form-group">
        <label>Task Name *</label>
        <input type="text" id="fTaskName" class="cal-input" placeholder="e.g. Submit assignment, Study for exam">
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Course / Subject <small style="font-weight:400">(optional)</small></label>
            <input type="text" id="fCourse" class="cal-input" placeholder="e.g. CS301">
        </div>
        <div class="form-group">
            <label>Priority</label>
            <select id="fPriority" class="cal-input">
                <option value="high">🔴 High</option>
                <option value="medium" selected>🟡 Medium</option>
                <option value="low">🟢 Low</option>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label>Deadline *</label>
        <input type="date" id="fDate" class="cal-input" oninput="updateDeadlineBadge(this.value)">
        <div id="deadline-badge" style="margin-top:6px;font-size:12px;display:none"></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Start Time <small style="font-weight:400">(optional)</small></label>
            <input type="time" id="fStart" class="cal-input">
        </div>
        <div class="form-group">
            <label>End Time <small style="font-weight:400">(optional)</small></label>
            <input type="time" id="fEnd" class="cal-input">
        </div>
    </div>
    <div class="form-group">
        <label>Notes / Description</label>
        <textarea id="fNotes" class="cal-input" placeholder="Add details, links, reminders..."></textarea>
    </div>
    <div class="form-group">
        <label>Colour</label>
        <div class="color-picker" id="colorPicker">
            <?php
            $swatches = ['#7c6af7','#f76a6a','#f7c46a','#6af7b8','#6ab8f7','#f76ab8','#f7a06a','#a0f76a','#f7f76a','#aaaaaa'];
            foreach ($swatches as $c): ?>
            <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>" onclick="selectColor('<?= $c ?>')"></div>
            <?php endforeach; ?>
            <div class="color-swatch" style="background:transparent;border:1px dashed #888;display:flex;align-items:center;justify-content:center;font-size:14px" onclick="document.getElementById('customColor').click()" title="Custom colour">+</div>
            <input type="color" id="customColor" style="display:none" onchange="selectColor(this.value)">
        </div>
        <input type="hidden" id="fColor">
    </div>
    <div class="form-group">
        <label>Repeat</label>
        <div class="recur-options">
            <button type="button" class="recur-pill active" data-recur="none"    onclick="selectRecur('none',this)">None</button>
            <button type="button" class="recur-pill" data-recur="daily"   onclick="selectRecur('daily',this)">Daily</button>
            <button type="button" class="recur-pill" data-recur="weekly"  onclick="selectRecur('weekly',this)">Weekly</button>
            <button type="button" class="recur-pill" data-recur="monthly" onclick="selectRecur('monthly',this)">Monthly</button>
        </div>
        <div id="recurEndWrap" style="display:none;margin-top:8px">
            <label style="font-size:12px;color:var(--text-muted);font-weight:600">Repeat Until</label>
            <input type="date" id="fRecurEnd" class="cal-input" style="margin-top:4px">
        </div>
    </div>

    <div class="modal-actions">
        <button class="btn-delete" id="btnDelete" style="display:none;margin-right:auto" onclick="deleteEventFromModal()">🗑 Delete</button>
        <button class="btn-dup"    id="btnDup"    style="display:none" onclick="duplicateEventFromModal()">⧉ Copy</button>
        <button class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button class="btn-save"   onclick="saveEvent()">💾 Save</button>
    </div>
</div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
let calendar;
let activeEventId = null;
const API = 'calendar_api.php';

// ── Init calendar ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView:    window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        headerToolbar:  { left: 'prev,next today', center: 'title', right: '' },
        height:         'auto',
        editable:       true,
        selectable:     true,
        selectMirror:   true,
        nowIndicator:   true,
        scrollTime:     '08:00:00',
        slotMinTime:    '06:00:00',
        slotMaxTime:    '23:00:00',
        slotDuration:   '00:30:00',
        snapDuration:   '00:15:00',
        dayMaxEvents:   4,
        weekNumbers:    false,
        eventMaxStack:  4,

        // Block selecting past dates
        selectAllow: (span) => {
            const today = new Date(); today.setHours(0,0,0,0);
            return span.start >= today;
        },

        // Dim past day cells
        dayCellClassNames: (arg) => {
            const today = new Date(); today.setHours(0,0,0,0);
            return arg.date < today ? ['fc-day-past'] : [];
        },

        events: (info, ok, fail) => {
            fetch(`${API}?action=fetch&start=${info.startStr}&end=${info.endStr}`)
                .then(r => r.json()).then(ok).catch(fail);
        },

        // Click empty slot / drag to select → add modal (blocked for past by selectAllow)
        select: (info) => {
            closePopup();
            openAddModal(info.startStr, info.endStr, info.allDay);
        },

        // Click event → mini popup
        eventClick: (info) => {
            info.jsEvent.stopPropagation();
            showPopup(info.event, info.jsEvent);
        },

        // Drag to reschedule — block dropping onto past dates
        eventDrop: (info) => {
            const today = new Date(); today.setHours(0,0,0,0);
            if (info.event.start < today) {
                info.revert();
                toast('❌ Cannot move a task to a past date');
                return;
            }
            updateEventTime(info.event, info.revert);
            toast('📅 Task rescheduled');
        },

        // Resize to change duration
        eventResize: (info) => {
            updateEventTime(info.event, info.revert);
            toast('⏱ Duration updated');
        },

        // Add recurring indicator to event
        eventDidMount: (info) => {
            if (info.event.extendedProps.is_recur || info.event.extendedProps.recur_type && info.event.extendedProps.recur_type !== 'none') {
                const dot = document.createElement('span');
                dot.innerHTML = ' ↻';
                dot.style.cssText = 'font-size:10px;opacity:.8';
                info.el.querySelector('.fc-event-title')?.appendChild(dot);
            }
            // Strikethrough for done
            if (info.event.extendedProps.status === 'done') {
                const title = info.el.querySelector('.fc-event-title');
                if (title) title.style.textDecoration = 'line-through';
            }
        },
    });

    calendar.render();

    // Keyboard shortcuts
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeModal(); closePopup(); }
        if ((e.key === 'Delete' || e.key === 'Backspace') && activeEventId && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            if (confirm('Delete this task?')) deleteEvent(activeEventId);
        }
    });

    // Click outside popup to close
    document.addEventListener('click', e => {
        const popup = document.getElementById('evPopup');
        if (popup.classList.contains('show') && !popup.contains(e.target)) closePopup();
    });
});

// ── View switcher ───────────────────────────────────────────────────────────
function setView(view, btn) {
    if (!calendar) return;
    calendar.changeView(view);
    document.querySelectorAll('.view-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── Mini popup ──────────────────────────────────────────────────────────────
function showPopup(event, jsEvent) {
    activeEventId = event.id;
    const p   = event.extendedProps;
    const col = event.backgroundColor;

    document.getElementById('popDot').style.background = col;
    document.getElementById('popTitle').textContent    = event.title;

    // Date + time
    const dateEl = document.getElementById('popDate').querySelector('span');
    let dateStr = event.start ? event.start.toLocaleDateString('en-MY', {weekday:'short',day:'numeric',month:'short',year:'numeric'}) : '';
    if (!event.allDay && event.start) {
        dateStr += ' · ' + event.start.toLocaleTimeString('en-MY', {hour:'2-digit',minute:'2-digit'});
        if (event.end) dateStr += ' – ' + event.end.toLocaleTimeString('en-MY', {hour:'2-digit',minute:'2-digit'});
    }
    dateEl.textContent = dateStr;

    // Course
    const courseEl = document.getElementById('popCourse');
    if (p.course) { courseEl.querySelector('span').textContent = p.course; courseEl.style.display = 'flex'; }
    else courseEl.style.display = 'none';

    // Priority
    const priMap = { high:'🔴 High', medium:'🟡 Medium', low:'🟢 Low' };
    document.getElementById('popPriority').querySelector('span').textContent = priMap[p.priority] || p.priority;

    // Notes
    const notesEl = document.getElementById('popNotes');
    if (p.notes) { notesEl.textContent = p.notes; notesEl.style.display = 'block'; }
    else notesEl.style.display = 'none';

    // Recurrence
    const recurEl = document.getElementById('popRecur');
    if (p.recur_type && p.recur_type !== 'none') {
        recurEl.querySelector('span').textContent = 'Repeats ' + p.recur_type + (p.recur_end ? ' until ' + p.recur_end : '');
        recurEl.style.display = 'flex';
    } else recurEl.style.display = 'none';

    // Buttons
    const isDone = p.status === 'done';
    document.getElementById('popToggle').textContent = isDone ? '↩ Unmark' : '✓ Done';
    document.getElementById('popEdit').onclick   = () => { closePopup(); openEditModal(event); };
    document.getElementById('popToggle').onclick = () => { toggleDone(event.id); closePopup(); };
    document.getElementById('popDup').onclick    = () => { duplicateEvent(event.id, event.startStr?.substring(0,10)); closePopup(); };
    document.getElementById('popDel').onclick    = () => { if(confirm('Delete this task?')) { deleteEvent(event.id); closePopup(); } };

    // Position popup near click
    const popup = document.getElementById('evPopup');
    popup.classList.add('show');
    const rect  = popup.getBoundingClientRect();
    let x = jsEvent.clientX + 10;
    let y = jsEvent.clientY + 10;
    if (x + 300 > window.innerWidth)  x = jsEvent.clientX - 310;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 10;
    popup.style.left = x + 'px';
    popup.style.top  = y + 'px';
}

function closePopup() {
    document.getElementById('evPopup').classList.remove('show');
}

// ── Open Add Modal ──────────────────────────────────────────────────────────
function openAddModal(startStr, endStr, allDay) {
    document.getElementById('modalTitle').textContent = '📝 Add Task';
    document.getElementById('editId').value    = '';
    document.getElementById('fTaskName').value = '';
    document.getElementById('fCourse').value   = '';
    document.getElementById('fPriority').value = 'medium';
    document.getElementById('fNotes').value    = '';
    document.getElementById('fColor').value    = '';
    document.getElementById('fRecurEnd').value = '';
    selectRecur('none');
    clearColorSelection();

    const today = new Date().toISOString().substring(0, 10);
    const dateInput = document.getElementById('fDate');
    dateInput.min   = today;  // block past dates
    dateInput.value = startStr ? startStr.substring(0, 10) : today;
    updateDeadlineBadge(dateInput.value);

    if (startStr && !allDay && startStr.length > 10) {
        document.getElementById('fStart').value = startStr.substring(11, 16);
        document.getElementById('fEnd').value   = (endStr && endStr.length > 10) ? endStr.substring(11, 16) : '';
    } else {
        document.getElementById('fStart').value = '';
        document.getElementById('fEnd').value   = '';
    }

    document.getElementById('btnDelete').style.display = 'none';
    document.getElementById('btnDup').style.display    = 'none';
    document.getElementById('calModal').classList.add('show');
    setTimeout(() => document.getElementById('fTaskName').focus(), 120);
}

// ── Open Edit Modal ─────────────────────────────────────────────────────────
function openEditModal(event) {
    const p = event.extendedProps;
    document.getElementById('modalTitle').textContent = '✏️ Edit Task';
    document.getElementById('editId').value    = event.id;
    document.getElementById('fTaskName').value = event.title;
    document.getElementById('fCourse').value   = p.course   || '';
    document.getElementById('fPriority').value = p.priority || 'medium';
    document.getElementById('fNotes').value    = p.notes    || '';
    const dateInput = document.getElementById('fDate');
    dateInput.min   = '';  // allow editing existing tasks on any date
    dateInput.value = event.startStr ? event.startStr.substring(0, 10) : '';
    updateDeadlineBadge(dateInput.value);
    document.getElementById('fStart').value    = (!event.allDay && event.startStr?.length > 10) ? event.startStr.substring(11, 16) : '';
    document.getElementById('fEnd').value      = (event.endStr?.length > 10) ? event.endStr.substring(11, 16) : '';
    document.getElementById('fColor').value    = p.custom_color || '';
    selectRecur(p.recur_type || 'none');
    document.getElementById('fRecurEnd').value = p.recur_end || '';

    // Highlight selected colour
    clearColorSelection();
    if (p.custom_color) {
        document.querySelectorAll('.color-swatch').forEach(s => {
            if (s.dataset.color === p.custom_color) s.classList.add('selected');
        });
    }

    document.getElementById('btnDelete').style.display = 'inline-block';
    document.getElementById('btnDup').style.display    = 'inline-block';
    document.getElementById('calModal').classList.add('show');
}

// ── Deadline countdown badge ────────────────────────────────────────────────
function updateDeadlineBadge(val) {
    const badge = document.getElementById('deadline-badge');
    if (!badge) return;
    if (!val) { badge.style.display = 'none'; return; }

    const today   = new Date(); today.setHours(0,0,0,0);
    const due     = new Date(val + 'T00:00:00');
    const diffMs  = due - today;
    const days    = Math.round(diffMs / 86400000);

    let html = '', bg = '', color = '';

    if (days < 0) {
        const n = Math.abs(days);
        bg = 'rgba(247,106,106,0.12)'; color = '#f76a6a';
        html = `<i class="bi bi-exclamation-triangle-fill"></i> ${n} day${n===1?'':'s'} overdue`;
    } else if (days === 0) {
        bg = 'rgba(247,106,106,0.12)'; color = '#f76a6a';
        html = `<i class="bi bi-alarm-fill"></i> Due TODAY`;
    } else if (days === 1) {
        bg = 'rgba(247,196,106,0.15)'; color = '#f7c46a';
        html = `<i class="bi bi-clock-fill"></i> Due tomorrow`;
    } else if (days <= 3) {
        bg = 'rgba(247,196,106,0.10)'; color = '#f7c46a';
        html = `<i class="bi bi-calendar-event"></i> ${days} days until deadline`;
    } else if (days <= 7) {
        bg = 'rgba(124,106,247,0.10)'; color = '#a89cf7';
        html = `<i class="bi bi-calendar-week"></i> ${days} days until deadline`;
    } else {
        bg = 'rgba(106,247,184,0.08)'; color = '#6af7b8';
        html = `<i class="bi bi-calendar3"></i> ${days} days until deadline`;
    }

    badge.style.display      = 'inline-flex';
    badge.style.alignItems   = 'center';
    badge.style.gap          = '5px';
    badge.style.padding      = '3px 10px';
    badge.style.borderRadius = '20px';
    badge.style.background   = bg;
    badge.style.color        = color;
    badge.style.fontWeight   = days <= 1 ? '600' : '400';
    badge.innerHTML          = html;
}

function closeModal() {
    document.getElementById('calModal').classList.remove('show');
    document.getElementById('fTaskName').classList.remove('error');
}

document.getElementById('calModal').addEventListener('click', e => {
    if (e.target.id === 'calModal') closeModal();
});

// ── Color picker ────────────────────────────────────────────────────────────
function selectColor(color) {
    document.getElementById('fColor').value = color;
    clearColorSelection();
    document.querySelectorAll('.color-swatch').forEach(s => {
        if (s.dataset.color === color) s.classList.add('selected');
    });
}
function clearColorSelection() {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
}

// ── Recurrence ──────────────────────────────────────────────────────────────
function selectRecur(val, btn) {
    document.querySelectorAll('.recur-pill').forEach(p => p.classList.remove('active'));
    const target = btn || document.querySelector(`.recur-pill[data-recur="${val}"]`);
    if (target) target.classList.add('active');
    document.getElementById('recurEndWrap').style.display = (val && val !== 'none') ? 'block' : 'none';
}

// ── Save ────────────────────────────────────────────────────────────────────
async function saveEvent() {
    const taskName = document.getElementById('fTaskName').value.trim();
    if (!taskName) {
        document.getElementById('fTaskName').classList.add('error');
        document.getElementById('fTaskName').focus();
        return;
    }
    document.getElementById('fTaskName').classList.remove('error');

    const course = document.getElementById('fCourse').value.trim();

    const id         = document.getElementById('editId').value;
    const dueDate    = document.getElementById('fDate').value  || new Date().toISOString().substring(0,10);
    const today      = new Date().toISOString().substring(0, 10);

    // Block past dates for new tasks only
    if (!id && dueDate < today) {
        document.getElementById('fDate').classList.add('error');
        document.getElementById('fDate').focus();
        toast('❌ Cannot set a task in the past');
        return;
    }
    document.getElementById('fDate').classList.remove('error');
    const startTime  = document.getElementById('fStart').value || null;
    const endTime    = document.getElementById('fEnd').value   || null;
    const recurType  = document.querySelector('.recur-pill.active')?.dataset.recur || 'none';

    const payload = {
        action:       id ? 'update' : 'add',
        id:           id ? parseInt(id) : undefined,
        task_name:    taskName,
        course:       course,
        priority:     document.getElementById('fPriority').value,
        due_date:     dueDate,
        start_time:   startTime,
        end_time:     endTime,
        start:        dueDate + (startTime ? 'T' + startTime : ''),
        end:          dueDate + (endTime   ? 'T' + endTime   : ''),
        allDay:       !startTime,
        notes:        document.getElementById('fNotes').value.trim(),
        custom_color: document.getElementById('fColor').value || '',
        recur_type:   recurType,
        recur_end:    recurType !== 'none' ? (document.getElementById('fRecurEnd').value || null) : null,
    };

    const btn = document.querySelector('.btn-save');
    btn.textContent = 'Saving…'; btn.disabled = true;

    try {
        const res  = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (!data.ok) {
            toast('❌ Save failed: ' + (data.error || 'Unknown error'));
            btn.textContent = '💾 Save'; btn.disabled = false;
            return;
        }
    } catch (e) {
        toast('❌ Network error: ' + e.message);
        btn.textContent = '💾 Save'; btn.disabled = false;
        return;
    }

    btn.textContent = '💾 Save'; btn.disabled = false;
    closeModal();
    calendar.refetchEvents();
    toast(id ? '✅ Task updated' : '✅ Task added');
}

// ── Drag/resize update ──────────────────────────────────────────────────────
async function updateEventTime(event, revertFn) {
    const res  = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', id: parseInt(event.id), start: event.startStr, end: event.endStr, allDay: event.allDay }) });
    const data = await res.json();
    if (!data.ok) revertFn();
}

// ── Delete ──────────────────────────────────────────────────────────────────
async function deleteEvent(id) {
    await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: parseInt(id) }) });
    calendar.refetchEvents();
    toast('🗑 Task deleted');
    activeEventId = null;
}
async function deleteEventFromModal() {
    const id = document.getElementById('editId').value;
    if (!id || !confirm('Delete this task?')) return;
    closeModal();
    await deleteEvent(parseInt(id));
}

// ── Duplicate ───────────────────────────────────────────────────────────────
async function duplicateEvent(id, dueDate) {
    await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'duplicate', id: parseInt(id), due_date: dueDate }) });
    calendar.refetchEvents();
    toast('⧉ Task copied');
}
async function duplicateEventFromModal() {
    const id = document.getElementById('editId').value;
    const date = document.getElementById('fDate').value;
    if (!id) return;
    closeModal();
    await duplicateEvent(parseInt(id), date);
}

// ── Toggle done ─────────────────────────────────────────────────────────────
async function toggleDone(id) {
    await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'toggle', id: parseInt(id) }) });
    calendar.refetchEvents();
    toast('✓ Status updated');
}

// ── Toast ───────────────────────────────────────────────────────────────────
function toast(msg) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2500);
}
</script>
<?php require_once 'includes/footer.php'; ?>
