<?php
// modules/shared/notifications/create_notification.php

// Function to create notifications
function createNotification($user_id, $title, $message, $type = 'system', $related_id = null)
{
    require_once __DIR__ . '/../../includes/config.php';

    // Get database connection
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }

    // Prepare SQL statement
    $sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        $conn->close();
        return false;
    }

    // Bind parameters and execute
    $stmt->bind_param("isssi", $user_id, $title, $message, $type, $related_id);
    $result = $stmt->execute();

    $stmt->close();
    $conn->close();

    return $result;
}

// Example usage functions
function notifyAssignmentPublished($student_id, $assignment_id, $assignment_title, $course_title)
{
    $title = "New Assignment: $assignment_title";
    $message = "A new assignment '$assignment_title' has been published for $course_title. Check your assignments page.";
    return createNotification($student_id, $title, $message, 'assignment', $assignment_id);
}

function notifyGradePosted($student_id, $assignment_id, $assignment_title, $grade)
{
    $title = "Grade Posted: $assignment_title";
    $message = "Your grade for '$assignment_title' has been posted. You received $grade%. Check your gradebook.";
    return createNotification($student_id, $title, $message, 'grade', $assignment_id);
}

function notifyAnnouncement($student_id, $announcement_id, $announcement_title, $course_title)
{
    $title = "New Announcement: $announcement_title";
    $message = "A new announcement has been posted for $course_title: '$announcement_title'";
    return createNotification($student_id, $title, $message, 'announcement', $announcement_id);
}

function notifyPaymentReceived($student_id, $transaction_id, $amount)
{
    $title = "Payment Received";
    $message = "Your payment of ₦" . number_format($amount, 2) . " has been received and processed successfully.";
    return createNotification($student_id, $title, $message, 'system', $transaction_id);
}

function notifyPaymentOverdue($student_id, $class_id, $amount)
{
    $title = "Payment Overdue";
    $message = "You have an overdue payment of ₦" . number_format($amount, 2) . ". Please make payment immediately to avoid suspension.";
    return createNotification($student_id, $title, $message, 'system', $class_id);
}

function notifyAccountSuspended($student_id, $reason)
{
    $title = "Account Suspended";
    $message = "Your account has been suspended due to: $reason. Please contact support.";
    return createNotification($student_id, $title, $message, 'system', null);
}

function notifyClassStartingSoon($student_id, $class_id, $class_title, $start_time)
{
    $title = "Class Starting Soon";
    $message = "Your class '$class_title' starts in 15 minutes at $start_time. Please join on time.";
    return createNotification($student_id, $title, $message, 'system', $class_id);
}
