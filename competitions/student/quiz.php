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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            /* Prevent scrolling */
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
            padding: 15px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 12px 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            flex-shrink: 0;
        }

        .student-info {
            font-size: 16px;
        }

        .student-name {
            font-weight: bold;
            font-size: 18px;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 14px;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Main Content - Flex column to fill height */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 0;
            /* Important for flex children */
        }

        /* Status Card - Full height centering */
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

        /* Question Card - Takes full height */
        .question-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 20px;
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 500px;
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
            font-size: 22px;
            margin-bottom: 20px;
            line-height: 1.4;
            text-align: center;
            font-weight: 500;
            flex-shrink: 0;
            max-height: 30vh;
            overflow-y: auto;
            padding: 0 5px;
        }

        /* Scrollable question text if very long */
        .question-text::-webkit-scrollbar {
            width: 5px;
        }

        .question-text::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .question-text::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        /* Keyboard shortcuts hint */
        .keyboard-hint {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: none;
        }

        @media (min-width: 1024px) {
            .keyboard-hint {
                display: block;
            }
        }

        .keyboard-hint span {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 5px;
            margin: 0 5px;
            font-weight: bold;
            font-family: monospace;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        /* Options Grid - Takes remaining space */
        .options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
            justify-content: center;
            min-height: 0;
        }

        .option {
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 50px;
            padding: 18px 20px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 18px;
            display: flex;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-tap-highlight-color: transparent;
            position: relative;
            /* For hover effect */
            overflow: hidden;
            /* For ripple effect */
        }

        /* Improved hover effect for laptop */
        @media (min-width: 1024px) {
            .option:hover {
                background: rgba(255, 255, 255, 0.25);
                transform: translateX(10px) scale(1.02);
                border-color: rgba(255, 255, 255, 0.5);
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            }

            .option:active {
                transform: translateX(5px) scale(0.99);
            }
        }

        /* Ripple effect */
        .option .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        .option.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: white;
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        /* Keyboard focus indicator */
        .option.keyboard-focus {
            outline: 3px solid rgba(255, 255, 255, 0.8);
            outline-offset: 2px;
            transform: scale(1.02);
        }

        .option.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }

        .option.correct {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            border-color: white;
        }

        .option.incorrect {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border-color: white;
            opacity: 0.7;
        }

        .option-letter {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            margin-right: 15px;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .option:hover .option-letter {
            background: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }

        .option-text {
            flex: 1;
            word-break: break-word;
        }

        /* Answer Status - Small notification */
        .answer-status {
            text-align: center;
            padding: 10px;
            border-radius: 50px;
            margin-top: 15px;
            font-size: 16px;
            font-weight: 500;
            flex-shrink: 0;
            animation: slideUp 0.3s ease;
        }

        .answer-status.success {
            background: rgba(46, 204, 113, 0.3);
            border: 2px solid #27ae60;
            color: #fff;
        }

        .answer-status.error {
            background: rgba(231, 76, 60, 0.3);
            border: 2px solid #e74c3c;
            color: #fff;
        }

        /* Results Card */
        .results-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px 20px;
            backdrop-filter: blur(10px);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            min-height: 400px;
        }

        .score {
            font-size: 64px;
            font-weight: bold;
            color: #f1c40f;
            margin: 20px 0;
            text-shadow: 0 0 20px rgba(241, 196, 15, 0.5);
        }

        .rank {
            font-size: 28px;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 30px;
            border-radius: 50px;
        }

        .correct-answer {
            background: rgba(46, 204, 113, 0.3);
            padding: 15px 25px;
            border-radius: 50px;
            margin-top: 20px;
            font-size: 18px;
            border: 2px solid #27ae60;
        }

        .waiting-message {
            margin-top: 30px;
            opacity: 0.8;
            font-size: 18px;
            animation: pulse 2s infinite;
        }

        /* Animations */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
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

        @keyframes countdown {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .countdown-number {
            animation: countdown 1s infinite;
        }

        /* Mobile optimizations */
        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }

            .question-text {
                font-size: 20px;
            }

            .option {
                padding: 15px;
                font-size: 16px;
            }

            .option-letter {
                width: 35px;
                height: 35px;
                line-height: 35px;
                font-size: 16px;
                margin-right: 10px;
            }

            .timer {
                font-size: 60px;
            }

            .score {
                font-size: 48px;
            }
        }

        /* Landscape mode */
        @media (max-height: 600px) and (orientation: landscape) {
            .question-card {
                min-height: 300px;
            }

            .options {
                gap: 8px;
            }

            .option {
                padding: 10px;
            }

            .question-timer {
                font-size: 36px;
                padding: 5px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="student-info">
                <div><?php echo $_SESSION['student_name']; ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Exit</a>
        </div>

        <!-- Main Content - This will be dynamically updated -->
        <div class="main-content" id="main-content">
            <!-- Content will be loaded here -->
        </div>
    </div>

    <script>
        // Global variables
        let currentQuestionId = null;
        let selectedOption = null;
        let questionStartTime = null;
        let answerSubmitted = false;
        let autoSubmitTimer = null;
        let correctAnswer = null;

        // Keyboard navigation variables
        let currentFocusIndex = -1;
        const optionKeys = ['A', 'B', 'C', 'D'];

        // Update quiz state every 100ms for smooth countdown
        setInterval(updateQuizState, 100);

        // Keyboard event listener
        document.addEventListener('keydown', handleKeyPress);

        function handleKeyPress(event) {
            // Ignore if typing in input field
            if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
                return;
            }

            const key = event.key.toUpperCase();

            // Check if it's a valid option key (A, B, C, D) and answer not submitted
            if (optionKeys.includes(key) && !answerSubmitted && currentQuestionId) {
                event.preventDefault(); // Prevent page scrolling

                // Add visual feedback
                const optionElement = document.querySelector(`.option[data-option="${key}"]`);
                if (optionElement && !optionElement.classList.contains('disabled')) {
                    // Remove previous keyboard focus
                    document.querySelectorAll('.option').forEach(opt => {
                        opt.classList.remove('keyboard-focus');
                    });

                    // Add focus to current option
                    optionElement.classList.add('keyboard-focus');

                    // Trigger click with ripple effect
                    createRipple(event, optionElement);
                    selectOption(key, correctAnswer);

                    // Remove focus after selection
                    setTimeout(() => {
                        optionElement.classList.remove('keyboard-focus');
                    }, 300);
                }
            }

            // Arrow key navigation for laptop users
            if (!answerSubmitted && currentQuestionId) {
                switch (event.key) {
                    case 'ArrowUp':
                    case 'ArrowDown':
                        event.preventDefault();
                        navigateOptions(event.key);
                        break;
                    case 'Enter':
                    case ' ':
                        event.preventDefault();
                        if (currentFocusIndex !== -1) {
                            const focusedOption = document.querySelectorAll('.option:not(.disabled)')[currentFocusIndex];
                            if (focusedOption) {
                                const optionLetter = focusedOption.querySelector('.option-letter').textContent;
                                createRipple(event, focusedOption);
                                selectOption(optionLetter, correctAnswer);
                            }
                        }
                        break;
                }
            }
        }

        function navigateOptions(direction) {
            const options = document.querySelectorAll('.option:not(.disabled)');
            if (options.length === 0) return;

            // Remove previous focus
            document.querySelectorAll('.option').forEach(opt => {
                opt.classList.remove('keyboard-focus');
            });

            if (direction === 'ArrowUp') {
                currentFocusIndex = (currentFocusIndex - 1 + options.length) % options.length;
            } else if (direction === 'ArrowDown') {
                currentFocusIndex = (currentFocusIndex + 1) % options.length;
            }

            // Add focus to new option
            options[currentFocusIndex].classList.add('keyboard-focus');

            // Scroll into view if needed
            options[currentFocusIndex].scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function createRipple(event, element) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple');

            const rect = element.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);

            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (event.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (event.clientY - rect.top - size / 2) + 'px';

            element.appendChild(ripple);

            setTimeout(() => {
                ripple.remove();
            }, 600);
        }

        function updateQuizState() {
            fetch('../api/get_quiz_data.php?action=state')
                .then(response => response.json())
                .then(data => {
                    const mainContent = document.getElementById('main-content');

                    switch (data.status) {
                        case 'waiting':
                            mainContent.innerHTML = `
                        <div class="status-card">
                            <div class="status-icon">⏳</div>
                            <div class="status-title">Ready to Play!</div>
                            <div class="status-message">Waiting for quiz to start...</div>
                        </div>
                    `;
                            answerSubmitted = false;
                            currentFocusIndex = -1;
                            break;

                        case 'countdown':
                            // This is the preparation countdown before question appears
                            mainContent.innerHTML = `
                        <div class="status-card">
                            <div class="status-icon">⚠️</div>
                            <div class="status-title">Get Ready!</div>
                            <div class="timer countdown-number">${Math.ceil(data.time_left)}</div>
                            <div class="status-message">Question starts in ${Math.ceil(data.time_left)} seconds...</div>
                        </div>
                    `;
                            answerSubmitted = false;
                            currentFocusIndex = -1;
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
                                    currentFocusIndex = -1;

                                    // Clear any existing auto-submit timer
                                    if (autoSubmitTimer) {
                                        clearTimeout(autoSubmitTimer);
                                        autoSubmitTimer = null;
                                    }
                                }

                                mainContent.innerHTML = `
                            <div class="question-card">
                                <div class="keyboard-hint">
                                    💡 Keyboard shortcuts: Press <span>A</span> <span>B</span> <span>C</span> <span>D</span> to answer | 
                                    <span>↑</span> <span>↓</span> arrows to navigate | <span>Enter</span> to select
                                </div>
                                <div class="question-timer">${Math.ceil(data.time_left)}s</div>
                                <div class="question-text">${data.question.question_text}</div>
                                <div class="options" id="options">
                                    <div class="option ${answerSubmitted && data.question.correct_option === 'A' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'A' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'A' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                         data-option="A"
                                         onclick="${!answerSubmitted ? `selectOption('A', '${data.question.correct_option}')` : ''}">
                                        <span class="option-letter">A</span>
                                        <span class="option-text">${data.question.option_a}</span>
                                    </div>
                                    <div class="option ${answerSubmitted && data.question.correct_option === 'B' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'B' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'B' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                         data-option="B"
                                         onclick="${!answerSubmitted ? `selectOption('B', '${data.question.correct_option}')` : ''}">
                                        <span class="option-letter">B</span>
                                        <span class="option-text">${data.question.option_b}</span>
                                    </div>
                                    <div class="option ${answerSubmitted && data.question.correct_option === 'C' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'C' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'C' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                         data-option="C"
                                         onclick="${!answerSubmitted ? `selectOption('C', '${data.question.correct_option}')` : ''}">
                                        <span class="option-letter">C</span>
                                        <span class="option-text">${data.question.option_c}</span>
                                    </div>
                                    <div class="option ${answerSubmitted && data.question.correct_option === 'D' ? 'correct' : ''} ${answerSubmitted && selectedOption === 'D' && selectedOption !== data.question.correct_option ? 'incorrect' : ''} ${selectedOption === 'D' && !answerSubmitted ? 'selected' : ''} ${answerSubmitted ? 'disabled' : ''}" 
                                         data-option="D"
                                         onclick="${!answerSubmitted ? `selectOption('D', '${data.question.correct_option}')` : ''}">
                                        <span class="option-letter">D</span>
                                        <span class="option-text">${data.question.option_d}</span>
                                    </div>
                                </div>
                                <div id="answerStatus" class="answer-status" style="display: none;"></div>
                            </div>
                        `;

                                // Reset focus index when new question loads
                                currentFocusIndex = -1;
                            }
                            break;

                        case 'results':
                            if (!answerSubmitted) {
                                fetchResults();
                            }
                            break;
                    }
                });
        }

        function selectOption(option, correctAnswer) {
            if (answerSubmitted) return;

            selectedOption = option;

            // Remove selected class from all options
            document.querySelectorAll('.option').forEach(opt => {
                opt.classList.remove('selected');
                opt.classList.remove('keyboard-focus');
            });

            // Add selected class to clicked option
            const selectedElement = document.querySelector(`.option[data-option="${option}"]`);
            if (selectedElement) {
                selectedElement.classList.add('selected');
            }

            // Automatically submit the answer when selected
            submitAnswer(option, correctAnswer);
        }

        function submitAnswer(option, correctAnswer) {
            if (answerSubmitted) return;

            const timeTaken = (new Date() - questionStartTime) / 1000;

            // Show submitting status
            const statusDiv = document.getElementById('answerStatus');
            statusDiv.style.display = 'block';
            statusDiv.className = 'answer-status';
            statusDiv.textContent = 'Submitting answer...';

            // Disable all options immediately to prevent double submission
            document.querySelectorAll('.option').forEach(opt => {
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
                        // Show success/failure with visual feedback
                        statusDiv.className = 'answer-status ' + (data.correct ? 'success' : 'error');

                        if (data.correct) {
                            statusDiv.textContent = `✅ Correct! ${data.points_earned} points (${data.time_taken.toFixed(3)}s)`;
                        } else {
                            statusDiv.textContent = `❌ Incorrect. The correct answer was ${data.correct_answer}`;
                        }

                        // Highlight correct/incorrect answers
                        document.querySelectorAll('.option').forEach(opt => {
                            const optLetter = opt.querySelector('.option-letter').textContent;
                            if (optLetter === data.correct_answer) {
                                opt.classList.add('correct');
                            } else if (optLetter === option && !data.correct) {
                                opt.classList.add('incorrect');
                            }
                        });
                    } else {
                        statusDiv.className = 'answer-status error';
                        statusDiv.textContent = data.error || 'Error submitting answer. Please wait...';

                        // Re-enable options on error
                        if (!data.already_answered) {
                            document.querySelectorAll('.option').forEach(opt => {
                                opt.classList.remove('disabled');
                            });
                        }
                    }

                    // Hide status after 3 seconds
                    setTimeout(() => {
                        statusDiv.style.display = 'none';
                    }, 3000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusDiv.className = 'answer-status error';
                    statusDiv.textContent = 'Network error. Please wait...';

                    // Re-enable options on error
                    document.querySelectorAll('.option').forEach(opt => {
                        opt.classList.remove('disabled');
                    });
                });
        }

        function fetchResults() {
            fetch(`../api/get_quiz_data.php?action=student_results&student_id=<?php echo $student_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    const mainContent = document.getElementById('main-content');

                    mainContent.innerHTML = `
                        <div class="results-card">
                            <div class="status-icon">🏆</div>
                            <div class="status-title">Question Complete!</div>
                            <div class="score">${data.total_points} pts</div>
                            <div class="rank">Rank #${data.rank}</div>
                            ${data.last_answer_correct ? 
                                '<div class="correct-answer">✅ You got it right!</div>' : 
                                '<div class="correct-answer" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">❌ Better luck next time!</div>'
                            }
                            <div class="waiting-message">Next question starting soon...</div>
                        </div>
                    `;

                    answerSubmitted = true;
                    currentFocusIndex = -1;
                });
        }

        // Prevent accidental page refresh
        window.addEventListener('beforeunload', function(e) {
            if (!answerSubmitted && currentQuestionId) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Add click event listener with ripple effect for laptop users
        document.addEventListener('click', function(event) {
            const option = event.target.closest('.option:not(.disabled)');
            if (option && !answerSubmitted) {
                const optionLetter = option.querySelector('.option-letter').textContent;
                createRipple(event, option);
                selectOption(optionLetter, correctAnswer);
            }
        }, true);
    </script>
</body>

</html>