<?php
function sendResponse($data, $status_code = 200)
{
    http_response_code($status_code);
    echo json_encode($data);
    exit();
}

function sendError($message, $status_code = 400)
{
    http_response_code($status_code);
    echo json_encode(["success" => false, "error" => $message]);
    exit();
}

function sendSuccess($data = null, $message = "Success")
{
    http_response_code(200);
    echo json_encode(["success" => true, "message" => $message, "data" => $data]);
    exit();
}
