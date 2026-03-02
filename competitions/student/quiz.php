<?php
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Update last activity
$student_id = $_SESSION['student_id'];
$conn->query("UPDATE students SET last_activity = NOW() WHERE id = $student_id");

// Get leaderboard data
$leaderboard_sql = "SELECT s.id, s.name, COALESCE(ss.total_points, 0) as total_points,
                    s.last_activity
                    FROM students s
                    LEFT JOIN student_scores ss ON s.id = ss.student_id
                    ORDER BY total_points DESC, s.name ASC";
$leaderboard_result = $conn->query($leaderboard_sql);
$leaderboard_data = [];
while ($row = $leaderboard_result->fetch_assoc()) {
    $leaderboard_data[] = $row;
}

// Get current student's rank
$student_rank = 1;
foreach ($leaderboard_data as $index => $student) {
    if ($student['id'] == $student_id) {
        $student_rank = $index + 1;
        break;
    }
}

// Get total points for max progress calculation
$max_points = !empty($leaderboard_data) ? max(array_column($leaderboard_data, 'total_points')) : 1000;
if ($max_points < 1000) $max_points = 1000;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Quiz Interface - Student</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            height: 100vh;
            max-width: 600px;
            margin: 0 auto;
            padding: 10px;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.1);
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 15px;
            padding: 12px 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .student-info {
            font-size: 14px;
        }

        .student-name {
            font-weight: bold;
            font-size: 16px;
        }

        .student-rank-badge {
            background: rgba(241, 196, 15, 0.3);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            border: 1px solid #f1c40f;
            margin-top: 3px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .toggle-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            backdrop-filter: blur(5px);
        }

        .toggle-btn.active {
            background: #f1c40f;
            color: #1e3c72;
            border-color: white;
            font-weight: bold;
        }

        .toggle-btn:active {
            transform: scale(0.95);
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 14px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: background 0.3s;
        }

        .logout-btn:active {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Main Content - Swipeable tabs */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            position: relative;
            overflow: hidden;
        }

        /* Tab Container */
        .tab-container {
            flex: 1;
            display: flex;
            transition: transform 0.3s ease-out;
            height: 100%;
            width: 100%;
        }

        .tab {
            flex: 0 0 100%;
            overflow-y: auto;
            padding: 10px 0;
            height: 100%;
            -webkit-overflow-scrolling: touch;
        }

        /* Quiz Tab */
        .quiz-tab {
            display: flex;
            flex-direction: column;
        }

        /* Status Card */
        .status-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            min-height: 300px;
        }

        .status-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        .status-title {
            font-size: 28px;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .status-message {
            font-size: 18px;
            opacity: 0.9;
        }

        /* Timer */
        .timer {
            font-size: 72px;
            font-weight: bold;
            margin: 20px 0;
            font-family: monospace;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
        }

        /* Question Card */
        .question-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 15px;
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .question-timer {
            text-align: center;
            font-size: 48px;
            font-weight: bold;
            color: #27ae60;
            font-family: monospace;
            margin-bottom: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            padding: 10px;
            flex-shrink: 0;
        }

        .question-text {
            font-size: 20px;
            margin-bottom: 15px;
            line-height: 1.4;
            text-align: center;
            font-weight: 500;
            flex-shrink: 0;
            max-height: 25vh;
            overflow-y: auto;
            padding: 0 5px;
        }

        .question-text::-webkit-scrollbar {
            width: 3px;
        }

        .question-text::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .question-text::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Options Grid */
        .options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
            justify-content: center;
        }

        .option {
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 50px;
            padding: 15px 18px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 16px;
            display: flex;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            touch-action: manipulation;
        }

        .option:active {
            transform: scale(0.98);
            background: rgba(255, 255, 255, 0.25);
        }

        .option.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: white;
            transform: scale(1.01);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .option.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .option.correct {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            border-color: white;
        }

        .option.incorrect {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border-color: white;
            opacity: 0.8;
        }

        .option-letter {
            display: inline-block;
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            text-align: center;
            line-height: 36px;
            margin-right: 12px;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
        }

        .option-text {
            flex: 1;
            word-break: break-word;
            font-size: 15px;
        }

        .answer-status {
            text-align: center;
            padding: 10px;
            border-radius: 50px;
            margin-top: 10px;
            font-size: 14px;
            font-weight: 500;
            flex-shrink: 0;
        }

        .answer-status.success {
            background: rgba(46, 204, 113, 0.3);
            border: 2px solid #27ae60;
        }

        .answer-status.error {
            background: rgba(231, 76, 60, 0.3);
            border: 2px solid #e74c3c;
        }

        /* Leaderboard Tab */
        .leaderboard-tab {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 15px;
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .leaderboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 5px 15px 5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .leaderboard-title {
            font-size: 24px;
            font-weight: bold;
            color: #f1c40f;
        }

        .leaderboard-stats {
            font-size: 14px;
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 15px;
            border-radius: 20px;
        }

        .leaderboard-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding-right: 5px;
            -webkit-overflow-scrolling: touch;
        }

        .student-item {
            background: rgba(255, 255, 255, 0.07);
            border-radius: 15px;
            padding: 12px 15px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .student-item.current-user {
            background: rgba(241, 196, 15, 0.2);
            border-color: #f1c40f;
            box-shadow: 0 0 20px rgba(241, 196, 15, 0.3);
        }

        .student-rank {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .student-item.first-place .student-rank {
            background: #f1c40f;
            color: #000;
            box-shadow: 0 0 20px #f1c40f;
        }

        .student-item.second-place .student-rank {
            background: #C0C0C0;
            color: #000;
        }

        .student-item.third-place .student-rank {
            background: #CD7F32;
            color: #000;
        }

        .student-info {
            flex: 1;
            min-width: 0;
        }

        .student-name {
            font-size: 16px;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .student-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
            width: 100%;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #f1c40f, #f39c12);
            border-radius: 5px;
            width: 0%;
            transition: width 0.5s;
        }

        .student-points {
            font-size: 20px;
            font-weight: bold;
            color: #f1c40f;
            font-family: monospace;
            min-width: 60px;
            text-align: right;
            flex-shrink: 0;
        }

        /* Tab Navigation */
        .tab-nav {
            display: flex;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 40px;
            padding: 4px;
            margin: 5px 0 5px 0;
            backdrop-filter: blur(10px);
            flex-shrink: 0;
        }

        .tab-btn {
            flex: 1;
            background: transparent;
            border: none;
            color: white;
            padding: 12px 0;
            font-size: 16px;
            font-weight: 500;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .tab-btn.active {
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .tab-btn:active {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Animations */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Mobile optimizations */
        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }

            .question-text {
                font-size: 18px;
            }

            .option {
                padding: 12px 15px;
            }

            .student-name {
                font-size: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="student-info">
                <div class="student-name"><?php echo $_SESSION['student_name']; ?></div>
                <div class="student-rank-badge">🏆 Rank #<?php echo $student_rank; ?></div>
            </div>
            <div class="action-buttons">
                <a href="logout.php" class="logout-btn">Exit</a>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn active" id="quizTabBtn">📝 Quiz</button>
            <button class="tab-btn" id="leaderboardTabBtn">🏆 Leaderboard</button>
        </div>

        <!-- Main Content with Tabs -->
        <div class="main-content">
            <div class="tab-container" id="tabContainer">
                <!-- Quiz Tab -->
                <div class="tab quiz-tab" id="quizTab">
                    <!-- Content will be dynamically updated here -->
                </div>

                <!-- Leaderboard Tab -->
                <div class="tab leaderboard-tab" id="leaderboardTab">
                    <div class="leaderboard-header">
                        <span class="leaderboard-title">🏆 RANKINGS</span>
                        <span class="leaderboard-stats"><?php echo count($leaderboard_data); ?> players</span>
                    </div>
                    <div class="leaderboard-list" id="leaderboardList">
                        <?php foreach ($leaderboard_data as $index => $student):
                            $rank = $index + 1;
                            $rankClass = $rank == 1 ? 'first-place' : ($rank == 2 ? 'second-place' : ($rank == 3 ? 'third-place' : ''));
                            $isCurrentUser = $student['id'] == $student_id;
                            $progressWidth = ($student['total_points'] / $max_points) * 100;
                        ?>
                            <div class="student-item <?php echo $rankClass . ' ' . ($isCurrentUser ? 'current-user' : ''); ?>" data-student-id="<?php echo $student['id']; ?>">
                                <div class="student-rank">#<?php echo $rank; ?></div>
                                <div class="student-info">
                                    <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                    <div class="student-progress">
                                        <div class="progress-fill" style="width: <?php echo $progressWidth; ?>%"></div>
                                    </div>
                                </div>
                                <div class="student-points"><?php echo $student['total_points']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentQuestionId = null;
        let selectedOption = null;
        let questionStartTime = null;
        let answerSubmitted = false;
        let correctAnswer = null;
        let currentStatus = 'waiting';
        let students = <?php echo json_encode($leaderboard_data); ?>;
        let maxPoints = <?php echo $max_points; ?>;
        let currentStudentId = <?php echo $student_id; ?>;

        // Tab switching
        const tabContainer = document.getElementById('tabContainer');
        const quizTabBtn = document.getElementById('quizTabBtn');
        const leaderboardTabBtn = document.getElementById('leaderboardTabBtn');
        const quizTab = document.getElementById('quizTab');
        const leaderboardTab = document.getElementById('leaderboardTab');

        quizTabBtn.addEventListener('click', () => {
            tabContainer.style.transform = 'translateX(0%)';
            quizTabBtn.classList.add('active');
            leaderboardTabBtn.classList.remove('active');
        });

        leaderboardTabBtn.addEventListener('click', () => {
            tabContainer.style.transform = 'translateX(-100%)';
            leaderboardTabBtn.classList.add('active');
            quizTabBtn.classList.remove('active');
        });

        // Handle touch swipe
        let touchStartX = 0;
        let touchEndX = 0;

        tabContainer.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        });

        tabContainer.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        });

        function handleSwipe() {
            const swipeThreshold = 50;
            const diff = touchStartX - touchEndX;

            if (Math.abs(diff) > swipeThreshold) {
                if (diff > 0) {
                    // Swipe left - show leaderboard
                    tabContainer.style.transform = 'translateX(-100%)';
                    leaderboardTabBtn.classList.add('active');
                    quizTabBtn.classList.remove('active');
                } else {
                    // Swipe right - show quiz
                    tabContainer.style.transform = 'translateX(0%)';
                    quizTabBtn.classList.add('active');
                    leaderboardTabBtn.classList.remove('active');
                }
            }
        }

        // Update quiz state every 100ms
        setInterval(updateQuizState, 100);

        function updateQuizState() {
            fetch('../api/get_quiz_data.php?action=state')
                .then(response => response.json())
                .then(data => {
                    const quizContent = document.getElementById('quizTab');

                    switch (data.status) {
                        case 'waiting':
                            quizContent.innerHTML = `
                                <div class="status-card">
                                    <div class="status-icon">⏳</div>
                                    <div class="status-title">Ready to Play!</div>
                                    <div class="status-message">Waiting for quiz to start...</div>
                                </div>
                            `;
                            answerSubmitted = false;
                            currentStatus = 'waiting';
                            break;

                        case 'countdown':
                            quizContent.innerHTML = `
                                <div class="status-card">
                                    <div class="status-icon">⚠️</div>
                                    <div class="status-title">Get Ready!</div>
                                    <div class="timer">${Math.ceil(data.time_left)}</div>
                                    <div class="status-message">Question starts in ${Math.ceil(data.time_left)} seconds...</div>
                                </div>
                            `;
                            answerSubmitted = false;
                            currentStatus = 'countdown';
                            break;

                        case 'question':
                            if (data.question) {
                                // New question started
                                if (currentQuestionId !== data.question.id) {
                                    currentQuestionId = data.question.id;
                                    questionStartTime = new Date();
                                    selectedOption = null;
                                    answerSubmitted = false;
                                    correctAnswer = data.question.correct_option;
                                }

                                quizContent.innerHTML = `
                                    <div class="question-card">
                                        <div class="question-timer">${Math.ceil(data.time_left)}s</div>
                                        <div class="question-text">${data.question.question_text}</div>
                                        <div class="options" id="options">
                                            <div class="option ${answerSubmitted && data.question.correct_option === 'A' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'A' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'A' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                                 data-option="A"
                                                 onclick="${!answerSubmitted ? `selectOption('A')` : ''}">
                                                <span class="option-letter">A</span>
                                                <span class="option-text">${data.question.option_a}</span>
                                            </div>
                                            <div class="option ${answerSubmitted && data.question.correct_option === 'B' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'B' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'B' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                                 data-option="B"
                                                 onclick="${!answerSubmitted ? `selectOption('B')` : ''}">
                                                <span class="option-letter">B</span>
                                                <span class="option-text">${data.question.option_b}</span>
                                            </div>
                                            <div class="option ${answerSubmitted && data.question.correct_option === 'C' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'C' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'C' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                                 data-option="C"
                                                 onclick="${!answerSubmitted ? `selectOption('C')` : ''}">
                                                <span class="option-letter">C</span>
                                                <span class="option-text">${data.question.option_c}</span>
                                            </div>
                                            <div class="option ${answerSubmitted && data.question.correct_option === 'D' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'D' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'D' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                                 data-option="D"
                                                 onclick="${!answerSubmitted ? `selectOption('D')` : ''}">
                                                <span class="option-letter">D</span>
                                                <span class="option-text">${data.question.option_d}</span>
                                            </div>
                                        </div>
                                        <div id="answerStatus" class="answer-status" style="display: none;"></div>
                                    </div>
                                `;
                                currentStatus = 'question';
                            }
                            break;

                        case 'results':
                            if (!answerSubmitted && currentStatus !== 'results') {
                                // Show result status
                                quizContent.innerHTML = `
                                    <div class="status-card">
                                        <div class="status-icon">🏆</div>
                                        <div class="status-title">Question Complete!</div>
                                        <div class="status-message">Check the leaderboard to see rankings...</div>
                                        <div style="margin-top: 20px; font-size: 18px; color: #f1c40f;">👆 Swipe or tap Leaderboard tab</div>
                                    </div>
                                `;
                            }
                            currentStatus = 'results';
                            break;
                    }
                });

            // Also update leaderboard periodically
            updateLeaderboard();
        }

        function selectOption(option) {
            if (answerSubmitted) return;

            selectedOption = option;

            // Remove selected class from all options
            document.querySelectorAll('#options .option').forEach(opt => {
                opt.classList.remove('selected');
            });

            // Add selected class to clicked option
            const selectedElement = document.querySelector(`#options .option[data-option="${option}"]`);
            if (selectedElement) {
                selectedElement.classList.add('selected');
            }

            // Automatically submit the answer when selected
            submitAnswer(option);
        }

        function submitAnswer(option) {
            if (answerSubmitted) return;

            const timeTaken = (new Date() - questionStartTime) / 1000;

            // Show submitting status
            const statusDiv = document.getElementById('answerStatus');
            statusDiv.style.display = 'block';
            statusDiv.className = 'answer-status';
            statusDiv.textContent = 'Submitting answer...';

            // Disable all options immediately
            document.querySelectorAll('#options .option').forEach(opt => {
                opt.classList.add('disabled');
                opt.onclick = null;
            });

            fetch('ajax/submit_answer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `question_id=${currentQuestionId}&answer=${option}&time_taken=${timeTaken}`
                })
                .then(response => response.json())
                .then(data => {
                    answerSubmitted = true;

                    if (data.success) {
                        statusDiv.className = 'answer-status ' + (data.correct ? 'success' : 'error');

                        if (data.correct) {
                            statusDiv.innerHTML = `✅ Correct! +${data.points_earned} points`;
                        } else {
                            statusDiv.innerHTML = `❌ Incorrect. Answer was ${data.correct_answer}`;
                        }

                        // Highlight correct/incorrect answers
                        document.querySelectorAll('#options .option').forEach(opt => {
                            const optLetter = opt.querySelector('.option-letter').textContent;
                            if (optLetter === data.correct_answer) {
                                opt.classList.add('correct');
                            } else if (optLetter === option && !data.correct) {
                                opt.classList.add('incorrect');
                            }
                        });
                    } else {
                        statusDiv.className = 'answer-status error';
                        statusDiv.textContent = data.error || 'Error submitting answer';
                    }

                    // Hide status after 3 seconds
                    setTimeout(() => {
                        statusDiv.style.display = 'none';
                    }, 3000);

                    // Refresh leaderboard after answer
                    updateLeaderboard();
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusDiv.className = 'answer-status error';
                    statusDiv.textContent = 'Network error';
                });
        }

        function updateLeaderboard() {
            fetch('../api/get_quiz_data.php?action=all_students')
                .then(response => response.json())
                .then(data => {
                    students = data;
                    renderLeaderboard();
                });
        }

        function renderLeaderboard() {
            const sortedStudents = [...students].sort((a, b) => b.total_points - a.total_points);
            const listEl = document.getElementById('leaderboardList');

            let html = '';
            sortedStudents.forEach((student, index) => {
                const rank = index + 1;
                const rankClass = rank === 1 ? 'first-place' : (rank === 2 ? 'second-place' : (rank === 3 ? 'third-place' : ''));
                const isCurrentUser = student.id == currentStudentId;
                const progressWidth = (student.total_points / maxPoints) * 100;

                html += `
                    <div class="student-item ${rankClass} ${isCurrentUser ? 'current-user' : ''}" data-student-id="${student.id}">
                        <div class="student-rank">#${rank}</div>
                        <div class="student-info">
                            <div class="student-name">${student.name}</div>
                            <div class="student-progress">
                                <div class="progress-fill" style="width: ${progressWidth}%"></div>
                            </div>
                        </div>
                        <div class="student-points">${student.total_points}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;

            // Update header stats
            document.querySelector('.leaderboard-stats').textContent = `${sortedStudents.length} players`;

            // Update student's rank badge in header
            const currentStudentRank = sortedStudents.findIndex(s => s.id == currentStudentId) + 1;
            if (currentStudentRank > 0) {
                document.querySelector('.student-rank-badge').innerHTML = `🏆 Rank #${currentStudentRank}`;
            }
        }

        // Initialize
        renderLeaderboard();
    </script>
</body>

</html>