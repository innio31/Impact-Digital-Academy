// Add this at the top of ALL your PHP API files (login.php, students/index.php, etc.)
// Place this BEFORE any other code or output

// Allow from any origin (or specify your Netlify domain)
header('Access-Control-Allow-Origin: https://your-netlify-site.netlify.app');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
http_response_code(200);
exit();
}