<?php
// Exam sections (e.g. "General Aptitude", "Computer Science") and reading passages.
// A question belongs to at most one section via questions.section_id.
// Questions without a section are shown under the subject name.
// A question group is a passage plus the questions that point at it (questions.passage_id);
// the exam shows the passage on the left and the question on the right.

// Creates the sections/passages tables and the questions columns if they are missing.
function ensure_sections_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS sections (
            section_id   INT AUTO_INCREMENT PRIMARY KEY,
            section_name VARCHAR(100) NOT NULL UNIQUE,
            sort_order   INT NOT NULL DEFAULT 0
        )
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS passages (
            passage_id        INT AUTO_INCREMENT PRIMARY KEY,
            title             VARCHAR(150) NOT NULL,
            passage_text      MEDIUMTEXT NULL,
            passage_image_url VARCHAR(500) NULL
        )
    ");
    foreach (['section_id', 'passage_id'] as $column) {
        $col = $conn->query("SHOW COLUMNS FROM questions LIKE '$column'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE questions ADD COLUMN $column INT NULL DEFAULT NULL, ADD INDEX idx_questions_$column ($column)");
        }
    }
    // A passage (question group) belongs to one test (subject + set) and one section
    foreach (['subject_id', 'set_no', 'section_id'] as $column) {
        $col = $conn->query("SHOW COLUMNS FROM passages LIKE '$column'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE passages ADD COLUMN $column INT NULL DEFAULT NULL");
        }
    }
}

// All sections in exam order, with how many questions each has.
function fetch_sections(mysqli $conn): array
{
    $sections = [];
    $res = $conn->query("
        SELECT s.section_id, s.section_name, s.sort_order, COUNT(q.id) AS question_count
        FROM sections s
        LEFT JOIN questions q ON q.section_id = s.section_id
        GROUP BY s.section_id, s.section_name, s.sort_order
        ORDER BY s.sort_order, s.section_name
    ");
    if ($res) { while ($row = $res->fetch_assoc()) { $sections[] = $row; } }
    return $sections;
}

// All passages, newest first, with how many questions use each.
function fetch_passages(mysqli $conn): array
{
    $passages = [];
    $res = $conn->query("
        SELECT p.passage_id, p.title, p.passage_text, p.passage_image_url, COUNT(q.id) AS question_count
        FROM passages p
        LEFT JOIN questions q ON q.passage_id = p.passage_id
        GROUP BY p.passage_id, p.title, p.passage_text, p.passage_image_url
        ORDER BY p.passage_id DESC
    ");
    if ($res) { while ($row = $res->fetch_assoc()) { $passages[] = $row; } }
    return $passages;
}
