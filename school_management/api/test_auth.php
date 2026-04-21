<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$request_method = $_SERVER["REQUEST_METHOD"];
$path = isset($_GET['action']) ? $_GET['action'] : '';

// Test credentials
$test_users = [
    'admin@impactschool.com' => [
        'password' => 'admin123',
        'id' => 1,
        'email' => 'admin@impactschool.com',
        'user_type' => 'admin',
        'first_name' => 'John',
        'last_name' => 'Admin',
        'phone' => '+2348012345678',
        'is_active' => 1
    ],
    'teacher@impactschool.com' => [
        'password' => 'teacher123',
        'id' => 2,
        'email' => 'teacher@impactschool.com',
        'user_type' => 'staff',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'phone' => '+2348023456789',
        'is_active' => 1
    ],
    'parent@example.com' => [
        'password' => 'parent123',
        'id' => 5,
        'email' => 'parent@example.com',
        'user_type' => 'parent',
        'first_name' => 'David',
        'last_name' => 'Brown',
        'phone' => '+2348056789012',
        'is_active' => 1
    ]
];

if ($request_method == 'POST' && $path == 'login') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        echo json_encode(["success" => false, "message" => "Email and password required"]);
        exit();
    }

    $email = $data['email'];
    $password = $data['password'];

    if (isset($test_users[$email]) && $test_users[$email]['password'] === $password) {
        $user = $test_users[$email];

        // Add extra data based on user type
        $extra_data = [];
        if ($user['user_type'] == 'staff') {
            $extra_data = [
                'staff_number' => 'TCH001',
                'qualification' => 'B.Ed Mathematics',
                'specialization' => 'Mathematics',
                'class_assigned_id' => 1,
                'school' => [
                    'id' => 3,
                    'school_name' => 'Impact Diagnostic School',
                    'school_code' => 'IMPACT001'
                ]
            ];
        } elseif ($user['user_type'] == 'parent') {
            $extra_data = [
                'occupation' => 'Software Engineer',
                'address' => '123 Parent Street, Lagos',
                'relationship' => 'father',
                'children' => [
                    [
                        'id' => 1,
                        'admission_number' => 'STU2024001',
                        'first_name' => 'Alice',
                        'last_name' => 'Brown',
                        'class_id' => 1,
                        'class_name' => 'Grade 1'
                    ],
                    [
                        'id' => 2,
                        'admission_number' => 'STU2024002',
                        'first_name' => 'Bob',
                        'last_name' => 'Brown',
                        'class_id' => 1,
                        'class_name' => 'Grade 1'
                    ]
                ]
            ];
        } elseif ($user['user_type'] == 'admin') {
            $extra_data = [
                'school' => [
                    'id' => 3,
                    'school_name' => 'Impact Diagnostic School',
                    'school_code' => 'IMPACT001',
                    'subscription_status' => 'active'
                ]
            ];
        }

        $token_payload = json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $user['user_type'],
            'expires' => time() + (7 * 24 * 60 * 60)
        ]);
        $token = base64_encode($token_payload);

        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "data" => [
                "token" => $token,
                "user" => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'phone' => $user['phone'],
                    'user_type' => $user['user_type'],
                    'extra_data' => $extra_data
                ]
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    }
    exit();
}
