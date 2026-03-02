<?php
session_start();
unset($_SESSION['student_id']);
unset($_SESSION['student_name']);
header('Location: login.php');
exit;
