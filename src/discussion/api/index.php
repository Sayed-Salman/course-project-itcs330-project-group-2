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
$topicId = $_GET['topic_id'] ?? null;

try {
    if ($method === 'GET') {
        if ($action === 'replies') {
            getRepliesByTopicId($db, $topicId);
        } elseif ($id !== null) {
            getTopicById($db, $id);
        } else {
            getAllTopics($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'reply') {
            createReply($db, $data);
        } else {
            createTopic($db, $data);
        }
    } elseif ($method === 'PUT') {
        updateTopic($db, $data);
    } elseif ($method === 'DELETE') {
        if ($action === 'delete_reply') {
            deleteReply($db, $id);
        } else {
            deleteTopic($db, $id);
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

function getAllTopics(PDO $db): void
{
    $query = 'SELECT id, subject, message, author, created_at FROM topics';
    $params = [];

    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $query .= ' WHERE subject LIKE :search OR message LIKE :search OR author LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $allowedSort = ['subject', 'author', 'created_at'];
    $sort = $_GET['sort'] ?? 'created_at';
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'created_at';
    }

    $order = strtolower($_GET['order'] ?? 'desc');
    if (!in_array($order, ['asc', 'desc'], true)) {
        $order = 'desc';
    }

    $query .= " ORDER BY {$sort} {$order}";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    sendResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getTopicById(PDO $db, $id): void
{
    if (!is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid topic id.'], 400);
    }

    $stmt = $db->prepare('SELECT id, subject, message, author, created_at FROM topics WHERE id = ?');
    $stmt->execute([(int) $id]);
    $topic = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$topic) {
        sendResponse(['success' => false, 'message' => 'Topic not found.'], 404);
    }

    sendResponse(['success' => true, 'data' => $topic]);
}

function createTopic(PDO $db, array $data): void
{
    foreach (['subject', 'message', 'author'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            sendResponse(['success' => false, 'message' => "Missing {$field}."], 400);
        }
    }

    $subject = sanitizeInput((string) $data['subject']);
    $message = sanitizeInput((string) $data['message']);
    $author = sanitizeInput((string) $data['author']);

    $stmt = $db->prepare('INSERT INTO topics (subject, message, author) VALUES (?, ?, ?)');
    $stmt->execute([$subject, $message, $author]);

    if ($stmt->rowCount() > 0) {
        $id = (int) $db->lastInsertId();
        $fetch = $db->prepare('SELECT id, subject, message, author, created_at FROM topics WHERE id = ?');
        $fetch->execute([$id]);
        $topic = $fetch->fetch(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'message' => 'Topic created successfully.',
            'id' => $id,
            'data' => $topic,
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create topic.'], 500);
}

function updateTopic(PDO $db, array $data): void
{
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid topic id.'], 400);
    }

    $id = (int) $data['id'];
    ensureTopicExists($db, $id);

    $clauses = [];
    $values = [];

    if (array_key_exists('subject', $data)) {
        if (trim((string) $data['subject']) === '') {
            sendResponse(['success' => false, 'message' => 'Subject cannot be empty.'], 400);
        }
        $clauses[] = 'subject = ?';
        $values[] = sanitizeInput((string) $data['subject']);
    }

    if (array_key_exists('message', $data)) {
        if (trim((string) $data['message']) === '') {
            sendResponse(['success' => false, 'message' => 'Message cannot be empty.'], 400);
        }
        $clauses[] = 'message = ?';
        $values[] = sanitizeInput((string) $data['message']);
    }

    if (count($clauses) === 0) {
        sendResponse(['success' => false, 'message' => 'No fields to update.'], 400);
    }

    $values[] = $id;
    $stmt = $db->prepare('UPDATE topics SET ' . implode(', ', $clauses) . ' WHERE id = ?');
    $stmt->execute($values);

    sendResponse(['success' => true, 'message' => 'Topic updated successfully.']);
}

function deleteTopic(PDO $db, $id): void
{
    if (!is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid topic id.'], 400);
    }

    $id = (int) $id;
    ensureTopicExists($db, $id);

    $stmt = $db->prepare('DELETE FROM topics WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Topic deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete topic.'], 500);
}

function getRepliesByTopicId(PDO $db, $topicId): void
{
    if (!is_numeric($topicId)) {
        sendResponse(['success' => false, 'message' => 'Invalid topic id.'], 400);
    }

    $stmt = $db->prepare(
        'SELECT id, topic_id, text, author, created_at
         FROM replies
         WHERE topic_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([(int) $topicId]);

    sendResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function createReply(PDO $db, array $data): void
{
    foreach (['topic_id', 'text', 'author'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            sendResponse(['success' => false, 'message' => "Missing {$field}."], 400);
        }
    }

    if (!is_numeric($data['topic_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid topic id.'], 400);
    }

    $topicId = (int) $data['topic_id'];
    ensureTopicExists($db, $topicId);

    $text = sanitizeInput((string) $data['text']);
    $author = sanitizeInput((string) $data['author']);

    $stmt = $db->prepare('INSERT INTO replies (topic_id, text, author) VALUES (?, ?, ?)');
    $stmt->execute([$topicId, $text, $author]);

    if ($stmt->rowCount() > 0) {
        $id = (int) $db->lastInsertId();
        $fetch = $db->prepare('SELECT id, topic_id, text, author, created_at FROM replies WHERE id = ?');
        $fetch->execute([$id]);
        $reply = $fetch->fetch(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'message' => 'Reply created successfully.',
            'id' => $id,
            'data' => $reply,
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create reply.'], 500);
}

function deleteReply(PDO $db, $replyId): void
{
    if (!is_numeric($replyId)) {
        sendResponse(['success' => false, 'message' => 'Invalid reply id.'], 400);
    }

    $replyId = (int) $replyId;
    $check = $db->prepare('SELECT id FROM replies WHERE id = ?');
    $check->execute([$replyId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Reply not found.'], 404);
    }

    $stmt = $db->prepare('DELETE FROM replies WHERE id = ?');
    $stmt->execute([$replyId]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Reply deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete reply.'], 500);
}

function ensureTopicExists(PDO $db, int $id): void
{
    $stmt = $db->prepare('SELECT id FROM topics WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Topic not found.'], 404);
    }
}

function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function sanitizeInput(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
