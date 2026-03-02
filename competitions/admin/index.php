<?php
require_once '../includes/functions.php';

// Get quiz state
$state = getQuizState();
$current_question = getCurrentQuestion();

// Get all students with their total scores
$students_sql = "SELECT s.id, s.name, COALESCE(ss.total_points, 0) as total_points,
                 s.last_activity
                 FROM students s
                 LEFT JOIN student_scores ss ON s.id = ss.student_id
                 ORDER BY s.name ASC";
$students_result = $conn->query($students_sql);
$students = [];
while ($row = $students_result->fetch_assoc()) {
    $students[] = $row;
}

// Get current question correct answer for display
$correct_answer = '';
if ($current_question) {
    $correct_answer = $current_question['correct_option'];
}

// Calculate number of students to adjust heights
$student_count = count($students);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Quiz Arena - Live Leaderboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Arial Black', sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            color: white;
            overflow: hidden;
            position: relative;
        }

        /* Animated background particles */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            animation: float 10s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        .container {
            position: relative;
            z-index: 1;
            width: 100vw;
            height: 100vh;
            padding: 10px;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(5px);
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 10px 15px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            height: auto;
            min-height: 60px;
        }

        .title {
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: linear-gradient(135deg, #fff, #f1c40f);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 60%;
        }

        .timer-box {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            padding: 5px 12px;
            text-align: center;
            border: 2px solid #f1c40f;
        }

        .timer-label {
            font-size: 10px;
            opacity: 0.8;
        }

        .timer-value {
            font-size: 20px;
            font-weight: bold;
            font-family: monospace;
            color: #f1c40f;
            line-height: 1.2;
        }

        /* Question Screen - Full Screen */
        .question-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            z-index: 10;
            display: flex;
            flex-direction: column;
            padding: 15px;
            transition: all 0.5s ease;
            opacity: 1;
            visibility: visible;
        }

        .question-screen.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .question-screen .header {
            margin-bottom: 20px;
        }

        .question-display {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            overflow-y: auto;
            padding-bottom: 20px;
        }

        .question-text {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 20px;
            line-height: 1.4;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 15px;
            border-left: 5px solid #f1c40f;
            text-align: center;
            width: 100%;
            word-break: break-word;
        }

        .options-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            width: 100%;
        }

        .option-card {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
            word-break: break-word;
        }

        .option-card.correct-highlight {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            border-color: white;
            animation: correctPulse 1s infinite;
            transform: scale(1.02);
            box-shadow: 0 0 30px rgba(46, 204, 113, 0.5);
        }

        .option-letter {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            margin-right: 15px;
            font-weight: bold;
            font-size: 20px;
            flex-shrink: 0;
        }

        .status-display {
            margin-top: 30px;
            text-align: center;
            font-size: 32px;
            font-weight: bold;
            animation: statusPulse 1.5s infinite;
        }

        @keyframes statusPulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.7;
                transform: scale(1.05);
            }
        }

        .waiting-message {
            font-size: 32px;
            color: #f1c40f;
        }

        /* Leaderboard Screen - Full Screen */
        .leaderboard-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            z-index: 20;
            display: flex;
            flex-direction: column;
            padding: 15px;
            transition: all 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .leaderboard-screen.visible {
            opacity: 1;
            visibility: visible;
            pointer-events: all;
        }

        .leaderboard-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
            height: 100%;
            min-height: 0;
        }

        .leaderboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 10px 10px 10px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 15px;
        }

        .leaderboard-title {
            font-size: 24px;
            text-transform: uppercase;
            color: #f1c40f;
            letter-spacing: 1px;
            animation: titleGlow 2s infinite;
        }

        @keyframes titleGlow {

            0%,
            100% {
                text-shadow: 0 0 10px #f1c40f;
            }

            50% {
                text-shadow: 0 0 20px #f1c40f;
            }
        }

        .leaderboard-stats {
            font-size: 12px;
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .leaderboard-list {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            overflow-y: auto;
            padding-right: 5px;
            min-height: 0;
            height: 100%;
        }

        /* Custom scrollbar */
        .leaderboard-list::-webkit-scrollbar {
            width: 4px;
        }

        .leaderboard-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .leaderboard-list::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        /* Student Items */
        .student-item {
            background: rgba(255, 255, 255, 0.07);
            border-radius: 12px;
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            height: auto;
            min-height: 60px;
            flex-shrink: 0;
            font-size: 14px;
        }

        .student-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .student-item.updated::before {
            left: 100%;
        }

        /* DRAMATIC MOVEMENT ANIMATIONS */
        .student-item.moving-up {
            animation: dramaticMoveUp 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, rgba(46, 204, 113, 0.5), rgba(46, 204, 113, 0.3));
            border-color: #27ae60;
            box-shadow: 0 0 30px rgba(46, 204, 113, 0.8);
            z-index: 100;
            transform: scale(1.01);
        }

        .student-item.moving-down {
            animation: dramaticMoveDown 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.5), rgba(231, 76, 60, 0.3));
            border-color: #e74c3c;
            box-shadow: 0 0 30px rgba(231, 76, 60, 0.8);
            z-index: 100;
            transform: scale(1.01);
        }

        @keyframes dramaticMoveUp {
            0% {
                transform: translateY(0) scale(1);
            }

            15% {
                transform: translateY(-30px) scale(1.1);
                background: rgba(46, 204, 113, 0.8);
                box-shadow: 0 30px 40px rgba(46, 204, 113, 0.9);
            }

            30% {
                transform: translateY(15px) scale(0.97);
            }

            45% {
                transform: translateY(-10px) scale(1.05);
            }

            60% {
                transform: translateY(5px) scale(0.99);
            }

            75% {
                transform: translateY(-3px) scale(1.01);
            }

            100% {
                transform: translateY(0) scale(1);
            }
        }

        @keyframes dramaticMoveDown {
            0% {
                transform: translateY(0) scale(1);
            }

            15% {
                transform: translateY(30px) scale(0.9);
                background: rgba(231, 76, 60, 0.8);
                box-shadow: 0 -30px 40px rgba(231, 76, 60, 0.9);
            }

            30% {
                transform: translateY(-15px) scale(1.05);
            }

            45% {
                transform: translateY(10px) scale(0.97);
            }

            60% {
                transform: translateY(-5px) scale(1.02);
            }

            75% {
                transform: translateY(3px) scale(0.99);
            }

            100% {
                transform: translateY(0) scale(1);
            }
        }

        /* POSITION CHANGE INDICATORS - VERY VISIBLE */
        .position-change {
            position: absolute;
            right: 80px;
            font-size: 20px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 30px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            border: 1px solid;
            z-index: 200;
            animation: indicatorPop 1.8s ease-out forwards;
            box-shadow: 0 0 20px currentColor;
        }

        .position-change.up {
            color: #27ae60;
            border-color: #27ae60;
            text-shadow: 0 0 10px #27ae60;
        }

        .position-change.down {
            color: #e74c3c;
            border-color: #e74c3c;
            text-shadow: 0 0 10px #e74c3c;
        }

        @keyframes indicatorPop {
            0% {
                opacity: 0;
                transform: translateX(30px) scale(0.5);
            }

            20% {
                opacity: 1;
                transform: translateX(-10px) scale(1.2);
            }

            40% {
                transform: translateX(3px) scale(1.05);
            }

            60% {
                transform: translateX(-1px) scale(1);
                opacity: 1;
            }

            100% {
                opacity: 0;
                transform: translateX(20px) scale(0.8);
            }
        }

        /* SCORE COUNTING ANIMATION - VERY VISIBLE */
        .student-item.points-counting {
            background: linear-gradient(135deg, rgba(241, 196, 15, 0.5), rgba(241, 196, 15, 0.3));
            border-color: #f1c40f;
            box-shadow: 0 0 40px rgba(241, 196, 15, 0.8);
            transform: scale(1.01);
            z-index: 50;
        }

        .student-points {
            font-size: 18px;
            font-weight: bold;
            color: #f1c40f;
            margin-left: 5px;
            font-family: monospace;
            min-width: 50px;
            text-align: right;
            flex-shrink: 0;
            transition: all 0.1s;
            position: relative;
            z-index: 10;
        }

        .student-points.counting {
            animation: coinCount 0.15s infinite;
            color: #fff;
            text-shadow: 0 0 15px #f1c40f, 0 0 30px #f1c40f;
            font-weight: bold;
            transform-origin: center;
        }

        @keyframes coinCount {
            0% {
                transform: scale(1) translateY(0);
                color: #f1c40f;
            }

            25% {
                transform: scale(1.3) translateY(-5px);
                color: #fff;
            }

            50% {
                transform: scale(1.5) translateY(-10px);
                color: #f1c40f;
                text-shadow: 0 0 20px #f1c40f;
            }

            75% {
                transform: scale(1.2) translateY(-3px);
                color: #fff;
            }

            100% {
                transform: scale(1) translateY(0);
                color: #f1c40f;
            }
        }

        .student-points.final-value {
            animation: pointsLand 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes pointsLand {
            0% {
                transform: scale(1.8) translateY(-15px);
                color: #fff;
                text-shadow: 0 0 30px #27ae60, 0 0 60px #27ae60;
            }

            40% {
                transform: scale(0.9) translateY(5px);
                color: #f1c40f;
            }

            70% {
                transform: scale(1.1) translateY(-3px);
                color: #f1c40f;
            }

            100% {
                transform: scale(1) translateY(0);
                color: #f1c40f;
            }
        }

        /* PROGRESS BAR ANIMATION */
        .student-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 5px;
            overflow: hidden;
            width: 100%;
            position: relative;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #f1c40f, #f39c12, #f1c40f);
            border-radius: 5px;
            width: 0%;
            transition: width 1.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            background-size: 200% 100%;
            animation: progressShimmer 2s infinite;
        }

        @keyframes progressShimmer {
            0% {
                background-position: 100% 0;
            }

            100% {
                background-position: -100% 0;
            }
        }

        .progress-fill.animating {
            animation: progressPulse 0.5s infinite, progressShimmer 1s infinite;
            box-shadow: 0 0 15px #f1c40f;
        }

        @keyframes progressPulse {

            0%,
            100% {
                filter: brightness(1);
            }

            50% {
                filter: brightness(1.5);
                box-shadow: 0 0 20px #f1c40f;
            }
        }

        .student-item.first-place {
            background: linear-gradient(135deg, rgba(241, 196, 15, 0.4), rgba(241, 196, 15, 0.2));
            border-color: #f1c40f;
            border-width: 2px;
            box-shadow: 0 0 30px rgba(241, 196, 15, 0.5);
        }

        .student-item.second-place {
            background: linear-gradient(135deg, rgba(192, 192, 192, 0.3), rgba(192, 192, 192, 0.1));
            border-color: #C0C0C0;
        }

        .student-item.third-place {
            background: linear-gradient(135deg, rgba(205, 127, 50, 0.3), rgba(205, 127, 50, 0.1));
            border-color: #CD7F32;
        }

        .student-rank {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .first-place .student-rank {
            background: #f1c40f;
            color: #000;
            box-shadow: 0 0 30px #f1c40f;
            animation: rankGlow 1.5s infinite;
            border-color: #fff;
        }

        .second-place .student-rank {
            background: #C0C0C0;
            color: #000;
            box-shadow: 0 0 20px #C0C0C0;
        }

        .third-place .student-rank {
            background: #CD7F32;
            color: #000;
            box-shadow: 0 0 20px #CD7F32;
        }

        @keyframes rankGlow {

            0%,
            100% {
                box-shadow: 0 0 20px #f1c40f, 0 0 40px #f1c40f;
            }

            50% {
                box-shadow: 0 0 40px #f1c40f, 0 0 60px #f1c40f;
            }
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
            margin-bottom: 4px;
        }

        /* Results Overlay */
        .results-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(15px);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .results-overlay.active {
            display: flex;
        }

        .results-content {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            border-radius: 30px;
            padding: 30px 20px;
            text-align: center;
            border: 3px solid #f1c40f;
            animation: resultsPop 0.5s ease;
            max-width: 500px;
            width: 100%;
        }

        @keyframes resultsPop {
            from {
                transform: scale(0.3);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .results-title {
            font-size: 28px;
            color: #f1c40f;
            margin-bottom: 20px;
        }

        .correct-answer-display {
            font-size: 48px;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px 30px;
            border-radius: 50px;
            margin: 20px 0;
            font-weight: bold;
            letter-spacing: 2px;
            animation: correctAnswerPop 2s infinite;
        }

        @keyframes correctAnswerPop {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 0 20px #f1c40f;
            }

            50% {
                transform: scale(1.05);
                box-shadow: 0 0 50px #f1c40f;
            }
        }

        .results-message {
            font-size: 16px;
            margin-top: 20px;
            opacity: 0.9;
        }

        /* Coin rain effect */
        .coin-rain {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2000;
        }

        .coin {
            position: absolute;
            width: 20px;
            height: 20px;
            background: radial-gradient(circle at 30% 30%, #f1c40f, #b8860b);
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 0 15px #f1c40f;
            animation: coinFall 2s linear forwards;
            z-index: 2000;
        }

        @keyframes coinFall {
            0% {
                transform: translateY(-100px) rotate(0deg) scale(1);
                opacity: 1;
            }

            100% {
                transform: translateY(100vh) rotate(720deg) scale(0.5);
                opacity: 0;
            }
        }

        /* Confetti */
        .confetti {
            position: absolute;
            width: 8px;
            height: 8px;
            background: #f1c40f;
            opacity: 0.8;
            animation: confettiFall 3s linear infinite;
            pointer-events: none;
            z-index: 2000;
        }

        @keyframes confettiFall {
            0% {
                transform: translateY(-100vh) rotate(0deg);
                opacity: 1;
            }

            100% {
                transform: translateY(100vh) rotate(720deg);
                opacity: 0;
            }
        }

        /* Tablet Styles */
        @media (min-width: 768px) {
            .container {
                padding: 20px;
            }

            .header {
                padding: 15px 25px;
                min-height: 70px;
            }

            .title {
                font-size: 24px;
            }

            .timer-value {
                font-size: 28px;
            }

            .question-text {
                font-size: 32px;
                padding: 30px;
            }

            .options-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .option-card {
                padding: 20px;
                font-size: 20px;
            }

            .leaderboard-title {
                font-size: 32px;
            }

            .student-item {
                padding: 12px 20px;
                font-size: 16px;
                min-height: 70px;
            }

            .student-name {
                font-size: 18px;
            }

            .student-points {
                font-size: 22px;
                min-width: 70px;
            }

            .student-rank {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }

            .position-change {
                right: 100px;
                font-size: 24px;
            }
        }

        /* Small phones */
        @media (max-width: 380px) {
            .title {
                font-size: 14px;
            }

            .timer-box {
                padding: 3px 8px;
            }

            .timer-value {
                font-size: 16px;
            }

            .student-item {
                padding: 8px 10px;
                gap: 6px;
            }

            .student-rank {
                width: 30px;
                height: 30px;
                font-size: 14px;
            }

            .student-name {
                font-size: 14px;
            }

            .student-points {
                font-size: 16px;
                min-width: 40px;
            }

            .position-change {
                right: 60px;
                font-size: 16px;
                padding: 2px 6px;
            }

            .question-text {
                font-size: 18px;
                padding: 15px;
            }

            .option-card {
                padding: 12px;
                font-size: 14px;
            }

            .option-letter {
                width: 32px;
                height: 32px;
                line-height: 32px;
                font-size: 16px;
                margin-right: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- Animated particles -->
    <div class="particles" id="particles"></div>

    <!-- Coin rain container -->
    <div class="coin-rain" id="coinRain"></div>

    <div class="container">
        <!-- Header (always visible) -->
        <div class="header">
            <div class="title">🏆 QUIZ ARENA 🏆</div>
            <div class="timer-box" id="timerBox">
                <div class="timer-label" id="timerLabel">Ready</div>
                <div class="timer-value" id="timerValue">0</div>
            </div>
        </div>

        <!-- Question Screen (visible during question) -->
        <div class="question-screen" id="questionScreen">
            <div class="question-display">
                <div class="question-text" id="questionText">Waiting for next question...</div>
                <div class="options-grid" id="optionsGrid">
                    <!-- Options will be populated here -->
                </div>
                <div class="status-display" id="statusDisplay"></div>
            </div>
        </div>

        <!-- Leaderboard Screen (visible after question) -->
        <div class="leaderboard-screen" id="leaderboardScreen">
            <div class="leaderboard-content">
                <div class="leaderboard-header">
                    <span class="leaderboard-title">🏆 LEADERBOARD</span>
                    <span class="leaderboard-stats" id="leaderboardStats"><?php echo $student_count; ?> players</span>
                </div>
                <div class="leaderboard-list" id="leaderboardList">
                    <!-- Student items will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Results Overlay -->
    <div class="results-overlay" id="resultsOverlay">
        <div class="results-content">
            <div class="results-title">🏆 QUESTION COMPLETE 🏆</div>
            <div class="correct-answer-display" id="correctAnswerDisplay"></div>
            <div class="results-message">Get ready for the leaderboard!</div>
        </div>
    </div>

    <script>
        // Global variables
        let students = <?php echo json_encode($students); ?>;
        let previousScores = {};
        let scoresLocked = false;
        let correctAnswer = '<?php echo $correct_answer; ?>';
        let animationInProgress = false;
        let currentStatus = 'waiting';

        // Calculate max points
        const maxPoints = Math.max(...students.map(s => s.total_points), 1000);

        // Initialize previous scores
        students.forEach(student => {
            previousScores[student.id] = student.total_points;
        });

        // Create particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            for (let i = 0; i < 30; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 8 + 's';
                particle.style.animationDuration = (8 + Math.random() * 8) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Switch screens
        function switchToQuestionScreen() {
            document.getElementById('questionScreen').classList.remove('hidden');
            document.getElementById('leaderboardScreen').classList.remove('visible');
        }

        function switchToLeaderboardScreen() {
            document.getElementById('questionScreen').classList.add('hidden');
            document.getElementById('leaderboardScreen').classList.add('visible');
        }

        // Get rank class
        function getRankClass(rank) {
            if (rank === 1) return 'first-place';
            if (rank === 2) return 'second-place';
            if (rank === 3) return 'third-place';
            return '';
        }

        // Load leaderboard
        function loadLeaderboard(studentData) {
            const sortedStudents = [...studentData].sort((a, b) => b.total_points - a.total_points);
            const listEl = document.getElementById('leaderboardList');
            let html = '';

            sortedStudents.forEach((student, index) => {
                const rank = index + 1;
                const progressWidth = (student.total_points / maxPoints) * 100;
                const rankClass = getRankClass(rank);

                html += `
                <div class="student-item ${rankClass}" data-student-id="${student.id}">
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
            document.getElementById('leaderboardStats').textContent = `${studentData.length} players`;
        }

        // Animate a single student's score
        function animateStudentScore(element, newScore) {
            return new Promise((resolve) => {
                const pointsEl = element.querySelector('.student-points');
                const progressEl = element.querySelector('.progress-fill');
                const currentScore = parseInt(pointsEl.textContent);
                const difference = newScore - currentScore;

                if (difference <= 0) {
                    resolve();
                    return;
                }

                // Add highlighting
                element.classList.add('points-counting');
                pointsEl.classList.add('counting');
                progressEl.classList.add('animating');

                let currentValue = currentScore;
                const steps = 15; // Reduced for mobile
                const increment = difference / steps;
                let step = 0;

                const timer = setInterval(() => {
                    step++;
                    currentValue += increment;
                    const rounded = Math.round(currentValue);

                    // Update display
                    pointsEl.textContent = rounded;
                    progressEl.style.width = (rounded / maxPoints) * 100 + '%';

                    // Bounce effect
                    if (step % 4 === 0) {
                        pointsEl.style.transform = 'scale(1.3)';
                        setTimeout(() => {
                            pointsEl.style.transform = 'scale(1)';
                        }, 80);
                    }

                    if (step >= steps) {
                        clearInterval(timer);
                        pointsEl.textContent = newScore;
                        progressEl.style.width = (newScore / maxPoints) * 100 + '%';

                        // Remove highlighting
                        pointsEl.classList.remove('counting');
                        pointsEl.classList.add('final-value');
                        progressEl.classList.remove('animating');

                        setTimeout(() => {
                            pointsEl.classList.remove('final-value');
                            element.classList.remove('points-counting');
                            resolve();
                        }, 400);
                    }
                }, 60);
            });
        }

        // Move student to new position
        function moveStudent(element, newPosition) {
            return new Promise((resolve) => {
                const list = document.getElementById('leaderboardList');
                const currentPosition = Array.from(list.children).indexOf(element);

                if (currentPosition === newPosition) {
                    resolve();
                    return;
                }

                // Add movement animation class
                if (newPosition < currentPosition) {
                    element.classList.add('moving-up');
                } else {
                    element.classList.add('moving-down');
                }

                // Move in DOM after a tiny delay
                setTimeout(() => {
                    if (newPosition < currentPosition) {
                        // Moving up
                        list.insertBefore(element, list.children[newPosition]);
                    } else {
                        // Moving down
                        if (newPosition + 1 < list.children.length) {
                            list.insertBefore(element, list.children[newPosition + 1]);
                        } else {
                            list.appendChild(element);
                        }
                    }

                    // Update all rank numbers
                    const items = list.children;
                    for (let i = 0; i < items.length; i++) {
                        const rank = i + 1;
                        const rankEl = items[i].querySelector('.student-rank');
                        rankEl.textContent = `#${rank}`;

                        // Update rank classes
                        items[i].classList.remove('first-place', 'second-place', 'third-place');
                        if (rank === 1) items[i].classList.add('first-place');
                        else if (rank === 2) items[i].classList.add('second-place');
                        else if (rank === 3) items[i].classList.add('third-place');
                    }

                    // Remove animation class after movement
                    setTimeout(() => {
                        element.classList.remove('moving-up', 'moving-down');
                        resolve();
                    }, 800);
                }, 50);
            });
        }

        // Show position change arrow
        function showArrow(element, change) {
            if (change === 0) return;

            // Remove existing arrow
            const oldArrow = element.querySelector('.position-change');
            if (oldArrow) oldArrow.remove();

            // Create new arrow
            const arrow = document.createElement('div');
            arrow.className = `position-change ${change > 0 ? 'up' : 'down'}`;
            arrow.innerHTML = change > 0 ? `↑ ${change}` : `↓ ${Math.abs(change)}`;
            element.appendChild(arrow);

            // Auto remove after 2 seconds
            setTimeout(() => {
                if (arrow.parentNode) arrow.remove();
            }, 1800);
        }

        // Create confetti
        function createConfetti() {
            for (let i = 0; i < 30; i++) { // Reduced for mobile
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.background = `hsl(${Math.random() * 360}, 100%, 50%)`;
                    confetti.style.animationDelay = '0s';
                    confetti.style.width = (6 + Math.random() * 8) + 'px';
                    confetti.style.height = (6 + Math.random() * 8) + 'px';
                    document.body.appendChild(confetti);

                    setTimeout(() => {
                        confetti.remove();
                    }, 2500);
                }, i * 20);
            }
        }

        // Main animation sequence
        async function animateLeaderboard(newStudents) {
            if (animationInProgress) return;
            animationInProgress = true;

            // Get current rankings
            const list = document.getElementById('leaderboardList');
            const currentItems = Array.from(list.children);
            const currentRanks = {};
            currentItems.forEach((item, index) => {
                const id = item.dataset.studentId;
                currentRanks[id] = index;
            });

            // Sort new students by score
            const sortedNew = [...newStudents].sort((a, b) => b.total_points - a.total_points);

            // Animate each student
            for (let i = 0; i < sortedNew.length; i++) {
                const student = sortedNew[i];
                const element = document.querySelector(`.student-item[data-student-id="${student.id}"]`);

                if (element) {
                    const oldRank = currentRanks[student.id] + 1;
                    const newRank = i + 1;

                    // Count up the score
                    await animateStudentScore(element, student.total_points);

                    // Show arrow if rank changed
                    if (oldRank !== newRank) {
                        showArrow(element, oldRank - newRank);
                    }

                    // Move to new position
                    await moveStudent(element, i);

                    // Pause between students
                    await new Promise(resolve => setTimeout(resolve, 300));
                }
            }

            // Celebration
            createConfetti();
            animationInProgress = false;
        }

        // Check for updates
        function checkForUpdates() {
            fetch('../api/get_quiz_data.php?action=state')
                .then(response => response.json())
                .then(data => {
                    const timerLabel = document.getElementById('timerLabel');
                    const timerValue = document.getElementById('timerValue');
                    const questionText = document.getElementById('questionText');
                    const optionsGrid = document.getElementById('optionsGrid');
                    const statusDisplay = document.getElementById('statusDisplay');
                    const resultsOverlay = document.getElementById('resultsOverlay');
                    const correctAnswerDisplay = document.getElementById('correctAnswerDisplay');

                    switch (data.status) {
                        case 'waiting':
                            timerLabel.textContent = 'Waiting';
                            timerValue.textContent = '∞';
                            questionText.textContent = 'Waiting for next question...';
                            optionsGrid.innerHTML = '';
                            statusDisplay.innerHTML = '<div class="waiting-message">⏳ GET READY ⏳</div>';
                            scoresLocked = false;
                            resultsOverlay.classList.remove('active');
                            switchToQuestionScreen();
                            currentStatus = 'waiting';
                            break;

                        case 'countdown':
                            timerLabel.textContent = 'Get Ready!';
                            timerValue.textContent = Math.ceil(data.time_left);
                            questionText.textContent = 'Question starting in...';
                            statusDisplay.innerHTML = `<div class="waiting-message">${Math.ceil(data.time_left)}</div>`;
                            scoresLocked = false;
                            resultsOverlay.classList.remove('active');
                            switchToQuestionScreen();
                            currentStatus = 'countdown';
                            break;

                        case 'question':
                            timerLabel.textContent = 'Question Time';
                            timerValue.textContent = Math.ceil(data.time_left);
                            scoresLocked = false;
                            resultsOverlay.classList.remove('active');
                            switchToQuestionScreen();
                            currentStatus = 'question';

                            if (data.question) {
                                questionText.textContent = data.question.question_text;
                                correctAnswer = data.question.correct_option;

                                const options = ['A', 'B', 'C', 'D'];
                                let optionsHtml = '';
                                options.forEach(opt => {
                                    optionsHtml += `
                                    <div class="option-card">
                                        <span class="option-letter">${opt}</span>
                                        ${data.question['option_' + opt.toLowerCase()]}
                                    </div>
                                `;
                                });
                                optionsGrid.innerHTML = optionsHtml;
                                statusDisplay.innerHTML = '⚡ ANSWER NOW ⚡';
                            }

                            // Update leaderboard in background without animation
                            fetch('../api/get_quiz_data.php?action=all_students')
                                .then(response => response.json())
                                .then(students => {
                                    loadLeaderboard(students);
                                });
                            break;

                        case 'results':
                            timerLabel.textContent = 'Results';
                            timerValue.textContent = '🏆';

                            if (!scoresLocked) {
                                scoresLocked = true;

                                // Highlight correct answer
                                if (correctAnswer) {
                                    const options = document.querySelectorAll('.option-card');
                                    options.forEach((opt, index) => {
                                        const letter = String.fromCharCode(65 + index);
                                        if (letter === correctAnswer) {
                                            opt.classList.add('correct-highlight');
                                        }
                                    });
                                }

                                // Show results overlay
                                correctAnswerDisplay.textContent = `${correctAnswer}`;
                                resultsOverlay.classList.add('active');

                                // Fetch updated scores
                                fetch('../api/get_quiz_data.php?action=all_students')
                                    .then(response => response.json())
                                    .then(newStudents => {
                                        // Store the new scores for later
                                        window.pendingScores = newStudents;
                                    });

                                // Switch to leaderboard after delay
                                setTimeout(() => {
                                    resultsOverlay.classList.remove('active');
                                    switchToLeaderboardScreen();

                                    // Trigger animation with the new scores
                                    setTimeout(() => {
                                        if (window.pendingScores) {
                                            animateLeaderboard(window.pendingScores);
                                            // Update previous scores
                                            window.pendingScores.forEach(student => {
                                                previousScores[student.id] = student.total_points;
                                            });
                                        }
                                    }, 500);
                                }, 3000);
                            }
                            currentStatus = 'results';
                            break;
                    }
                });
        }

        // Initialize
        createParticles();
        loadLeaderboard(students);
        switchToQuestionScreen();

        // Start checking for updates
        setInterval(checkForUpdates, 100);
    </script>
</body>

</html>