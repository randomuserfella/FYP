<?php
require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/csrf.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (isset($_SESSION['user_id'])) {
    $dest = ($_SESSION['user_role'] ?? 'student') === 'lecturer' ? 'lecturer.php' : 'dashboard.php';
    header('Location: ' . tab_url($dest, $GLOBALS['tab_id']));
    exit;
}

$institutions = [
    // ── Test institutions (for FYP demo / development) ──────────────────────
    'alpha'   => ['Test University Alpha',   'Kuala Lumpur',  'Sem 2, 2024/25', 'Faculty of Computing'],
    'beta'    => ['Test University Beta',    'Selangor',      'Sem 1, 2024/25', 'Faculty of Engineering'],
    'gamma'   => ['Test University Gamma',   'Johor Bahru',   'Sem 2, 2024/25', 'Faculty of Science'],
    'delta'   => ['Test University Delta',   'Penang',        'Sem 1, 2024/25', 'Faculty of Management'],
    'epsilon' => ['Test University Epsilon', 'Kota Kinabalu', 'Sem 2, 2024/25', 'Faculty of Built Environment'],

    // ── Public Universities (Universiti Awam) ────────────────────────────────
    'um'       => ['Universiti Malaya (UM)',                          'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Computer Science & IT'],
    'upm'      => ['Universiti Putra Malaysia (UPM)',                 'Serdang',       'Sem 1, 2024/25', 'Faculty of Computer Science & IT'],
    'ukm'      => ['Universiti Kebangsaan Malaysia (UKM)',            'Bangi',         'Sem 1, 2024/25', 'Faculty of Information Science & Technology'],
    'utm'      => ['Universiti Teknologi Malaysia (UTM)',             'Johor Bahru',   'Sem 1, 2024/25', 'Faculty of Computing'],
    'usm'      => ['Universiti Sains Malaysia (USM)',                 'Penang',        'Sem 1, 2024/25', 'School of Computer Sciences'],
    'uitm'     => ['Universiti Teknologi MARA (UiTM)',                'Shah Alam',     'Sem 1, 2024/25', 'Faculty of Computer & Mathematical Sciences'],
    'iium'     => ['International Islamic University Malaysia (IIUM)', 'Gombak',       'Sem 1, 2024/25', 'Kulliyyah of ICT'],
    'uum'      => ['Universiti Utara Malaysia (UUM)',                  'Sintok',       'Sem 1, 2024/25', 'School of Computing'],
    'utem'     => ['Universiti Teknikal Malaysia Melaka (UTeM)',      'Melaka',        'Sem 1, 2024/25', 'Faculty of Information & Communication Technology'],
    'unimas'   => ['Universiti Malaysia Sarawak (UNIMAS)',            'Kota Samarahan','Sem 1, 2024/25', 'Faculty of Computer Science & IT'],
    'ums'      => ['Universiti Malaysia Sabah (UMS)',                 'Kota Kinabalu', 'Sem 1, 2024/25', 'School of Engineering & IT'],
    'upsi'     => ['Universiti Pendidikan Sultan Idris (UPSI)',       'Tanjung Malim', 'Sem 1, 2024/25', 'Faculty of Art, Computing & Creative Industry'],
    'uthm'     => ['Universiti Tun Hussein Onn Malaysia (UTHM)',      'Batu Pahat',    'Sem 1, 2024/25', 'Faculty of Computer Science & IT'],
    'unimap'   => ['Universiti Malaysia Perlis (UniMAP)',             'Kangar',        'Sem 1, 2024/25', 'School of Computer & Communication Engineering'],
    'ump'      => ['Universiti Malaysia Pahang (UMP)',                'Gambang',       'Sem 1, 2024/25', 'Faculty of Computing'],
    'unisza'   => ['Universiti Sultan Zainal Abidin (UniSZA)',        'Kuala Nerus',   'Sem 1, 2024/25', 'Faculty of Informatics & Computing'],
    'unirazak' => ['Universiti Tun Abdul Razak (UNIRAZAK)',           'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Business & Management'],
    'upnm'     => ['Universiti Pertahanan Nasional Malaysia (UPNM)',  'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Defence Science & Technology'],
    'unikl'    => ['Universiti Kuala Lumpur (UniKL)',                 'Kuala Lumpur',  'Sem 1, 2024/25', 'Malaysian Institute of Information Technology'],

    // ── Private Universities ─────────────────────────────────────────────────
    'taylor'       => ['Taylor\'s University',                        'Subang Jaya',   'Sem 1, 2024/25', 'School of Computing & IT'],
    'sunway'       => ['Sunway University',                           'Bandar Sunway', 'Sem 1, 2024/25', 'School of Computing & Technology'],
    'mmu'          => ['Multimedia University (MMU)',                 'Cyberjaya',     'Sem 1, 2024/25', 'Faculty of Computing & Informatics'],
    'ucsi'         => ['UCSI University',                             'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Computing & Applied Sciences'],
    'apu'          => ['Asia Pacific University (APU)',               'Kuala Lumpur',  'Sem 1, 2024/25', 'School of Technology'],
    'inti'         => ['INTI International University',               'Nilai',         'Sem 1, 2024/25', 'Faculty of Computing & Technology'],
    'kdu'          => ['KDU University College',                      'Utama',         'Sem 1, 2024/25', 'School of Computing & IT'],
    'help'         => ['HELP University',                             'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Computing & Digital Media'],
    'mahsa'        => ['MAHSA University',                            'Jenjarom',      'Sem 1, 2024/25', 'Faculty of Computing & Informatics'],
    'segi'         => ['SEGi University',                             'Kota Damansara','Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_kd'      => ['SEGi College Kota Damansara',                 'Kota Damansara','Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_kl'      => ['SEGi College Kuala Lumpur',                   'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_sj'      => ['SEGi College Subang Jaya',                    'Subang Jaya',   'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_pg'      => ['SEGi College Penang',                         'Penang',        'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_swk'     => ['SEGi College Sarawak',                        'Kuching',       'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'segi_online'  => ['SEGi University Online',                      'Online',        'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
    'newera'       => ['New Era University College',                  'Kajang',        'Sem 1, 2024/25', 'Faculty of Computing & IT'],
    'linton'       => ['Linton University College',                   'Mantin',        'Sem 1, 2024/25', 'School of Computing'],
    'msd'          => ['Management & Science University (MSU)',        'Shah Alam',    'Sem 1, 2024/25', 'Faculty of Information Sciences & Engineering'],
    'limkokwing'   => ['Limkokwing University of Creative Technology', 'Cyberjaya',    'Sem 1, 2024/25', 'Faculty of Information & Communication Technology'],
    'bac'          => ['British American College (BAC)',              'Petaling Jaya', 'Sem 1, 2024/25', 'School of Business & Computing'],
    'rmu'          => ['Raffles University',                          'Johor Bahru',   'Sem 1, 2024/25', 'School of Engineering & Technology'],
    'monash'       => ['Monash University Malaysia',                  'Bandar Sunway', 'Sem 1, 2024/25', 'School of IT'],
    'nottingham'   => ['University of Nottingham Malaysia',           'Semenyih',      'Sem 1, 2024/25', 'School of Computer Science'],
    'curtin'       => ['Curtin University Malaysia',                  'Miri',          'Sem 1, 2024/25', 'Faculty of Engineering & Science'],
    'swinburne'    => ['Swinburne University of Technology Sarawak',  'Kuching',       'Sem 1, 2024/25', 'Faculty of Engineering, Computing & Science'],
    'manipal'      => ['Manipal International University',            'Nilai',         'Sem 1, 2024/25', 'School of IT & Engineering'],
    'xiamen'       => ['Xiamen University Malaysia',                  'Sepang',        'Sem 1, 2024/25', 'School of Computer & Data Science'],
    'perdana'      => ['Perdana University',                          'Kuala Lumpur',  'Sem 1, 2024/25', 'Graduate School of Science'],
    'albukhary'    => ['Albukhary International University',          'Alor Setar',    'Sem 1, 2024/25', 'Faculty of Computing & Technology'],
    'wawasan'      => ['Wawasan Open University (WOU)',               'Penang',        'Sem 1, 2024/25', 'School of Science & Technology'],
    'oum'          => ['Open University Malaysia (OUM)',              'Kuala Lumpur',  'Sem 1, 2024/25', 'Faculty of Computing & Informatics'],
    'widad'        => ['Universiti Widad Malaysia',                   'Kuantan',       'Sem 1, 2024/25', 'Faculty of Computing'],
    'al_madinah'   => ['Al-Madinah International University (MEDIU)', 'Shah Alam',     'Sem 1, 2024/25', 'Faculty of ICT'],
    'kliuc'        => ['KL Infrastructure University College (KLIUC)','Kajang',        'Sem 1, 2024/25', 'School of Computing & IT'],
    'nilai'        => ['Nilai University',                            'Nilai',         'Sem 1, 2024/25', 'Faculty of Computing & Technology'],
    'tunku_abd'    => ['Tunku Abdul Rahman University of Management & Technology (TAR UMT)', 'Kuala Lumpur', 'Sem 1, 2024/25', 'Faculty of Computing & Information Technology'],
];

// ── Institutional email validation (shared by AJAX send-code + final submit) ─
function pt_is_valid_student_email(string $email): bool {
    $edu_domains = [
        // Malaysian universities
        'student.um.edu.my','um.edu.my','siswa.um.edu.my',
        'student.upm.edu.my','upm.edu.my',
        'student.ukm.edu.my','ukm.edu.my',
        'student.utm.edu.my','utm.edu.my',
        'student.usm.my','usm.my',
        'student.utem.edu.my','utem.edu.my',
        'student.unimas.my','unimas.my',
        'student.ums.edu.my','ums.edu.my',
        'student.upsi.edu.my','upsi.edu.my',
        'student.uthm.edu.my','uthm.edu.my',
        'student.unimap.edu.my','unimap.edu.my',
        'student.ump.edu.my','ump.edu.my',
        'student.uitm.edu.my','uitm.edu.my','student.uitm.ac.my',
        'student.iium.edu.my','iium.edu.my',
        'student.uum.edu.my','uum.edu.my',
        'student.unisza.edu.my','unisza.edu.my',
        'student.unirazak.edu.my','unirazak.edu.my',
        'student.taylor.edu.my','taylor.edu.my','taylors.edu.my','sd.taylors.edu.my',
        'student.sunway.edu.my','sunway.edu.my',
        'student.mmu.edu.my','mmu.edu.my',
        'student.help.edu.my','help.edu.my',
        'student.mahsa.edu.my','mahsa.edu.my',
        'student.linton.edu.my','linton.edu.my',
        'student.ucsi.edu.my','ucsi.edu.my',
        'student.apu.edu.my','apu.edu.my',
        'student.inti.edu.my','inti.edu.my',
        'student.kdu.edu.my','kdu.edu.my',
        'student.newera.edu.my','newera.edu.my',
        'student.segi.edu.my','segi.edu.my','segi4u.my',
        'student.msd.edu.my','msd.edu.my',
        // Generic patterns — any .edu.my or .ac.my institution
        // (checked via suffix below)
        '__suffix__edu.my',
        '__suffix__ac.my',
        '__suffix__edu.sg',
        '__suffix__ac.uk',
        '__suffix__edu.au',
        '__suffix__ac.nz',
        '__suffix__edu',       // US .edu
    ];

    $email_domain = strtolower(substr($email, strpos($email, '@') + 1));
    foreach ($edu_domains as $d) {
        if (str_starts_with($d, '__suffix__')) {
            $suffix = substr($d, 10);
            if ($email_domain === $suffix || str_ends_with($email_domain, '.' . $suffix)) {
                return true;
            }
        } elseif ($email_domain === $d) {
            return true;
        }
    }
    return false;
}

// ── AJAX: send / verify the email verification code ──────────────────────────
// Handles both Demo Mode (code shown on screen) and Real Mode (actual SMTP send)
if (isset($_POST['ajax_action']) && in_array($_POST['ajax_action'], ['send_code', 'verify_code'], true)) {
    header('Content-Type: application/json');
    csrf_verify();

    if ($_POST['ajax_action'] === 'send_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $real_mode = !empty($_POST['real_mode']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }
        if (!pt_is_valid_student_email($email)) {
            echo json_encode(['ok' => false, 'error' => 'Please use your institutional email (e.g. name@student.um.edu.my).']);
            exit;
        }

        require_once __DIR__ . '/config/db.php';
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'That email is already registered.']);
            exit;
        }

        $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['reg_email_code']      = $code;
        $_SESSION['reg_email_pending']   = $email;
        $_SESSION['reg_email_expires']   = time() + 300; // 5 min
        $_SESSION['reg_email_real_mode'] = $real_mode;
        $_SESSION['reg_email_verified']  = false;

        if ($real_mode) {
            require_once __DIR__ . '/email_config.php';
            require_once __DIR__ . '/includes/notifier.php';
            $sent = sendEmail(
                $pdo, 0, $email,
                'Your ProcraTrack Verification Code',
                "Your email verification code is: <b style='font-size:20px;letter-spacing:4px'>{$code}</b><br><br>This code expires in 5 minutes.",
                'reg_email_verify'
            );
            if ($sent) {
                echo json_encode(['ok' => true, 'mode' => 'real']);
            } else {
                // SMTP failed — fall back to demo display so testing isn't blocked
                echo json_encode(['ok' => true, 'mode' => 'demo', 'code' => $code, 'smtp_failed' => true]);
            }
        } else {
            echo json_encode(['ok' => true, 'mode' => 'demo', 'code' => $code]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'verify_code') {
        $entered = preg_replace('/\s+/', '', trim($_POST['code'] ?? ''));
        $stored  = $_SESSION['reg_email_code']    ?? '';
        $pending = $_SESSION['reg_email_pending'] ?? '';
        $expires = $_SESSION['reg_email_expires'] ?? 0;

        if (!$stored || !$pending) {
            echo json_encode(['ok' => false, 'error' => 'No code was sent yet. Please request a new code.']);
        } elseif (time() > $expires) {
            unset($_SESSION['reg_email_code'], $_SESSION['reg_email_pending'], $_SESSION['reg_email_expires'], $_SESSION['reg_email_real_mode']);
            echo json_encode(['ok' => false, 'error' => 'Code expired. Please request a new one.']);
        } elseif ($entered !== $stored) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect code. Please try again.']);
        } else {
            $_SESSION['reg_email_verified'] = true;
            echo json_encode(['ok' => true]);
        }
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_once __DIR__ . '/config/db.php';

    $email      = strtolower(trim($_POST['email']             ?? ''));
    $name       = trim($_POST['name']                         ?? '');
    $password   = $_POST['password']                          ?? '';
    $confirm    = $_POST['confirm_password']                  ?? '';
    $inst_key   = trim($_POST['institution_key']              ?? '');
    $inst_other = trim($_POST['institution_other']            ?? '');
    $sem_manual = trim($_POST['semester_manual']              ?? '');

    if ($inst_key === 'other') {
        $university = $inst_other;
        $semester   = $sem_manual ?: 'Unknown';
    } elseif (isset($institutions[$inst_key])) {
        $university = $institutions[$inst_key][0];
        $semester   = $institutions[$inst_key][2];
    } else {
        $university = '';
        $semester   = '';
    }

    $email_valid_domain = pt_is_valid_student_email($email);

    if (empty($email) || empty($name) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$email_valid_domain) {
        $error = 'Please use your institutional email (e.g. name@student.um.edu.my). Personal emails (Gmail, Yahoo, etc.) are not accepted.';
    } elseif (empty($university)) {
        $error = 'Please select or enter your institution.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (empty($_SESSION['reg_email_verified']) || ($_SESSION['reg_email_pending'] ?? '') !== $email) {
        $error = 'Please verify your email address first.';
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'That email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins  = $pdo->prepare(
                    "INSERT INTO users (name, email, password, role, university, semester)
                     VALUES (?, ?, ?, 'student', ?, ?)"
                );
                $ins->execute([$name, $email, $hash, $university, $semester]);
                $_SESSION['user_id']   = $pdo->lastInsertId();
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'student';
                unset($_SESSION['reg_email_code'], $_SESSION['reg_email_pending'], $_SESSION['reg_email_expires'], $_SESSION['reg_email_real_mode'], $_SESSION['reg_email_verified']);
                header('Location: ' . tab_url('dashboard.php', $GLOBALS['tab_id']));
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$js_inst = json_encode(array_map(fn($v) => [
    'name' => $v[0], 'city' => $v[1], 'semester' => $v[2], 'faculty' => $v[3]
], $institutions), JSON_HEX_TAG);
$inst_keys_js = json_encode(array_keys($institutions));
?>
<!DOCTYPE html>
<html lang="en">
<script>(function(){var t=localStorage.getItem("pt_student_theme")||"dark";document.documentElement.setAttribute("data-theme",t);})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — Student Registration</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--bg-base:#0f0f13;--bg-surface:#1c1c26;--bg-input:#16161d;--border:#2a2a38;--text-main:#e8e8f0;--text-muted:#a8a8c0;--text-dim:#5a5a78;--accent:#7c6af7;--glow:rgba(124,106,247,.15);--col-ok:#4caf82;--col-err:#d9534f;--col-warn:#c89a2e}
        [data-theme="light"]{--bg-base:#f2f2f8;--bg-surface:#ffffff;--bg-input:#f7f7fb;--border:#d0d0e0;--text-main:#111122;--text-muted:#4a4a6a;--text-dim:#8888aa;--accent:#5b49e0;--glow:rgba(91,73,224,.12);--col-ok:#1e7e4e;--col-err:#b02a2a;--col-warn:#9a6e00}

        *,*::before,*::after{box-sizing:border-box;transition:background-color .3s,border-color .3s,color .3s,box-shadow .3s !important}
        button,a,input{transition:opacity .2s,transform .15s !important}
        [data-theme="light"] .fc{background:#fff !important;color:#111122 !important;border-color:#d0d0e0 !important}
        [data-theme="light"] .fc::placeholder{color:#8888aa !important}
        [data-theme="light"] .fc:focus{background:#fff !important;box-shadow:0 0 0 3px var(--glow) !important}
        body{background:var(--bg-base);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;font-family:'Segoe UI',sans-serif;padding:40px 16px}
        .theme-float{position:fixed;top:18px;right:18px;z-index:9999}
        .tt{background:var(--bg-input);border:1px solid var(--border);color:var(--text-muted);border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
        .tt:hover{border-color:var(--accent);color:var(--accent)}
        .cw{background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;padding:36px 40px 32px;width:100%;max-width:460px}
        .brand{text-align:center;margin-bottom:6px}
        .bn{font-size:19px;font-weight:700;color:var(--accent)}
        .bn span{color:var(--text-main)}
        .bs{font-size:12px;color:var(--text-muted);margin-top:3px}
        .step-row{display:flex;align-items:center;justify-content:center;gap:6px;margin:18px 0 4px}
        .sd{width:7px;height:7px;border-radius:50%;background:var(--border);transition:background .25s !important}
        .sd.done{background:var(--accent)}
        .sl{font-size:11px;color:var(--text-muted);text-align:center;margin-bottom:20px}
        .fl{font-size:12px;color:var(--text-muted);margin-bottom:5px;display:block}
        .fg{margin-bottom:14px}
        .fc{width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:11px 16px;font-size:14px}
        .fc:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--glow);background:var(--bg-input);color:var(--text-main)}
        .fc::placeholder{color:var(--text-dim)}
        .pw{position:relative}
        .pw .fc{padding-right:44px}
        .eb{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:0}
        .eb:hover{color:var(--accent)}
        .vbox{background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:18px;text-align:center;margin-bottom:16px}
        .vi{font-size:26px;color:var(--accent);margin-bottom:8px}
        .vbox p{font-size:13px;color:var(--text-muted);line-height:1.5;margin:0}
        .vc{font-size:26px;font-weight:700;letter-spacing:8px;color:var(--text-main);margin:10px 0 4px}
        .vh{font-size:11px;color:var(--text-dim)}
        .il{display:flex;flex-direction:column;gap:7px;margin-bottom:8px;max-height:260px;overflow-y:auto;padding-right:4px}
        .il::-webkit-scrollbar{width:6px}
        .il::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
        .inst-search-wrap{position:relative;margin-bottom:10px}
        .inst-search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
        #inst_search{padding-left:36px}
        .inst-empty{display:none;text-align:center;font-size:12px;color:var(--text-muted);padding:16px 8px}
        .inst-empty.show{display:block}
        .ic{border:1px solid var(--border);border-radius:10px;padding:11px 13px;cursor:pointer;display:flex;align-items:center;gap:11px}
        .ic:hover{border-color:var(--text-muted);background:var(--bg-input)}
        .ic.sel{border-color:var(--accent);background:var(--glow)}
        .ic.oth{border-style:dashed}
        .ic.oth.sel{border-style:solid}
        .ir{width:16px;height:16px;border-radius:50%;border:1.5px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center}
        .ird{width:7px;height:7px;border-radius:50%;background:var(--accent);display:none}
        .ic.sel .ir{border-color:var(--accent)}
        .ic.sel .ird{display:block}
        .ib{flex:1;min-width:0}
        .in{font-size:13px;font-weight:600;color:var(--text-main)}
        .in.dm{color:var(--text-muted);font-weight:400}
        .iloc{font-size:11px;color:var(--text-muted);margin-top:1px}
        .oa{overflow:hidden;max-height:0;transition:max-height .3s ease,opacity .25s ease !important;opacity:0}
        .oa.open{max-height:130px;opacity:1}
        .oh{font-size:11px;color:var(--text-muted);display:flex;align-items:flex-start;gap:5px;margin-top:5px;line-height:1.4}
        .af{display:flex;align-items:center;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:12px;color:var(--text-muted);margin-bottom:12px;min-height:38px}
        .af i{color:var(--accent);font-size:15px;flex-shrink:0}
        .bm{width:100%;padding:11px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;margin-top:4px}
        .bm:hover{opacity:.87}
        .bm:disabled{opacity:.4;cursor:not-allowed}
        .bb{width:100%;padding:10px;border:1px solid var(--border);border-radius:10px;background:none;color:var(--text-muted);font-size:13px;cursor:pointer;margin-top:8px}
        .bb:hover{border-color:var(--accent);color:var(--accent)}
        .sr{display:flex;gap:5px;margin-top:8px}
        .ss{flex:1;height:4px;border-radius:2px;background:var(--border)}
        .slb{font-size:11px;margin-top:4px;min-height:14px;font-weight:600}
        .sw{background:var(--col-err)}.sf{background:var(--col-warn)}.sg{background:var(--col-ok)}.sstr{background:var(--accent)}
        .rl{background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:7px;margin-top:8px}
        .ri{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--text-muted)}
        .rd{width:16px;height:16px;border-radius:50%;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;color:var(--text-dim)}
        .ri.ok{color:var(--col-ok)}.ri.ok .rd{background:rgba(106,247,184,.15);color:var(--col-ok)}
        .ri.bad{color:var(--col-err)}.ri.bad .rd{background:rgba(247,106,106,.15);color:var(--col-err)}
        .mm{font-size:12px;min-height:16px;margin-top:5px;font-weight:600}
        .cok{color:var(--col-ok)}.cbad{color:var(--col-err)}
        .aerr{background:rgba(247,106,106,.1);border:1px solid rgba(247,106,106,.3);color:var(--col-err);border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:18px}
        .hint-ok{color:var(--col-ok)}.hint-err{color:var(--col-err)}
        .aok{background:rgba(106,247,184,.1);border:1px solid rgba(106,247,184,.3);color:var(--col-ok);border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px}
        .fl2{text-align:center;font-size:13px;color:var(--text-muted);margin-top:16px}
        .fl2 a{color:var(--accent);text-decoration:none;font-weight:600}
        .fl2 a:hover{text-decoration:underline}
        hr.dv{border:none;border-top:1px solid var(--border);margin:18px 0}
    </style>
    <script src="includes/tab_inject.js"></script>
</head>
<body>
<div class="theme-float">
    <button class="tt" onclick="toggleTheme()" title="Toggle theme">
        <i class="bi bi-moon-stars-fill theme-icon"></i>
    </button>
</div>

<div class="cw">
    <div class="brand">
        <div class="bn">🧠 Procra<span>Track</span></div>
        <div class="bs">Create your student account</div>
    </div>

    <?php if ($error): ?>
        <div class="aerr" style="margin-top:14px">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="step-row">
        <div class="sd done" id="sd0"></div>
        <div class="sd"      id="sd1"></div>
        <div class="sd"      id="sd2"></div>
        <div class="sd"      id="sd3"></div>
    </div>
    <div class="sl" id="step-lbl">Step 1 — Enter your university email</div>

    <form method="POST" action="register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>" id="regForm">
        <input type="hidden" name="tab"               value="<?= htmlspecialchars($GLOBALS['tab_id']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="institution_key"   id="institution_key"    value="">
        <input type="hidden" name="institution_other" id="inst_other_hidden"  value="">
        <input type="hidden" name="email"             id="email_hidden"       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

        <!-- STEP 1: Email -->
        <div id="s1">
            <div class="fg">
                <label class="fl" for="email_inp">University email</label>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;line-height:1.8">
                    <span style="opacity:.7">e.g.&nbsp;</span>
                    <code style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:1px 6px;font-size:11px;color:var(--accent)">name@student.um.edu.my</code>&nbsp;
                    <code style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:1px 6px;font-size:11px;color:var(--accent)">name@student.uitm.edu.my</code>&nbsp;
                    <code style="background:var(--bg-input);border:1px solid var(--border);border-radius:4px;padding:1px 6px;font-size:11px;color:var(--accent)">name@graduate.utm.edu.my</code>
                </div>
                <input type="email" id="email_inp" class="fc"
                       placeholder="yourname@student.university.edu.my"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       oninput="checkEmail()">
            </div>

            <div class="fg" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px">
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--text-main)"><i class="bi bi-envelope-paper"></i> Real Email Mode</div>
                    <div style="font-size:11px;color:var(--text-muted)">Off = Demo (code shown here). On = actually emails the code.</div>
                </div>
                <label style="position:relative;display:inline-block;width:44px;height:24px;flex:none">
                    <input type="checkbox" id="real-mode-toggle" style="opacity:0;width:0;height:0"
                           onchange="document.getElementById('rmt-track').style.background=this.checked?'var(--accent)':'#ccc'; document.getElementById('rmt-knob').style.transform=this.checked?'translateX(20px)':'translateX(0)';">
                    <span id="rmt-track" style="position:absolute;inset:0;background:#ccc;border-radius:24px;transition:.2s;pointer-events:none">
                        <span id="rmt-knob" style="position:absolute;left:2px;top:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></span>
                    </span>
                </label>
            </div>

            <button type="button" class="bm" id="btn-send" disabled onclick="sendCode()">
                <i class="bi bi-send"></i> Send verification code
            </button>
            <div class="vh" id="send-err" style="color:var(--col-err);min-height:14px;margin-top:6px"></div>
            <hr class="dv">
            <div class="fl2">Already have an account? <a href="<?= tab_url('index.php', $GLOBALS['tab_id']) ?>">Sign in</a></div>
            <div class="fl2" style="margin-top:8px">Lecturer? <a href="<?= tab_url('lecturer_register.php', $GLOBALS['tab_id']) ?>">Register here</a></div>
        </div>

        <!-- STEP 2: Verify -->
        <div id="s2" style="display:none">
            <div class="vbox">
                <div class="vi"><i class="bi bi-envelope-check"></i></div>
                <p id="verify-lead">We sent a 6-digit code to<br>
                   <strong id="email-show" style="color:var(--text-main)">you@university.edu.my</strong>
                </p>
                <div class="vc" id="code-display" style="display:none">847 291</div>
                <div class="vh" id="code-hint">(demo — code shown above)</div>
            </div>
            <div class="fg">
                <label class="fl" for="code_inp">Enter the code</label>
                <input type="text" id="code_inp" class="fc"
                       placeholder="_ _ _ _ _ _" maxlength="7"
                       style="text-align:center;letter-spacing:6px;font-weight:700;font-size:18px"
                       oninput="checkCode()">
            </div>
            <div class="vh" id="verify-err" style="color:var(--col-err);min-height:14px;margin-bottom:6px"></div>
            <button type="button" class="bm" id="btn-verify" disabled onclick="doVerify()">
                <i class="bi bi-patch-check"></i> Verify email
            </button>
            <button type="button" class="bb" onclick="goTo(1)">← Back</button>
        </div>

        <!-- STEP 3: Institution -->
        <div id="s3" style="display:none">
            <div class="aok"><i class="bi bi-patch-check-fill me-2"></i>Email verified successfully</div>

            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Select your institution</p>

            <div class="inst-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" id="inst_search" class="fc" placeholder="Search by name or state..." oninput="filterInst()" autocomplete="off">
            </div>

            <div class="il" id="inst-list">
                <?php foreach ($institutions as $key => $inst): ?>
                <div class="ic" id="ic-<?= $key ?>" data-search="<?= htmlspecialchars(strtolower($inst[0] . ' ' . $inst[1])) ?>" onclick="pickInst('<?= $key ?>')">
                    <div class="ir"><div class="ird"></div></div>
                    <div class="ib">
                        <div class="in"><?= htmlspecialchars($inst[0]) ?></div>
                        <div class="iloc"><?= htmlspecialchars($inst[1]) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="inst-empty" id="inst-empty">No matches — use "My institution isn't listed" below.</div>
            </div>

            <div class="ic oth" id="ic-other" onclick="pickInst('other')" style="margin-top:7px">
                <div class="ir"><div class="ird"></div></div>
                <div class="ib">
                    <div class="in dm">My institution isn't listed</div>
                    <div class="iloc">Enter it manually</div>
                </div>
                <i class="bi bi-pencil" style="font-size:14px;color:var(--text-muted);flex-shrink:0"></i>
            </div>

            <div class="oa" id="other-area">
                <div class="fg" style="margin-bottom:0;margin-top:10px">
                    <label class="fl" for="other_inp">Institution name</label>
                    <input type="text" id="other_inp" class="fc"
                           placeholder="e.g. Universiti Sains Malaysia"
                           oninput="otherTyped()">
                    <div class="oh">
                        <i class="bi bi-info-circle" style="font-size:13px;flex-shrink:0;margin-top:1px"></i>
                        Semester info won't be auto-filled — you'll enter it in the next step.
                    </div>
                </div>
            </div>

            <div class="af" id="af-bar" style="display:none;margin-top:10px">
                <i class="bi bi-stars"></i>
                <span id="af-txt">Auto-filled</span>
            </div>

            <button type="button" class="bm" id="btn-inst" disabled style="margin-top:10px" onclick="toStep4()">
                <i class="bi bi-arrow-right"></i> Continue
            </button>
            <button type="button" class="bb" onclick="goTo(2)">← Back</button>
        </div>

        <!-- STEP 4: Name + Password -->
        <div id="s4" style="display:none">

            <div class="af" id="af-bar4" style="display:none;margin-bottom:14px">
                <i class="bi bi-stars"></i>
                <span id="af-txt4">Auto-filled</span>
            </div>

            <div id="sem-wrap" style="display:none">
                <div class="fg">
                    <label class="fl" for="sem_inp">Current semester</label>
                    <select id="sem_inp" name="semester_manual" class="fc">
                        <option value="">Select semester…</option>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>">Semester <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="fg">
                <label class="fl" for="name_inp">Full name</label>
                <input type="text" id="name_inp" name="name" class="fc"
                       placeholder="As per university records"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       oninput="checkSubmit()">
            </div>

            <div class="fg">
                <label class="fl" for="pass_inp">Password</label>
                <div class="pw">
                    <input type="password" id="pass_inp" name="password" class="fc"
                           placeholder="At least 8 characters" oninput="checkStrength()">
                    <button type="button" class="eb" onclick="toggleEye('pass_inp','eye1')" tabindex="-1">
                        <i class="bi bi-eye" id="eye1"></i>
                    </button>
                </div>
                <div class="sr">
                    <div class="ss" id="ss1"></div><div class="ss" id="ss2"></div>
                    <div class="ss" id="ss3"></div><div class="ss" id="ss4"></div>
                </div>
                <div class="slb" id="str-lbl"></div>
                <div class="rl">
                    <div class="ri" id="req-len">  <div class="rd"><i class="bi bi-dash"></i></div> At least 8 characters</div>
                    <div class="ri" id="req-upper"><div class="rd"><i class="bi bi-dash"></i></div> One uppercase letter (A–Z)</div>
                    <div class="ri" id="req-lower"><div class="rd"><i class="bi bi-dash"></i></div> One lowercase letter (a–z)</div>
                    <div class="ri" id="req-num">  <div class="rd"><i class="bi bi-dash"></i></div> One number (0–9)</div>
                    <div class="ri" id="req-spec"> <div class="rd"><i class="bi bi-dash"></i></div> One special character</div>
                </div>
            </div>

            <div class="fg">
                <label class="fl" for="conf_inp">Confirm password</label>
                <div class="pw">
                    <input type="password" id="conf_inp" name="confirm_password" class="fc"
                           placeholder="Repeat password" oninput="checkSubmit()">
                    <button type="button" class="eb" onclick="toggleEye('conf_inp','eye2')" tabindex="-1">
                        <i class="bi bi-eye" id="eye2"></i>
                    </button>
                </div>
                <div class="mm" id="match-msg"></div>
            </div>

            <button type="submit" class="bm" id="btn-submit" disabled>
                <i class="bi bi-person-plus"></i> Create account
            </button>
            <button type="button" class="bb" onclick="goTo(3)">← Back</button>
        </div>

    </form>
</div>

<script>
var INST    = <?= $js_inst ?>;
var IKEYS   = <?= $inst_keys_js ?>;
var selInst = '';

var LBLS = ['','Step 1 — Enter your university email','Step 2 — Verify your email','Step 3 — Select your institution','Step 4 — Set up your profile'];

function goTo(n) {
    ['s1','s2','s3','s4'].forEach(function(id,i){ document.getElementById(id).style.display = i+1===n?'block':'none'; });
    for(var i=0;i<4;i++) document.getElementById('sd'+i).className='sd'+(i<n?' done':'');
    document.getElementById('step-lbl').textContent = LBLS[n];
    window.scrollTo(0,0);
}

// Institutional email domains — same list as PHP backend
var EDU_SUFFIXES = ['edu.my','ac.my','edu.sg','ac.uk','edu.au','ac.nz','edu'];
var EDU_EXACT    = [
    'student.um.edu.my','um.edu.my','siswa.um.edu.my',
    'student.upm.edu.my','upm.edu.my','student.ukm.edu.my','ukm.edu.my',
    'student.utm.edu.my','utm.edu.my','student.usm.my','usm.my',
    'student.utem.edu.my','utem.edu.my','student.unimas.my','unimas.my',
    'student.ums.edu.my','ums.edu.my','student.upsi.edu.my','upsi.edu.my',
    'student.uthm.edu.my','uthm.edu.my','student.unimap.edu.my','unimap.edu.my',
    'student.ump.edu.my','ump.edu.my','student.uitm.edu.my','uitm.edu.my',
    'student.uitm.ac.my','student.iium.edu.my','iium.edu.my',
    'student.uum.edu.my','uum.edu.my','student.unisza.edu.my','unisza.edu.my',
    'student.taylor.edu.my','taylor.edu.my','taylors.edu.my','sd.taylors.edu.my',
    'student.sunway.edu.my','sunway.edu.my',
    'student.mmu.edu.my','mmu.edu.my','student.help.edu.my','help.edu.my',
    'student.mahsa.edu.my','mahsa.edu.my','student.ucsi.edu.my','ucsi.edu.my',
    'student.apu.edu.my','apu.edu.my','student.segi.edu.my','segi.edu.my','segi4u.my',
    'student.inti.edu.my','inti.edu.my','student.kdu.edu.my','kdu.edu.my',
    'student.linton.edu.my','linton.edu.my'
];

// Bare two-part Malaysian/SG edu TLDs valid on their own (e.g. name@edu.my)
var EDU_BARE = ['edu.my','ac.my','edu.sg'];
function isEduEmail(email) {
    var at = email.indexOf('@');
    if (at < 1) return false;
    var domain = email.slice(at + 1).toLowerCase();
    if (!domain || domain.indexOf('.') < 0) return false;
    // Accept bare edu.my / ac.my / edu.sg directly
    if (EDU_BARE.indexOf(domain) >= 0) return true;
    if (EDU_EXACT.indexOf(domain) >= 0) return true;
    for (var i = 0; i < EDU_SUFFIXES.length; i++) {
        var s = EDU_SUFFIXES[i];
        if (domain.slice(-(s.length + 1)) === '.' + s) return true;
    }
    return false;
}

var _emailHint = document.createElement('div');
_emailHint.style.cssText = 'font-size:11px;min-height:15px;margin-top:5px;font-weight:600';
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('email_inp').parentNode.appendChild(_emailHint);
});

function checkEmail(){
    var v = document.getElementById('email_inp').value.trim();
    var at = v.indexOf('@');
    var btn = document.getElementById('btn-send');
    if (!v) { _emailHint.textContent = ''; btn.disabled = true; return; }
    if (at < 1 || v.length < 5) { _emailHint.textContent = ''; btn.disabled = true; return; }
    var domain = v.slice(at + 1).toLowerCase();
    var personal = ['gmail.com','yahoo.com','hotmail.com','outlook.com','live.com','icloud.com','me.com','mail.com','proton.me','protonmail.com'];
    if (personal.indexOf(domain) >= 0) {
        _emailHint.textContent = '✗ Personal emails not accepted — use your university email';
        _emailHint.className = 'hint-err';
        btn.disabled = true; return;
    }
    if (!isEduEmail(v)) {
        _emailHint.textContent = domain.length > 3 ? '✗ Must be an institutional email (e.g. @student.um.edu.my)' : '';
        _emailHint.className = 'hint-err';
        btn.disabled = true; return;
    }
    _emailHint.textContent = '✓ Looks like a valid university email';
    _emailHint.className = 'hint-ok';
    btn.disabled = false;
}

function sendCode(){
    var email=document.getElementById('email_inp').value.trim();
    var realMode=document.getElementById('real-mode-toggle').checked;
    var errBox=document.getElementById('send-err');
    var btn=document.getElementById('btn-send');
    var csrf=document.querySelector('#regForm [name=csrf_token]').value;

    errBox.textContent='';
    btn.disabled=true;
    var origHtml=btn.innerHTML;
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Sending…';

    fetch('register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_action=send_code&email='+encodeURIComponent(email)+'&real_mode='+(realMode?'1':'')+'&csrf_token='+encodeURIComponent(csrf)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.innerHTML=origHtml;
        btn.disabled=false;
        if(!data.ok){ errBox.textContent=data.error||'Could not send code.'; return; }

        document.getElementById('email_hidden').value=email;
        document.getElementById('email-show').textContent=email;

        var codeDisplay=document.getElementById('code-display');
        var codeHint=document.getElementById('code-hint');
        if(data.mode==='real'){
            codeDisplay.style.display='none';
            codeHint.textContent='Real Email Mode — check that inbox for the code.';
        } else {
            codeDisplay.style.display='block';
            codeDisplay.textContent=data.code.slice(0,3)+' '+data.code.slice(3);
            codeHint.textContent=data.smtp_failed
                ? '(real send failed — showing code as fallback)'
                : '(demo mode — code shown here, no email sent)';
        }
        document.getElementById('code_inp').value='';
        checkCode();
        goTo(2);
    })
    .catch(function(){
        btn.innerHTML=origHtml;
        btn.disabled=false;
        errBox.textContent='Network error — please try again.';
    });
}

function checkCode(){
    var raw=document.getElementById('code_inp').value.replace(/\s/g,'');
    document.getElementById('btn-verify').disabled=raw.length<6;
}

function doVerify(){
    var btn=document.getElementById('btn-verify');
    var errBox=document.getElementById('verify-err');
    var csrf=document.querySelector('#regForm [name=csrf_token]').value;
    var code=document.getElementById('code_inp').value.trim();

    errBox.textContent='';
    btn.disabled=true;
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Verifying…';

    fetch('register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_action=verify_code&code='+encodeURIComponent(code)+'&csrf_token='+encodeURIComponent(csrf)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.innerHTML='<i class="bi bi-patch-check me-2"></i>Verify email';
        if(!data.ok){
            errBox.textContent=data.error||'Incorrect code.';
            btn.disabled=false;
            return;
        }
        goTo(3);
    })
    .catch(function(){
        btn.innerHTML='<i class="bi bi-patch-check me-2"></i>Verify email';
        btn.disabled=false;
        errBox.textContent='Network error — please try again.';
    });
}

function filterInst(){
    var q = document.getElementById('inst_search').value.trim().toLowerCase();
    var cards = document.querySelectorAll('#inst-list .ic');
    var visible = 0;
    cards.forEach(function(c){
        var match = !q || c.getAttribute('data-search').indexOf(q) !== -1;
        c.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    });
    document.getElementById('inst-empty').classList.toggle('show', visible === 0);
}

function pickInst(key){
    selInst=key;
    document.getElementById('institution_key').value=key;
    IKEYS.concat(['other']).forEach(function(k){
        document.getElementById('ic-'+k).classList.toggle('sel',k===key);
    });
    var oa=document.getElementById('other-area');
    var af=document.getElementById('af-bar');
    var btn=document.getElementById('btn-inst');
    if(key==='other'){
        oa.classList.add('open'); af.style.display='none';
        var v=document.getElementById('other_inp').value.trim();
        btn.disabled=v.length===0;
        setTimeout(function(){ document.getElementById('other_inp').focus(); },280);
    } else {
        oa.classList.remove('open'); af.style.display='flex';
        var inst=INST[key];
        document.getElementById('af-txt').textContent=inst.name+' · '+inst.semester+' · '+inst.faculty+' · auto-filled';
        btn.disabled=false;
    }
}

function otherTyped(){
    var v=document.getElementById('other_inp').value.trim();
    document.getElementById('inst_other_hidden').value=v;
    document.getElementById('btn-inst').disabled=v.length===0;
}

function toStep4(){
    var isOther=selInst==='other';
    document.getElementById('sem-wrap').style.display=isOther?'block':'none';
    var af4=document.getElementById('af-bar4');
    if(!isOther){
        af4.style.display='flex';
        var inst=INST[selInst];
        document.getElementById('af-txt4').textContent=inst.semester+' · '+inst.faculty+' · auto-filled';
    } else {
        af4.style.display='none';
    }
    checkSubmit();
    goTo(4);
}

function toggleEye(fid,iid){
    var f=document.getElementById(fid),i=document.getElementById(iid);
    f.type=f.type==='password'?'text':'password';
    i.className=f.type==='password'?'bi bi-eye':'bi bi-eye-slash';
}

function setReq(id,pass){
    var el=document.getElementById(id);
    el.className='ri '+(pass?'ok':'bad');
    el.querySelector('.rd').innerHTML=pass?'<i class="bi bi-check"></i>':'<i class="bi bi-x"></i>';
}
function resetReq(id){
    var el=document.getElementById(id);
    el.className='ri';
    el.querySelector('.rd').innerHTML='<i class="bi bi-dash"></i>';
}

function checkStrength(){
    var v=document.getElementById('pass_inp').value;
    var segs=['ss1','ss2','ss3','ss4'];
    segs.forEach(function(id){ document.getElementById(id).className='ss'; });
    var lbl=document.getElementById('str-lbl');
    if(!v){ ['req-len','req-upper','req-lower','req-num','req-spec'].forEach(resetReq); lbl.textContent=''; checkSubmit(); return; }
    var c={'req-len':v.length>=8,'req-upper':/[A-Z]/.test(v),'req-lower':/[a-z]/.test(v),'req-num':/[0-9]/.test(v),'req-spec':/[^A-Za-z0-9]/.test(v)};
    Object.keys(c).forEach(function(k){ setReq(k,c[k]); });
    var score=Object.values(c).filter(Boolean).length;
    var cfg=[null,{n:1,cls:'sw',txt:'Very weak',col:'var(--col-err)'},{n:2,cls:'sf',txt:'Fair',col:'var(--col-warn)'},{n:3,cls:'sg',txt:'Good',col:'var(--col-ok)'},{n:4,cls:'sg',txt:'Strong',col:'var(--col-ok)'},{n:4,cls:'sstr',txt:'Very strong',col:'var(--accent)'}];
    var x=cfg[score];
    if(x){ for(var i=0;i<x.n;i++) document.getElementById(segs[i]).classList.add(x.cls); lbl.textContent=x.txt; lbl.style.color=x.col; }
    checkSubmit();
}

function checkSubmit(){
    var name=document.getElementById('name_inp').value.trim();
    var pass=document.getElementById('pass_inp').value;
    var conf=document.getElementById('conf_inp').value;
    var isOther=selInst==='other';
    var semOk=!isOther||(document.getElementById('sem_inp').value!=='');
    var msg=document.getElementById('match-msg');
    if(conf){ if(pass===conf&&pass.length>=8){msg.textContent='Passwords match';msg.className='mm cok';}else{msg.textContent='Passwords do not match';msg.className='mm cbad';} }else{msg.textContent='';}
    var reqs=pass.length>=8&&/[A-Z]/.test(pass)&&/[a-z]/.test(pass)&&/[0-9]/.test(pass)&&/[^A-Za-z0-9]/.test(pass);
    document.getElementById('btn-submit').disabled=!(name&&semOk&&reqs&&pass===conf);
}

function toggleTheme(){
    var next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
    localStorage.setItem('pt_student_theme',next);
    document.querySelectorAll('.theme-icon').forEach(function(i){ i.className='bi '+(next==='dark'?'bi-moon-stars-fill':'bi-sun-fill')+' theme-icon'; });
}
document.addEventListener('DOMContentLoaded',function(){
    var t=localStorage.getItem('pt_student_theme')||'dark';
    document.querySelectorAll('.theme-icon').forEach(function(i){ i.className='bi '+(t==='dark'?'bi-moon-stars-fill':'bi-sun-fill')+' theme-icon'; });
    <?php if ($error): ?>goTo(4);<?php endif; ?>
});
</script>
</body>
</html>
