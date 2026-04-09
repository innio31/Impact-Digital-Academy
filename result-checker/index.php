<?php
// index.php - Complete result checker with full report card display matching generate_report_card.php
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
            --primary: #1a237e;
            --primary-dark: #0d1b5e;
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            text-align: center;
            padding: 30px 20px;
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 0;
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
        }

        .info-box {
            background: #e8f4fd;
            border-radius: var(--radius-md);
            padding: 15px 20px;
            margin-top: 20px;
            display: flex;
            gap: 12px;
            border-left: 4px solid var(--secondary);
        }

        .features {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .feature {
            text-align: center;
            padding: 15px 25px;
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

        footer {
            text-align: center;
            margin-top: auto;
            padding: 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }

        .result-container {
            display: none;
            margin-top: 30px;
        }

        /* Report Card Styles - Matching generate_report_card.php */
        .report-card {
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

        .report-header {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .school-logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            overflow: hidden;
        }

        .school-logo i {
            font-size: 40px;
            color: var(--primary);
        }

        .school-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .report-title {
            font-size: 1rem;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.3);
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .student-info-table {
            width: 100%;
            border-collapse: collapse;
            background: #f8f9fa;
        }

        .student-info-table td {
            padding: 12px 15px;
            border: 1px solid #dee2e6;
        }

        .info-label {
            font-weight: 600;
            background: #e9ecef;
            width: 18%;
        }

        .scores-section {
            padding: 20px;
            overflow-x: auto;
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .scores-table th,
        .scores-table td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            text-align: left;
        }

        .scores-table th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-align: center;
        }

        .scores-table td {
            text-align: center;
        }

        .subject-col {
            text-align: left !important;
            font-weight: 600;
        }

        .traits-row {
            display: flex;
            gap: 20px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .traits-column {
            flex: 1;
            min-width: 250px;
        }

        .traits-table {
            width: 100%;
            border-collapse: collapse;
        }

        .traits-table td {
            padding: 8px 10px;
            border: 1px solid #dee2e6;
        }

        .section-header {
            background: var(--primary);
            color: white;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 0;
        }

        .rating-key {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 0.75rem;
        }

        .rating-key td {
            padding: 6px;
            text-align: center;
            border: 1px solid #dee2e6;
        }

        .comments-section {
            padding: 20px;
        }

        .comment-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: var(--radius-md);
            margin-bottom: 15px;
            border-left: 4px solid var(--secondary);
        }

        .comment-label {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .footer-section {
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            font-size: 0.8rem;
        }

        .action-buttons {
            padding: 20px;
            display: flex;
            gap: 15px;
            border-top: 1px solid #dee2e6;
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
            padding: 15px;
            text-align: center;
            background: #fff3cd;
            color: #856404;
            font-size: 0.85rem;
            border-top: 1px solid #ffeeba;
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
            color: #2ecc71;
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

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .checker-card {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .traits-row {
                flex-direction: column;
            }

            .student-info-table td {
                padding: 8px 10px;
            }

            .info-label {
                width: 35%;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        @media print {
            body {
                background: white;
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

            .report-card {
                box-shadow: none;
                border: 1px solid #ddd;
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
                <div class="form-row">
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
                </div>

                <div class="form-row">
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
                <p><strong>How to get your PIN?</strong><br>Result Checker PINs are available at your child's school office. Each PIN can be used up to <strong>3 times</strong>.</p>
            </div>
        </div>

        <div class="features">
            <div class="feature"><i class="fas fa-lock"></i><span>Secure Access</span></div>
            <div class="feature"><i class="fas fa-print"></i><span>Printable Result</span></div>
            <div class="feature"><i class="fas fa-download"></i><span>Download PDF</span></div>
        </div>

        <div class="result-container" id="resultContainer"></div>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> MyResultChecker.com - All rights reserved.</p>
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
            sessionSelect.innerHTML = '<option value="">-- Select Year --</option>';
            for (let i = 0; i < 5; i++) {
                const startYear = currentYear - i;
                const endYear = startYear + 1;
                const year = `${startYear}/${endYear}`;
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                sessionSelect.appendChild(option);
            }
        }

        function formatPinInput(input) {
            let value = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();


            if (value.length > 4 && value.length <= 8) {
                value = value.slice(0, 4) + '-' + value.slice(4);
            } else if (value.length > 8) {
                value = value.slice(0, 4) + '-' + value.slice(4, 8) + '-' + value.slice(8, 12);
            }

            input.value = value;
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            errorText.textContent = message;
            errorDiv.style.display = 'flex';
            setTimeout(() => errorDiv.style.display = 'none', 5000);
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

        function calculateAge(dob) {
            if (!dob) return 'N/A';
            const birthDate = new Date(dob);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const m = today.getMonth() - birthDate.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
            return age + ' yrs';
        }

        function getOrdinal(n) {
            if (!n) return 'N/A';
            const s = ['th', 'st', 'nd', 'rd'];
            const v = n % 100;
            return n + (s[(v - 20) % 10] || s[v] || s[0]);
        }

        function renderFullReportCard(data) {
            const container = document.getElementById('resultContainer');
            const result = data.result;
            const student = data.student;
            const school = data.school;

            // Build scores table
            let scoresHtml = '';
            let totalScored = 0;
            let totalMax = 0;
            let componentHeaders = '';
            let componentHeadersSet = false;
            let scoreTypes = [];

            if (result.scores && result.scores.length > 0) {
                result.scores.forEach((score, idx) => {
                    let subjectTotal = 0;
                    let componentsHtml = '';
                    let componentNames = [];

                    // Handle different score data formats
                    if (score.components) {
                        // Format from export
                        for (const [compName, compValue] of Object.entries(score.components)) {
                            componentsHtml += `<td>${compValue || 0}</td>`;
                            componentNames.push(compName);
                            subjectTotal += parseFloat(compValue) || 0;
                        }
                    } else if (score.score_data && typeof score.score_data === 'object') {
                        // Format from database
                        for (const [compName, compValue] of Object.entries(score.score_data)) {
                            if (compName !== 'subject_name' && compName !== 'subject_id' && compName !== 'raw_values') {
                                componentsHtml += `<td>${compValue || 0}</td>`;
                                componentNames.push(compName);
                                subjectTotal += parseFloat(compValue) || 0;
                            }
                        }
                    } else if (score.ca1 !== undefined) {
                        // Simple format with ca1, ca2, exam
                        const ca1 = score.ca1 || 0;
                        const ca2 = score.ca2 || 0;
                        const exam = score.exam || 0;
                        componentsHtml = `<td>${ca1}</td><td>${ca2}</td><td>${exam}</td>`;
                        componentNames = ['CA1', 'CA2', 'Exam'];
                        subjectTotal = ca1 + ca2 + exam;
                    }

                    // Use provided values if available
                    subjectTotal = score.total_score || subjectTotal;
                    const maxScore = score.max_score || 100;
                    totalMax += maxScore;
                    totalScored += subjectTotal;

                    const percentage = score.percentage || ((subjectTotal / maxScore) * 100).toFixed(2);

                    // Set component headers from first score
                    if (!componentHeadersSet && componentNames.length > 0) {
                        componentNames.forEach(name => {
                            componentHeaders += `<th>${escapeHtml(name)}</th>`;
                        });
                        componentHeadersSet = true;
                        scoreTypes = componentNames;
                    }

                    scoresHtml += `
                <tr>
                    <td class="subject-col">${(idx + 1)}. ${escapeHtml(score.subject_name)}</td>
                    ${componentsHtml}
                    <td><strong>${subjectTotal}</strong></td>
                    <td>${percentage}%</td>
                    <td class="${getGradeClass(score.grade)}"><strong>${score.grade || '-'}</strong></td>
                    <td>${score.remark || getPerformanceRemark(percentage)}</td>
                </tr>
            `;
                });
            }

            // Default component headers if not set
            if (!componentHeaders) {
                componentHeaders = '<th>CA1</th><th>CA2</th><th>Exam</th>';
            }

            // If no scores found, show message
            if (!scoresHtml) {
                scoresHtml = '<tr><td colspan="10" style="text-align:center;">No subject scores available</td></tr>';
            }

            // Get overall percentage
            const overallPercentage = result.average || ((totalScored / totalMax) * 100).toFixed(2);
            const overallGrade = result.grade || calculateGrade(overallPercentage);

            // Affective traits
            let affectiveHtml = '';
            if (result.affective_traits) {
                const traits = {
                    'punctuality': 'Punctuality',
                    'attendance': 'Attendance',
                    'politeness': 'Politeness',
                    'honesty': 'Honesty',
                    'neatness': 'Neatness',
                    'reliability': 'Reliability',
                    'relationship': 'Relationship with others',
                    'self_control': 'Self Control'
                };
                for (const [key, label] of Object.entries(traits)) {
                    const value = result.affective_traits[key];
                    if (value) {
                        affectiveHtml += `<tr><td>${label}</td><td><strong>${escapeHtml(value)}</strong></td></tr>`;
                    }
                }
            }

            // Psychomotor skills
            let psychomotorHtml = '';
            if (result.psychomotor_skills) {
                const skills = {
                    'handwriting': 'Handwriting',
                    'verbal_fluency': 'Verbal Fluency',
                    'sports': 'Sports',
                    'handling_tools': 'Handling Tools',
                    'drawing_painting': 'Drawing & Painting',
                    'musical_skills': 'Musical Skills'
                };
                for (const [key, label] of Object.entries(skills)) {
                    const value = result.psychomotor_skills[key];
                    if (value) {
                        psychomotorHtml += `<tr><td>${label}</td><td><strong>${escapeHtml(value)}</strong></td></tr>`;
                    }
                }
            }

            const reportHtml = `
        <div class="report-card" id="reportCard">
            <div class="report-header">
                <div class="school-logo">
                    ${school.logo ? `<img src="${school.logo}" alt="Logo">` : '<i class="fas fa-school"></i>'}
                </div>
                <div class="school-name">${escapeHtml(school.name)}</div>
                <div style="font-size: 0.8rem;">${escapeHtml(school.address || '')}</div>
                <div class="report-title">${result.term} TERM REPORT - ${result.session_year}</div>
            </div>
            
            <table class="student-info-table">
                <tr>
                    <td class="info-label">Student Name</td><td colspan="3"><strong>${escapeHtml(student.name)}</strong></td>
                    <td class="info-label">Admission No.</td><td>${escapeHtml(student.admission_number)}</td>
                </tr>
                <tr>
                    <td class="info-label">Class</td><td>${escapeHtml(student.class)}</td>
                    <td class="info-label">Gender</td><td>${student.gender || 'N/A'}</td>
                    <td class="info-label">Age</td><td>${calculateAge(student.date_of_birth)}</td>
                </tr>
                <tr>
                    <td class="info-label">Position</td><td>${getOrdinal(result.class_position)}</td>
                    <td class="info-label">Class Size</td><td>${result.class_total || 'N/A'}</td>
                    <td class="info-label">Days Present</td><td>${result.days_present || 0}</td>
                </tr>
                <tr>
                    <td class="info-label">Total Score</td><td>${totalScored} / ${totalMax}</td>
                    <td class="info-label">Percentage</td><td>${overallPercentage}%</td>
                    <td class="info-label">Grade</td><td class="${getGradeClass(overallGrade)}"><strong>${overallGrade}</strong></td>
                    ${result.promoted_to ? `<td class="info-label">Promoted To</td><td>${escapeHtml(result.promoted_to)}</td>` : ''}
                </tr>
            </table>
            
            <div class="scores-section">
                <table class="scores-table">
                    <thead>
                        <tr>
                            <th>SUBJECT</th>
                            ${componentHeaders}
                            <th>Total</th>
                            <th>%</th>
                            <th>Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${scoresHtml}
                    </tbody>
                </table>
            </div>
            
            <div class="traits-row">
                <div class="traits-column">
                    <div class="section-header">AFFECTIVE TRAITS</div>
                    <table class="traits-table">
                        ${affectiveHtml || '<tr><td colspan="2">No data available</td></tr>'}
                    </table>
                </div>
                <div class="traits-column">
                    <div class="section-header">PSYCHOMOTOR SKILLS</div>
                    <table class="traits-table">
                        ${psychomotorHtml || '<tr><td colspan="2">No data available</td></tr>'}
                    </table>
                </div>
            </div>
            
            <table class="rating-key">
                <tr style="background:#f0f0f0; font-weight:bold;">
                    <td>5: Excellent</td><td>4: Very Good</td><td>3: Good</td><td>2: Pass</td><td>1: Poor</td>
                </tr>
            </table>
            
            <div class="comments-section">
                <div class="comment-box">
                    <div class="comment-label"><i class="fas fa-chalkboard-teacher"></i> Teacher's Comment</div>
                    <div>${escapeHtml(result.teachers_comment) || 'No comment provided.'}</div>
                </div>
                <div class="comment-box">
                    <div class="comment-label"><i class="fas fa-user-tie"></i> Principal's Comment</div>
                    <div>${escapeHtml(result.principals_comment) || 'No comment provided.'}</div>
                </div>
            </div>
            
            <div class="footer-section">
                <strong>Next Term Resumption:</strong> To be announced by the school<br>
                <i>Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</i>
            </div>
            
            <div class="action-buttons">
                <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Report Card</button>
                <button class="btn-download" id="downloadPDFBtn"><i class="fas fa-download"></i> Download PDF</button>
            </div>
            
            <div class="pin-remaining">
                <i class="fas fa-info-circle"></i> This PIN has ${data.pin_remaining_uses} remaining usage(s)
            </div>
        </div>
    `;

            container.innerHTML = reportHtml;
            container.style.display = 'block';
            container.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

            const downloadBtn = document.getElementById('downloadPDFBtn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', function() {
                    const element = document.getElementById('reportCard');
                    html2pdf().set({
                        margin: [0.5, 0.5, 0.5, 0.5],
                        filename: `${student.name}_${result.session_year}_${result.term}_Report.pdf`,
                        image: {
                            type: 'jpeg',
                            quality: 0.98
                        },
                        html2canvas: {
                            scale: 2,
                            useCORS: true
                        },
                        jsPDF: {
                            unit: 'in',
                            format: 'a4',
                            orientation: 'portrait'
                        }
                    }).from(element).save();
                });
            }
        }

        // Add this helper function
        function getPerformanceRemark(percentage) {
            if (percentage >= 70) return 'Excellent';
            if (percentage >= 60) return 'Very good';
            if (percentage >= 50) return 'Good';
            if (percentage >= 40) return 'Pass';
            if (percentage >= 30) return 'Poor';
            return 'Fail';
        }

        function calculateGrade(percentage) {
            if (percentage >= 80) return 'A';
            if (percentage >= 70) return 'B';
            if (percentage >= 60) return 'C';
            if (percentage >= 50) return 'D';
            if (percentage >= 40) return 'E';
            return 'F';
        }

        document.getElementById('resultForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const school_code = document.getElementById('school_code').value;
            const admission_number = document.getElementById('admission_number').value.trim();
            const session_year = document.getElementById('session_year').value;
            const term = document.getElementById('term').value;
            let pin = document.getElementById('pin').value.trim();

            if (!school_code) return showError('Please select a school');
            if (!admission_number) return showError('Please enter the admission number');
            if (!session_year) return showError('Please select the academic year');
            if (!term) return showError('Please select the term');
            if (!pin) return showError('Please enter the Result Checker PIN');

            // Remove dashes and spaces from PIN for validation
            const pinClean = pin.replace(/[-\s]/g, '');

            // Only check if PIN is not empty - don't enforce format
            if (pinClean.length === 0) {
                return showError('Please enter a valid PIN');
            }

            // Optional: Show warning if PIN is too short but still allow
            if (pinClean.length < 4) {
                return showError('PIN is too short. Please enter a valid PIN');
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
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        school_code: school_code,
                        admission_number: admission_number,
                        session_year: session_year,
                        term: term,
                        pin: pinClean // Send the cleaned PIN without dashes
                    })
                });

                const data = await response.json();
                if (data.success) {
                    renderFullReportCard(data);
                } else {
                    showError(data.error || 'Failed to retrieve result.');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('Network error. Please check your connection.');
            } finally {
                loading.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-search"></i> Check Result';
            }
        });

        document.getElementById('pin').addEventListener('input', function(e) {
            formatPinInput(this);
        });
        document.getElementById('pin').addEventListener('keyup', function(e) {
            this.value = this.value.toUpperCase();
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadSchools();
            loadSessionYears();
        });
    </script>
</body>

</html>