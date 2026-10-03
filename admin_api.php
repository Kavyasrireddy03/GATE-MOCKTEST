<?php
// admin_api.php

// ----------------------------------------------------
// 1. CONFIGURATION AND SETUP
// ----------------------------------------------------

// Enable error reporting for debugging (Remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php'; // Contains your $conn connection object
header('Content-Type: application/json');

// ----------------------------------------------------
// 2. INPUT HANDLING (CRITICAL FIX)
// ----------------------------------------------------

$action = $_GET['action'] ?? '';
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read the raw JSON data sent by the JavaScript fetch() request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // If JSON decoding fails, or is empty, flag it.
    if ($data === null) {
        $response = ['success' => false, 'message' => 'Error: Invalid JSON payload received.'];
        echo json_encode($response);
        $conn->close();
        exit;
    }
} else {
    // Handle GET requests (like getSubjects)
    $data = $_GET;
}

// Override action if it's present in the decoded POST data
$action = $data['action'] ?? $action; 

$response = ['success' => false, 'message' => 'Invalid action specified.'];

// ----------------------------------------------------
// 3. ACTION SWITCH
// ----------------------------------------------------

switch ($action) {
    
    // ----------------------
    // Subject Management
    // ----------------------
    case 'addSubject':
        if (isset($data['subject_name']) && !empty($data['subject_name'])) {
            $subject_name = $conn->real_escape_string($data['subject_name']);
            
            // Use prepared statements for security
            $stmt = $conn->prepare("INSERT INTO subjects (subject_name) VALUES (?)");
            
            if ($stmt === false) {
                 $response = ['success' => false, 'message' => 'SQL Prepare Failed: ' . $conn->error];
                 break;
            }
            
            $stmt->bind_param("s", $subject_name);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Subject added successfully!', 'insert_id' => $stmt->insert_id];
            } else {
                // Check for duplicate key error (if UNIQUE constraint fails)
                if ($conn->errno == 1062) {
                    $response = ['success' => false, 'message' => 'Error: Subject name already exists (Duplicate Entry).'];
                } else {
                    $response = ['success' => false, 'message' => 'Database Error: ' . $stmt->error];
                }
            }
            $stmt->close();
        } else {
            $response['message'] = 'Subject name is required.';
        }
        break;

    case 'getSubjects':
        $result = $conn->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
        $subjects = [];
        if ($result) {
             while($row = $result->fetch_assoc()) {
                 $subjects[] = $row;
             }
             $response = ['success' => true, 'subjects' => $subjects];
        } else {
             $response = ['success' => false, 'message' => 'Error retrieving subjects: ' . $conn->error];
        }
        break;

    // ----------------------
    // Question Management
    // ----------------------
    case 'addQuestion':
        $subject_id = $data['subject_id'] ?? null;
        $q_type = $data['q_type'] ?? null;
        $q_text_url = $data['q_text_url'] ?? null;
        $options_json = json_encode($data['options'] ?? []);
        $correct_answer_json = json_encode($data['correct_answers'] ?? []);
        $explanation = $data['explanation'] ?? null;
        $marks = $data['marks'] ?? 1.0;
        $range_min = ($q_type === 'NAT') ? ($data['range_min'] ?? null) : null;
        $range_max = ($q_type === 'NAT') ? ($data['range_max'] ?? null) : null;
        
        // Basic validation
        if (empty($subject_id) || empty($q_type) || empty($q_text_url)) {
            $response['message'] = 'Missing required question fields (Subject, Type, or Text/URL).';
            break;
        }

        $sql = "INSERT INTO questions (subject_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks, range_min, range_max) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
             $response = ['success' => false, 'message' => 'SQL Prepare Failed: ' . $conn->error];
             break;
        }

        // 'issssdsdd' means: integer, string, string, string, string, string, double, double, double
        $stmt->bind_param("issssdsdd", 
            $subject_id, 
            $q_type, 
            $q_text_url, 
            $options_json, 
            $correct_answer_json, 
            $explanation, 
            $marks, 
            $range_min, 
            $range_max
        );
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Question added successfully!', 'insert_id' => $stmt->insert_id];
        } else {
            $response = ['success' => false, 'message' => 'Database Error: ' . $stmt->error];
        }
        $stmt->close();
        break;
}

// ----------------------------------------------------
// 4. OUTPUT AND CLEANUP
// ----------------------------------------------------

echo json_encode($response);
$conn->close();
?>