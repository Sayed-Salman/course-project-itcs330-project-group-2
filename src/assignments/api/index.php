<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../common/db.php';

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true) ?? [];

$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;
$assignmentId = $_GET['assignment_id'] ?? null;
$commentId = $_GET['comment_id'] ?? null;

try {
    if ($method === 'GET') {
        if ($action === 'comments') {
            getCommentsByAssignment($db, $assignmentId);
        } elseif ($id !== null) {
            getAssignmentById($db, $id);
        } else {
            getAllAssignments($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'comment') {
            createComment($db, $data);
        } else {
            createAssignment($db, $data);
        }
    } elseif ($method === 'PUT') {
        updateAssignment($db, $data);
    } elseif ($method === 'DELETE') {
        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        } else {
            deleteAssignment($db, $id);
        }
    } else {
        sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error.'], 500);
} catch (Exception $e) {
    error_log($e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error.'], 500);
}

function getAllAssignments(PDO $db): void
{
    $query = 'SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments';
    $params = [];

    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $query .= ' WHERE title LIKE :search OR description LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $allowedSort = ['title', 'due_date', 'created_at'];
    $sort = $_GET['sort'] ?? 'due_date';
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'due_date';
    }

    $order = strtolower($_GET['order'] ?? 'asc');
    if (!in_array($order, ['asc', 'desc'], true)) {
        $order = 'asc';
    }

    $query .= " ORDER BY {$sort} {$order}";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as &$assignment) {
        $assignment['files'] = json_decode($assignment['files'] ?? '[]', true) ?: [];
    }

    sendResponse(['success' => true, 'data' => $assignments]);
}

function getAssignmentById(PDO $db, $id): void
{
    if (!is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment id.'], 400);
    }

    $stmt = $db->prepare(
        'SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments WHERE id = ?'
    );
    $stmt->execute([(int) $id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    $assignment['files'] = json_decode($assignment['files'] ?? '[]', true) ?: [];
    sendResponse(['success' => true, 'data' => $assignment]);
}

function createAssignment(PDO $db, array $data): void
{
    foreach (['title', 'description', 'due_date'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            sendResponse(['success' => false, 'message' => "Missing {$field}."], 400);
        }
    }

    $title = sanitizeInput((string) $data['title']);
    $description = sanitizeInput((string) $data['description']);
    $dueDate = trim((string) $data['due_date']);

    if (!validateDate($dueDate)) {
        sendResponse(['success' => false, 'message' => 'Invalid due_date format.'], 400);
    }

    $files = normalizeFiles($data['files'] ?? []);
    $stmt = $db->prepare(
        'INSERT INTO assignments (title, description, due_date, files) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$title, $description, $dueDate, json_encode($files)]);

    if ($stmt->rowCount() > 0) {
        $id = (int) $db->lastInsertId();
        sendResponse([
            'success' => true,
            'message' => 'Assignment created successfully.',
            'id' => $id,
            'data' => [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'due_date' => $dueDate,
                'files' => $files,
            ],
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create assignment.'], 500);
}

function updateAssignment(PDO $db, array $data): void
{
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment id.'], 400);
    }

    $id = (int) $data['id'];
    ensureAssignmentExists($db, $id);

    $clauses = [];
    $values = [];

    if (array_key_exists('title', $data)) {
        if (trim((string) $data['title']) === '') {
            sendResponse(['success' => false, 'message' => 'Title cannot be empty.'], 400);
        }
        $clauses[] = 'title = ?';
        $values[] = sanitizeInput((string) $data['title']);
    }

    if (array_key_exists('description', $data)) {
        if (trim((string) $data['description']) === '') {
            sendResponse(['success' => false, 'message' => 'Description cannot be empty.'], 400);
        }
        $clauses[] = 'description = ?';
        $values[] = sanitizeInput((string) $data['description']);
    }

    if (array_key_exists('due_date', $data)) {
        $dueDate = trim((string) $data['due_date']);
        if (!validateDate($dueDate)) {
            sendResponse(['success' => false, 'message' => 'Invalid due_date format.'], 400);
        }
        $clauses[] = 'due_date = ?';
        $values[] = $dueDate;
    }

    if (array_key_exists('files', $data)) {
        $clauses[] = 'files = ?';
        $values[] = json_encode(normalizeFiles($data['files']));
    }

    if (count($clauses) === 0) {
        sendResponse(['success' => false, 'message' => 'No fields to update.'], 400);
    }

    $values[] = $id;
    $stmt = $db->prepare('UPDATE assignments SET ' . implode(', ', $clauses) . ' WHERE id = ?');
    $stmt->execute($values);

    sendResponse(['success' => true, 'message' => 'Assignment updated successfully.']);
}

function deleteAssignment(PDO $db, $id): void
{
    if (!is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment id.'], 400);
    }

    $id = (int) $id;
    ensureAssignmentExists($db, $id);

    $stmt = $db->prepare('DELETE FROM assignments WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Assignment deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete assignment.'], 500);
}

function getCommentsByAssignment(PDO $db, $assignmentId): void
{
    if (!is_numeric($assignmentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment id.'], 400);
    }

    $stmt = $db->prepare(
        'SELECT id, assignment_id, author, text, created_at
         FROM comments_assignment
         WHERE assignment_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([(int) $assignmentId]);

    sendResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function createComment(PDO $db, array $data): void
{
    foreach (['assignment_id', 'author', 'text'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            sendResponse(['success' => false, 'message' => "Missing {$field}."], 400);
        }
    }

    if (!is_numeric($data['assignment_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment id.'], 400);
    }

    $assignmentId = (int) $data['assignment_id'];
    ensureAssignmentExists($db, $assignmentId);

    $author = sanitizeInput((string) $data['author']);
    $text = sanitizeInput((string) $data['text']);
    $stmt = $db->prepare(
        'INSERT INTO comments_assignment (assignment_id, author, text) VALUES (?, ?, ?)'
    );
    $stmt->execute([$assignmentId, $author, $text]);

    if ($stmt->rowCount() > 0) {
        $id = (int) $db->lastInsertId();
        $fetch = $db->prepare(
            'SELECT id, assignment_id, author, text, created_at FROM comments_assignment WHERE id = ?'
        );
        $fetch->execute([$id]);
        $comment = $fetch->fetch(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'message' => 'Comment created successfully.',
            'id' => $id,
            'data' => $comment,
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create comment.'], 500);
}

function deleteComment(PDO $db, $commentId): void
{
    if (!is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid comment id.'], 400);
    }

    $commentId = (int) $commentId;
    $check = $db->prepare('SELECT id FROM comments_assignment WHERE id = ?');
    $check->execute([$commentId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Comment not found.'], 404);
    }

    $stmt = $db->prepare('DELETE FROM comments_assignment WHERE id = ?');
    $stmt->execute([$commentId]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete comment.'], 500);
}

function ensureAssignmentExists(PDO $db, int $id): void
{
    $stmt = $db->prepare('SELECT id FROM assignments WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }
}

function normalizeFiles($files): array
{
    if (!is_array($files)) {
        return [];
    }

    return array_values(array_filter(array_map(
        fn($file) => trim((string) $file),
        $files
    ), fn($file) => $file !== ''));
}

function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function validateDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function sanitizeInput(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
