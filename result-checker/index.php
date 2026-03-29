<?php
// index.php - Updated with school-specific class selection
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Check your child's academic results online securely. Enter admission number, select session and term, and use your result checker PIN.">
    <title>MyResultChecker - Check Student Results Online</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --secondary: #3498db;
            --secondary-dark: #2980b9;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --gray: #7f8c8d;
            --white: #ffffff;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') repeat-x bottom;
            background-size: cover;
            pointer-events: none;
            z-index: 0;
        }

        .container {
            max-width: 550px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            text-align: center;
            padding: 40px 20px 30px;
            color: white;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .logo i {
            font-size: 40px;
            color: white;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .checker-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        label i {
            margin-right: 8px;
            color: var(--secondary);
            width: 20px;
        }

        select,
        input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--light);
            border-radius: var(--radius-md);
            font-size: 15px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }

        select:focus,
        input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        select:disabled,
        input:disabled {
            background: var(--light);
            cursor: not-allowed;
        }

        .pin-input-wrapper {
            position: relative;
        }

        .pin-input-wrapper input {
            padding-right: 50px;
        }

        .toggle-pin {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray);
            font-size: 18px;
            padding: 5px;
        }

        .toggle-pin:hover {
            color: var(--secondary);
        }

        .btn-check {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-check:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.3);
        }

        .btn-check:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 25px;
            padding: 20px;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid var(--light);
            border-top-color: var(--secondary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loading p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .error-message {
            background: #fef2f2;
            color: var(--danger);
            padding: 15px 18px;
            border-radius: var(--radius-md);
            margin-top: 20px;
            display: none;
            align-items: center;
            gap: 12px;
            border-left: 4px solid var(--danger);
            font-size: 0.9rem;
        }

        .error-message i {
            font-size: 20px;
        }

        .info-box {
            background: #e8f4fd;
            border-radius: var(--radius-md);
            padding: 15px 20px;
            margin-top: 20px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-left: 4px solid var(--secondary);
        }

        .info-box i {
            color: var(--secondary);
            font-size: 20px;
            margin-top: 2px;
        }

        .info-box p {
            font-size: 0.85rem;
            color: var(--dark);
            line-height: 1.5;
        }

        .info-box strong {
            color: var(--secondary);
        }

        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .feature {
            text-align: center;
            padding: 15px 10px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-md);
            color: white;
        }

        .feature i {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
        }

        .feature span {
            font-size: 0.75rem;
            font-weight: 500;
        }

        footer {
            text-align: center;
            margin-top: auto;
            padding: 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }

        footer a {
            color: white;
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }

        .result-container {
            display: none;
            margin-top: 30px;
        }

        .result-card {
            background: white;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .result-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 30px 25px;
            text-align: center;
        }

        .school-logo {
            width: 70px;
            height: 70px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .school-logo i {
            font-size: 35px;
            color: var(--primary);
        }

        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .school-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .result-title {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-top: 8px;
        }

        .student-info {
            background: var(--light);
            padding: 20px 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            border-bottom: 1px solid #ddd;
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .info-value {
            font-weight: 700;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .scores-section {
            padding: 20px 25px;
            overflow-x: auto;
        }

        .scores-section h3 {
            font-size: 1rem;
            margin-bottom: 15px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            min-width: 500px;
        }

        .scores-table th,
        .scores-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid var(--light);
        }

        .scores-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
        }

        .scores-table tr:hover {
            background: #fafafa;
        }

        .grade-A,
        .grade-B,
        .grade-C,
        .grade-D,
        .grade-E,
        .grade-F {
            font-weight: 700;
        }

        .grade-A {
            color: var(--success);
        }

        .grade-B {
            color: #27ae60;
        }

        .grade-C {
            color: var(--warning);
        }

        .grade-D {
            color: #e67e22;
        }

        .grade-E {
            color: var(--danger);
        }

        .grade-F {
            color: #c0392b;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 20px 25px;
            background: #f8f9fa;
            margin: 0 25px 20px;
            border-radius: var(--radius-md);
        }

        .summary-item {
            text-align: center;
        }

        .summary-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .comment-section {
            padding: 0 25px 20px;
        }

        .comment-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: var(--radius-md);
            margin-bottom: 15px;
        }

        .comment-label {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comment-text {
            font-size: 0.9rem;
            line-height: 1.5;
            color: #555;
        }

        .action-buttons {
            padding: 20px 25px;
            display: flex;
            gap: 15px;
            border-top: 1px solid var(--light);
        }

        .btn-print,
        .btn-download {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-print {
            background: var(--primary);
            color: white;
        }

        .btn-download {
            background: var(--success);
            color: white;
        }

        .btn-print:hover,
        .btn-download:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        .pin-remaining {
            text-align: center;
            padding: 15px;
            font-size: 0.75rem;
            color: var(--gray);
            background: #f8f9fa;
            border-top: 1px solid var(--light);
        }

        @media (max-width: 600px) {
            .container {
                padding: 15px;
            }

            .header {
                padding: 20px 15px;
            }

            .header h1 {
                font-size: 1.6rem;
            }

            .checker-card {
                padding: 20px;
            }

            .features {
                gap: 10px;
            }

            .feature {
                padding: 12px 8px;
            }

            .feature i {
                font-size: 20px;
            }

            .feature span {
                font-size: 0.7rem;
            }

            .student-info {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .summary-grid {
                grid-template-columns: 1fr 1fr;
                margin: 0 15px 15px;
                padding: 15px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .scores-section {
                padding: 15px;
            }

            .comment-section {
                padding: 0 15px 15px;
            }
        }

        @media (max-width: 400px) {
            .features {
                display: none;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            body {
                background: white;
            }

            .container {
                padding: 0;
                max-width: 100%;
            }

            .header,
            .checker-card,
            .features,
            footer,
            .action-buttons,
            .pin-remaining,
            .info-box {
                display: none !important;
            }

            .result-container {
                display: block !important;
                margin-top: 0;
            }

            .result-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .btn-print,
            .btn-download {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h1>MyResultChecker</h1>
            <p>Secure Online Result Portal</p>
        </div>

        <div class="checker-card">
            <form id="resultForm">
                <div class="form-group">
                    <label><i class="fas fa-school"></i> Select School</label>
                    <select id="school_code" required>
                        <option value="">-- Select School --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Admission Number</label>
                    <input type="text" id="admission_number" placeholder="e.g., 2024/001" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Academic Year</label>
                    <select id="session_year" required>
                        <option value="">-- Select Year --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-chalkboard"></i> Term</label>
                    <select id="term" required>
                        <option value="">-- Select Term --</option>
                        <option value="First">First Term</option>
                        <option value="Second">Second Term</option>
                        <option value="Third">Third Term</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-key"></i> Result Checker PIN</label>
                    <div class="pin-input-wrapper">
                        <input type="password" id="pin" placeholder="XXXX-XXXX-XXXX" maxlength="14" autocomplete="off" required>
                        <button type="button" class="toggle-pin" onclick="togglePinVisibility()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-check" id="submitBtn">
                    <i class="fas fa-search"></i> Check Result
                </button>
            </form>

            <div class="loading" id="loading">
                <div class="loading-spinner"></div>
                <p>Processing your request... Please wait</p>
            </div>

            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="errorText"></span>
            </div>

            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <p>
                    <strong>How to get your PIN?</strong><br>
                    Result Checker PINs are available at your child's school office.
                    Each PIN can be used up to <strong>3 times</strong>.
                </p>
            </div>
        </div>

        <div class="features">
            <div class="feature">
                <i class="fas fa-lock"></i>
                <span>Secure Access</span>
            </div>
            <div class="feature">
                <i class="fas fa-print"></i>
                <span>Printable Result</span>
            </div>
            <div class="feature">
                <i class="fas fa-download"></i>
                <span>Download PDF</span>
            </div>
        </div>

        <div class="result-container" id="resultContainer"></div>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> MyResultChecker.com - All rights reserved.</p>
            <p><a href="#">Privacy Policy</a> | <a href="#">Terms of Use</a> | <a href="#">Contact Support</a></p>
        </footer>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <script>
        let schoolsList = [];

        function togglePinVisibility() {
            const pinInput = document.getElementById('pin');
            const icon = document.querySelector('.toggle-pin i');

            if (pinInput.type === 'password') {
                pinInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pinInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        async function loadSchools() {
            const schoolSelect = document.getElementById('school_code');
            schoolSelect.innerHTML = '<option value="">-- Loading schools... --</option>';

            try {
                const response = await fetch('api/get_schools.php');
                const data = await response.json();

                if (data.success && data.schools && data.schools.length > 0) {
                    schoolsList = data.schools;
                    schoolSelect.innerHTML = '<option value="">-- Select School --</option>';

                    schoolsList.forEach(school => {
                        const option = document.createElement('option');
                        option.value = school.school_code;
                        option.textContent = school.school_name;
                        // Store classes as data attribute
                        option.dataset.classes = JSON.stringify(school.classes || []);
                        schoolSelect.appendChild(option);
                    });
                } else {
                    schoolSelect.innerHTML = '<option value="">-- No schools available --</option>';
                }
            } catch (error) {
                console.error('Error loading schools:', error);
                schoolSelect.innerHTML = '<option value="">-- Error loading schools --</option>';
            }
        }

        function loadSessionYears() {
            const sessionSelect = document.getElementById('session_year');
            const currentYear = new Date().getFullYear();
            const years = [];

            for (let i = 0; i < 5; i++) {
                const startYear = currentYear - i;
                const endYear = startYear + 1;
                years.push(`${startYear}/${endYear}`);
            }

            sessionSelect.innerHTML = '<option value="">-- Select Year --</option>';
            years.forEach(year => {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                sessionSelect.appendChild(option);
            });
        }

        function formatPinInput(input) {
            let value = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

            if (value.length > 4) {
                value = value.slice(0, 4) + '-' + value.slice(4);
            }
            if (value.length > 9) {
                value = value.slice(0, 9) + '-' + value.slice(9);
            }
            if (value.length > 14) {
                value = value.slice(0, 14);
            }

            input.value = value;
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            errorText.textContent = message;
            errorDiv.style.display = 'flex';

            setTimeout(() => {
                errorDiv.style.display = 'none';
            }, 5000);
        }

        function hideError() {
            document.getElementById('errorMessage').style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getGradeClass(grade) {
            if (!grade) return '';
            const gradeMap = {
                'A': 'grade-A',
                'B': 'grade-B',
                'C': 'grade-C',
                'D': 'grade-D',
                'E': 'grade-E',
                'F': 'grade-F'
            };
            return gradeMap[grade.toUpperCase()] || '';
        }

        function renderResult(data) {
            const container = document.getElementById('resultContainer');

            let scoresHtml = '';
            let totalScored = 0;
            let totalObtainable = 0;

            if (data.result.scores && data.result.scores.length > 0) {
                data.result.scores.forEach(score => {
                    const ca1 = score.ca1 || 0;
                    const ca2 = score.ca2 || 0;
                    const exam = score.exam || 0;
                    const subjectTotal = ca1 + ca2 + exam;
                    const maxCa1 = score.max_ca1 || 20;
                    const maxCa2 = score.max_ca2 || 20;
                    const maxExam = score.max_exam || 60;
                    const subjectMax = maxCa1 + maxCa2 + maxExam;

                    totalScored += subjectTotal;
                    totalObtainable += subjectMax;

                    scoresHtml += `
                        <tr>
                            <td><strong>${escapeHtml(score.subject_name)}</strong></td>
                            <td>${ca1}</td>
                            <td>${ca2}</td>
                            <td>${exam}</td>
                            <td><strong>${subjectTotal}</strong></td>
                            <td>${subjectMax}</td>
                            <td class="${getGradeClass(score.grade)}">${score.grade || '-'}</td>
                        </tr>
                    `;
                });
            }

            let affectiveHtml = '';
            if (data.result.affective_traits) {
                const traits = data.result.affective_traits;
                const traitLabels = {
                    punctuality: 'Punctuality',
                    attendance: 'Attendance',
                    politeness: 'Politeness',
                    honesty: 'Honesty',
                    neatness: 'Neatness',
                    reliability: 'Reliability',
                    relationship: 'Relationship',
                    self_control: 'Self Control'
                };

                for (const [key, value] of Object.entries(traits)) {
                    if (value && traitLabels[key]) {
                        affectiveHtml += `<div class="info-item"><span class="info-label">${traitLabels[key]}</span><span class="info-value">${escapeHtml(value)}</span></div>`;
                    }
                }
            }

            let psychomotorHtml = '';
            if (data.result.psychomotor_skills) {
                const skills = data.result.psychomotor_skills;
                const skillLabels = {
                    handwriting: 'Handwriting',
                    verbal_fluency: 'Verbal Fluency',
                    sports: 'Sports',
                    handling_tools: 'Handling Tools',
                    drawing_painting: 'Drawing/Painting',
                    musical_skills: 'Musical Skills'
                };

                for (const [key, value] of Object.entries(skills)) {
                    if (value && skillLabels[key]) {
                        psychomotorHtml += `<div class="info-item"><span class="info-label">${skillLabels[key]}</span><span class="info-value">${escapeHtml(value)}</span></div>`;
                    }
                }
            }

            const percentage = data.result.average || ((totalScored / totalObtainable) * 100).toFixed(2);

            const resultHtml = `
                <div class="result-card" id="resultCard">
                    <div class="result-header">
                        <div class="school-logo">
                            ${data.school.logo ? `<img src="${data.school.logo}" alt="School Logo">` : '<i class="fas fa-school"></i>'}
                        </div>
                        <div class="school-name">${escapeHtml(data.school.name)}</div>
                        <div style="font-size: 0.8rem; opacity: 0.9;">${escapeHtml(data.school.address || '')}</div>
                        <div class="result-title">ACADEMIC RESULT REPORT</div>
                    </div>
                    
                    <div class="student-info">
                        <div class="info-item">
                            <div class="info-label">Student Name</div>
                            <div class="info-value">${escapeHtml(data.student.name)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Admission No.</div>
                            <div class="info-value">${escapeHtml(data.student.admission_number)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Class</div>
                            <div class="info-value">${escapeHtml(data.student.class)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Session / Term</div>
                            <div class="info-value">${escapeHtml(data.student.session)} | ${escapeHtml(data.student.term)} Term</div>
                        </div>
                    </div>
                    
                    <div class="scores-section">
                        <h3><i class="fas fa-chart-line"></i> Academic Performance</h3>
                        <div style="overflow-x: auto;">
                            <table class="scores-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>CA 1</th>
                                        <th>CA 2</th>
                                        <th>Exam</th>
                                        <th>Total</th>
                                        <th>Max</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${scoresHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-label">Total Marks</div>
                            <div class="summary-value">${totalScored} / ${totalObtainable}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Percentage</div>
                            <div class="summary-value">${percentage}%</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Grade</div>
                            <div class="summary-value ${getGradeClass(data.result.grade)}">${data.result.grade || '-'}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Class Position</div>
                            <div class="summary-value">${data.result.class_position || 'N/A'}${data.result.class_total ? '/' + data.result.class_total : ''}</div>
                        </div>
                    </div>
                    
                    ${data.result.promoted_to ? `
                    <div style="text-align: center; margin: -10px 25px 15px; padding: 8px; background: #e8f4fd; border-radius: 20px;">
                        <strong>Promoted to:</strong> ${escapeHtml(data.result.promoted_to)}
                    </div>
                    ` : ''}
                    
                    ${affectiveHtml ? `
                    <div style="padding: 0 25px 15px;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 10px; color: var(--primary);"><i class="fas fa-heart"></i> Affective Traits</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                            ${affectiveHtml}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${psychomotorHtml ? `
                    <div style="padding: 0 25px 15px;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 10px; color: var(--primary);"><i class="fas fa-running"></i> Psychomotor Skills</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                            ${psychomotorHtml}
                        </div>
                    </div>
                    ` : ''}
                    
                    <div class="comment-section">
                        <div class="comment-box">
                            <div class="comment-label"><i class="fas fa-chalkboard-teacher"></i> Teacher's Comment</div>
                            <div class="comment-text">${escapeHtml(data.result.teachers_comment) || 'No comment provided.'}</div>
                        </div>
                        <div class="comment-box">
                            <div class="comment-label"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                            <div class="comment-text">${escapeHtml(data.result.principals_comment) || 'No comment provided.'}</div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn-print" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Result
                        </button>
                        <button class="btn-download" id="downloadPDFBtn">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                    </div>
                    
                    <div class="pin-remaining">
                        <i class="fas fa-info-circle"></i> This PIN has ${data.pin_remaining_uses} remaining usage(s)
                    </div>
                </div>
            `;

            container.innerHTML = resultHtml;
            container.style.display = 'block';
            container.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

            const downloadBtn = document.getElementById('downloadPDFBtn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', function() {
                    const element = document.getElementById('resultCard');
                    const opt = {
                        margin: [0.5, 0.5, 0.5, 0.5],
                        filename: `${data.student.name}_${data.student.session}_${data.student.term}_Result.pdf`,
                        image: {
                            type: 'jpeg',
                            quality: 0.98
                        },
                        html2canvas: {
                            scale: 2,
                            useCORS: true,
                            logging: false
                        },
                        jsPDF: {
                            unit: 'in',
                            format: 'a4',
                            orientation: 'portrait'
                        }
                    };
                    html2pdf().set(opt).from(element).save();
                });
            }
        }

        document.getElementById('resultForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const school_code = document.getElementById('school_code').value;
            const admission_number = document.getElementById('admission_number').value.trim();
            const session_year = document.getElementById('session_year').value;
            const term = document.getElementById('term').value;
            const pin = document.getElementById('pin').value.trim();

            if (!school_code) {
                showError('Please select a school');
                return;
            }
            if (!admission_number) {
                showError('Please enter the admission number');
                return;
            }
            if (!session_year) {
                showError('Please select the academic year');
                return;
            }
            if (!term) {
                showError('Please select the term');
                return;
            }
            if (!pin) {
                showError('Please enter the Result Checker PIN');
                return;
            }

            const pinPattern = /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i;
            if (!pinPattern.test(pin)) {
                showError('Invalid PIN format. PIN should be in format: XXXX-XXXX-XXXX');
                return;
            }

            hideError();

            const loading = document.getElementById('loading');
            const submitBtn = document.getElementById('submitBtn');
            const resultContainer = document.getElementById('resultContainer');

            loading.style.display = 'block';
            submitBtn.disabled = true;
            resultContainer.style.display = 'none';
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

            try {
                const response = await fetch('api/check_result.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        school_code: school_code,
                        admission_number: admission_number,
                        session_year: session_year,
                        term: term,
                        pin: pin.toUpperCase()
                    })
                });

                const data = await response.json();

                if (data.success) {
                    renderResult(data);
                } else {
                    showError(data.error || 'Failed to retrieve result. Please check your details and try again.');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('Network error. Please check your internet connection and try again.');
            } finally {
                loading.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-search"></i> Check Result';
            }
        });

        const pinInput = document.getElementById('pin');
        pinInput.addEventListener('input', function(e) {
            formatPinInput(this);
        });

        pinInput.addEventListener('keyup', function(e) {
            this.value = this.value.toUpperCase();
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadSchools();
            loadSessionYears();

            const checkerCard = document.querySelector('.checker-card');
            checkerCard.style.opacity = '0';
            checkerCard.style.transform = 'translateY(20px)';
            setTimeout(() => {
                checkerCard.style.transition = 'all 0.5s ease';
                checkerCard.style.opacity = '1';
                checkerCard.style.transform = 'translateY(0)';
            }, 100);
        });

        pinInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('resultForm').dispatchEvent(new Event('submit'));
            }
        });
    </script>
</body>

</html>