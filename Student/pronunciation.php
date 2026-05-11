<?php
require_once 'student_session_check.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phonetics Practice - IAP Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../common/theme.css">
    <style>
        :root {
            --primary-color: #7c3aed;
            --primary-light: #ede9fe;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .dashboard-sidebar {
            width: 260px;
            background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
            border-right: 1px solid var(--border-color);
            padding: 24px;
            position: fixed;
            left: 0;
            top: 70px;
            height: calc(100vh - 70px);
            overflow-y: auto;
            box-shadow: var(--shadow);
            z-index: 1200;
            transition: transform 0.28s ease;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1150;
        }

        .mobile-sidebar-toggle {
            display: none;
            border: 1px solid rgba(255, 255, 255, 0.45);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-right: 10px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .sidebar-logo img {
            height: 80px;
            width: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            box-shadow: var(--shadow);
        }

        .sidebar-nav .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin-bottom: 8px;
            text-decoration: none;
            color: var(--text-secondary);
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            position: relative;
        }

        .sidebar-nav .sidebar-link:hover {
            background: var(--primary-light);
            color: var(--primary-color);
            transform: translateX(4px);
        }

        .sidebar-nav .sidebar-link.active {
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(255, 255, 255, 0.9) 100%);
            color: var(--primary-color);
            box-shadow: var(--shadow);
            transform: translateX(4px);
            border: 1px solid var(--primary-light);
        }

        .sidebar-nav .sidebar-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--primary-color);
            border-radius: 0 2px 2px 0;
        }

        .main-dashboard-content {
            margin-left: 260px;
            min-height: 100vh;
        }
        .navbar-custom { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); position: sticky; top: 0; z-index: 1100; }
        .navbar-custom .navbar-brand, .navbar-custom .nav-link { color: #fff !important; }
        .page-wrap { padding: 24px 16px 36px; }
        .page-header { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06); padding: 22px; margin-bottom: 20px; }
        .filter-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .phoneme-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(235px, 1fr)); gap: 14px; }
        .phoneme-card { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); padding: 16px; display: flex; flex-direction: column; gap: 12px; }
        .phoneme-symbol { font-size: 2rem; line-height: 1; color: #7c3aed; font-weight: 700; }
        .phoneme-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-pronounce { min-width: 90px; }
        .record-status { font-size: 0.83rem; color: #6b7280; min-height: 20px; }
        .record-status.recording { color: #dc2626; font-weight: 600; }
        @media (max-width: 991.98px) {
            .mobile-sidebar-toggle { display: inline-flex; }
            .dashboard-sidebar {
                width: 260px;
                position: fixed;
                left: 0;
                top: 70px;
                height: calc(100vh - 70px);
                border-right: 1px solid #e5e7eb;
                border-bottom: 0;
                transform: translateX(-100%);
                box-shadow: 0 12px 24px rgba(0, 0, 0, 0.2);
            }
            body.sidebar-open .dashboard-sidebar { transform: translateX(0); }
            body.sidebar-open .sidebar-overlay { display: block; }
            .main-dashboard-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .dashboard-sidebar {
                top: 64px;
                height: calc(100vh - 64px);
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="dashboard-sidebar" id="studentSidebar">
        <div class="sidebar-logo">
            <div style="display:flex; align-items:center; gap:12px;">
                <img src="../images/SA%20Main%20logo.jpg" alt="SA Main Logo" title="SA Main">
                <div style="display:flex; flex-direction:column;">
                    <span style="font-size:18px; font-weight:700; color:#7c3aed; line-height:1.2;">SPECANCIENS</span>
                    <span style="font-size:14px; font-weight:700; color:#6b7280; line-height:1.2;">IAP Portal</span>
                </div>
            </div>
        </div>
        <div class="sidebar-nav" style="margin-top:20px;">
            <a href="student_dashboard.php?view=dashboard" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
            <a href="student_dashboard.php?view=view_all_sessions" class="sidebar-link"><i class="fas fa-list"></i> View All Sessions</a>
            <a href="student_dashboard.php?view=view_registered_sessions" class="sidebar-link"><i class="fas fa-check-circle"></i> View Registered Sessions</a>
            <a href="student_dashboard.php?view=suggest_session" class="sidebar-link"><i class="fas fa-lightbulb"></i> Suggest a Session</a>
            <a href="student_dashboard.php?view=view_progress" class="sidebar-link"><i class="fas fa-chart-line"></i> View Progress</a>
            <a href="student_dashboard.php?view=edit_profile" class="sidebar-link"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <a href="pronunciation.php" class="sidebar-link active"><i class="fas fa-microphone-alt"></i> Phonetics Practice</a>
            <a href="student_dashboard.php?view=reset_password" class="sidebar-link"><i class="fas fa-key"></i> Reset Password</a>
        </div>
    </div>

    <div class="main-dashboard-content">
        <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
            <div class="container-fluid">
                <button type="button" class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar" aria-controls="studentSidebar" aria-expanded="false">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand" href="student_dashboard.php"><i class="fas fa-graduation-cap me-2"></i>IAP Student Portal</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item"><a class="nav-link" href="student_dashboard.php"><i class="fas fa-arrow-left me-1"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt me-1"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="page-wrap container-fluid">
            <section class="page-header">
                <h1 style="margin:0; color:#2d3748; font-size:1.6rem; font-weight:700;">Phonetics Practice</h1>
                <p style="margin:8px 0 0; color:#6b7280; font-size:0.95rem;">Listen, repeat, and record your pronunciation attempts.</p>
            </section>
            <section class="filter-toolbar">
                <button type="button" class="btn btn-outline-primary btn-sm filter-btn active" data-filter="all">All</button>
                <button type="button" class="btn btn-outline-primary btn-sm filter-btn" data-filter="consonant">Consonants</button>
                <button type="button" class="btn btn-outline-primary btn-sm filter-btn" data-filter="vowel">Vowels</button>
            </section>
            <section id="phonemeGrid" class="phoneme-grid"></section>
        </main>
    </div>

    <script>
        (function () {
            const sidebarToggleBtn = document.getElementById('mobileSidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const sidebar = document.getElementById('studentSidebar');
            const mobileSidebarMq = window.matchMedia('(max-width: 991.98px)');
            if (!sidebarToggleBtn || !sidebarOverlay || !sidebar) return;

            function closeSidebar() {
                document.body.classList.remove('sidebar-open');
                sidebarToggleBtn.setAttribute('aria-expanded', 'false');
            }

            sidebarToggleBtn.addEventListener('click', function() {
                const isOpen = document.body.classList.toggle('sidebar-open');
                sidebarToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            sidebarOverlay.addEventListener('click', closeSidebar);
            sidebar.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (mobileSidebarMq.matches) closeSidebar();
                });
            });
            window.addEventListener('resize', function() {
                if (!mobileSidebarMq.matches) closeSidebar();
            });
        })();

        const phonemes = [
            { symbol: "n", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Alveolar_nasal_n.ogg.mp3", example: "nice" },
            { symbol: "m", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Bilabial_nasal_m.ogg.mp3", example: "man" },
            { symbol: "ʔ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Glottal_stop_ʔ.ogg.mp3", example: "uh-oh" },
            { symbol: "ɲ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Palatal_nasal_ɲ.ogg.mp3", example: "canyon" },
            { symbol: "ŋ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Velar_nasal_ŋ.ogg.mp3", example: "sing" },
            { symbol: "l", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_alveolar_lateral_approximant_l.ogg.mp3", example: "light" },
            { symbol: "d", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_alveolar_plosive_d.ogg.mp3", example: "dog" },
            { symbol: "z", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_alveolar_sibilant_z.ogg.mp3", example: "zoo" },
            { symbol: "ð", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_dental_fricative_ð.ogg.mp3", example: "this" },
            { symbol: "h", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_glottal_fricative_h.ogg.mp3", example: "hat" },
            { symbol: "v", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_labio-dental_fricative_v.ogg.mp3", example: "van" },
            { symbol: "j", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_palatal_approximant_j.ogg.mp3", example: "yes" },
            { symbol: "ʒ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_palato-alveolar_sibilant_ʒ.ogg.mp3", example: "measure" },
            { symbol: "g", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiced_velar_plosive_g.ogg.mp3", example: "go" },
            { symbol: "t", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_alveolar_plosive_t.ogg.mp3", example: "top" },
            { symbol: "s", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_alveolar_sibilant_s.ogg.mp3", example: "sun" },
            { symbol: "θ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_dental_fricative_θ.ogg.mp3", example: "think" },
            { symbol: "f", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_labio-dental_fricative_f.ogg.mp3", example: "fish" },
            { symbol: "ʃ", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_palato-alveolar_sibilant_ʃ.ogg.mp3", example: "ship" },
            { symbol: "x", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_velar_fricative_x.ogg.mp3", example: "loch" },
            { symbol: "k", type: "consonant", file: "PhoneticFlashCards/ipa_audio/consonants/Voiceless_velar_plosive_k.ogg.mp3", example: "kite" },

            { symbol: "ɤ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close-mid_back_unrounded_vowel_ɤ.ogg.mp3", example: "Korean geo" },
            { symbol: "ɵ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close-mid_central_rounded_vowel_ɵ.ogg.mp3", example: "Swedish full" },
            { symbol: "ɘ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close-mid_central_unrounded_vowel_ɘ.ogg.mp3", example: "unstressed mid" },
            { symbol: "ø", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close-mid_front_rounded_vowel_ø.ogg.mp3", example: "French deux" },
            { symbol: "e", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close-mid_front_unrounded_vowel_e.ogg.mp3", example: "they" },
            { symbol: "u", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_back_rounded_vowel_u.ogg.mp3", example: "food" },
            { symbol: "ɯ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_back_unrounded_vowel_ɯ.ogg.mp3", example: "Korean eu" },
            { symbol: "ʉ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_central_rounded_vowel_ʉ.ogg.mp3", example: "Swedish du" },
            { symbol: "ɨ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_central_unrounded_vowel_ɨ.ogg.mp3", example: "Polish y" },
            { symbol: "y", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_front_rounded_vowel_y.ogg.mp3", example: "French tu" },
            { symbol: "i", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_front_unrounded_vowel_i.ogg.mp3", example: "see" },
            { symbol: "o", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Close_mid_back_rounded_vowel_o.ogg.mp3", example: "go" },
            { symbol: "ə", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Mid-central_vowel_ə.ogg.mp3", example: "about" },
            { symbol: "ʊ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Near-close_near-back_rounded_vowel_ʊ.ogg.mp3", example: "book" },
            { symbol: "ʏ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Near-close_near-front_rounded_vowel_ʏ.ogg.mp3", example: "German fuenf" },
            { symbol: "ɪ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Near-close_near-front_unrounded_vowel_ɪ.ogg.mp3", example: "sit" },
            { symbol: "ɐ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Near-open_central_unrounded_vowel_ɐ.ogg.mp3", example: "strut-like" },
            { symbol: "æ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Near-open_front_unrounded_vowel_æ.ogg.mp3", example: "cat" },
            { symbol: "ɔ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open-mid_back_rounded_vowel_ɔ.ogg.mp3", example: "thought" },
            { symbol: "ɞ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open-mid_central_rounded_vowel_ɞ.ogg.mp3", example: "rounded mid-open" },
            { symbol: "ɜ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open-mid_central_unrounded_vowel_ɜ.ogg.mp3", example: "nurse" },
            { symbol: "œ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open-mid_front_rounded_vowel_œ.ogg.mp3", example: "French soeur" },
            { symbol: "ɛ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open-mid_front_unrounded_vowel_ɛ.ogg.mp3", example: "bed" },
            { symbol: "ɒ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open_back_rounded_vowel_ɒ.ogg.mp3", example: "lot" },
            { symbol: "ɑ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open_back_unrounded_vowel_ɑ.ogg.mp3", example: "father" },
            { symbol: "ä", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open_central_unrounded_vowel_ä.ogg.mp3", example: "central a" },
            { symbol: "ɶ", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open_front_rounded_vowel_ɶ.ogg.mp3", example: "open rounded front" },
            { symbol: "a", type: "vowel", file: "PhoneticFlashCards/ipa_audio/vowels/Open_front_unrounded_vowel_a.ogg.mp3", example: "spa" }
        ];

        const recordings = {};
        let mediaStream = null;
        let currentRecorder = null;
        let currentChunks = [];
        let currentIndex = null;
        let activeFilter = "all";

        function playSound(file) {
            const soundPath = "../Phonetics/" + file;
            console.log("Playing:", soundPath);
            const audio = new Audio(soundPath);
            audio.play().catch(() => {
                alert("Unable to play audio. Check browser console/network for missing file path.");
            });
        }

        async function getStream() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("Microphone access is not supported in this browser.");
            }
            if (!mediaStream) {
                mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }
            return mediaStream;
        }

        function setStatus(index, message, isRecording) {
            const statusElement = document.querySelector(`[data-status="${index}"]`);
            if (!statusElement) return;
            statusElement.textContent = message;
            statusElement.classList.toggle("recording", Boolean(isRecording));
        }

        function setCardRecordingState(index, recording) {
            const recordBtn = document.querySelector(`[data-record="${index}"]`);
            const stopBtn = document.querySelector(`[data-stop="${index}"]`);
            if (!recordBtn || !stopBtn) return;
            recordBtn.disabled = recording;
            stopBtn.disabled = !recording;
        }

        async function startRecording(index) {
            if (currentRecorder && currentRecorder.state === "recording") {
                alert("A recording is already in progress. Stop it before starting another.");
                return;
            }
            try {
                const stream = await getStream();
                currentChunks = [];
                currentIndex = index;
                currentRecorder = new MediaRecorder(stream);

                currentRecorder.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) currentChunks.push(event.data);
                };

                currentRecorder.onstop = () => {
                    const audioBlob = new Blob(currentChunks, { type: "audio/webm" });
                    recordings[currentIndex] = { blob: audioBlob, createdAt: new Date().toISOString() };
                    setStatus(currentIndex, "Recording saved in memory.", false);
                    setCardRecordingState(currentIndex, false);
                    currentRecorder = null;
                    currentIndex = null;
                };

                currentRecorder.start();
                setStatus(index, "Recording...", true);
                setCardRecordingState(index, true);
            } catch (error) {
                setStatus(index, "Microphone access denied or unavailable.", false);
            }
        }

        function stopRecording(index) {
            if (!currentRecorder || currentRecorder.state !== "recording") {
                setStatus(index, "No active recording to stop.", false);
                return;
            }
            if (currentIndex !== index) {
                setStatus(index, "Another card is recording now.", false);
                return;
            }
            currentRecorder.stop();
        }

        function setActiveFilterButton() {
            document.querySelectorAll(".filter-btn").forEach((btn) => {
                btn.classList.toggle("active", btn.dataset.filter === activeFilter);
            });
        }

        function renderPhonemes() {
            const grid = document.getElementById("phonemeGrid");
            const filteredPhonemes = activeFilter === "all"
                ? phonemes
                : phonemes.filter((item) => item.type === activeFilter);

            grid.innerHTML = filteredPhonemes.map((item, index) => `
                <article class="phoneme-card">
                    <div class="phoneme-symbol">${item.symbol}</div>
                    <p style="margin:0; color:#374151; font-size:0.95rem;"><strong style="color:#111827;">Example:</strong> ${item.example}</p>
                    <div class="phoneme-actions">
                        <button type="button" class="btn btn-primary btn-sm btn-pronounce" data-play="${index}"><i class="fas fa-play me-1"></i>Play</button>
                        <button type="button" class="btn btn-success btn-sm btn-pronounce" data-record="${index}"><i class="fas fa-microphone me-1"></i>Record</button>
                        <button type="button" class="btn btn-danger btn-sm btn-pronounce" data-stop="${index}" disabled><i class="fas fa-stop me-1"></i>Stop</button>
                    </div>
                    <div class="record-status" data-status="${index}">Ready to practice.</div>
                </article>
            `).join("");

            filteredPhonemes.forEach((item, index) => {
                document.querySelector(`[data-play="${index}"]`).addEventListener("click", () => playSound(item.file));
                document.querySelector(`[data-record="${index}"]`).addEventListener("click", () => startRecording(index));
                document.querySelector(`[data-stop="${index}"]`).addEventListener("click", () => stopRecording(index));
            });

            if (!window.MediaRecorder) {
                filteredPhonemes.forEach((_, index) => {
                    document.querySelector(`[data-record="${index}"]`).disabled = true;
                    setStatus(index, "Recording not supported in this browser.", false);
                });
            }
        }

        document.querySelectorAll(".filter-btn").forEach((button) => {
            button.addEventListener("click", () => {
                activeFilter = button.dataset.filter;
                setActiveFilterButton();
                renderPhonemes();
            });
        });

        setActiveFilterButton();
        renderPhonemes();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
