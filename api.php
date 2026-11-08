<?php
// CORS заголовки для Canvas
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Настройки БД
$db_host = 'localhost';
$db_name = 'books';
$db_user = 'bookparser';
$db_pass = 'U9ikzkULjz@3%v6i';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit();
}

$request_method = $_SERVER["REQUEST_METHOD"];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', trim($uri, '/'));

// --- ПРОВЕРКА API КЛЮЧА (только для изменения данных) ---
$valid_api_key = 'bkXnvV3SA70yOL8nxQdcpNWRHGhk2Qwj4Y';
if (in_array($request_method, ['POST', 'PUT', 'DELETE'])) {
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($provided_key !== $valid_api_key) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized: Invalid API Key"]);
        exit();
    }
}

// --- НОВЫЕ GET ЭНДПОИНТЫ ДЛЯ ДОПОЛНИТЕЛЬНЫХ ДАННЫХ ПАРАГРАФА ---
function getParagraphDetail($pdo, $field) {
    if (!isset($_GET['bookId']) || !isset($_GET['paragraphId'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing bookId or paragraphId"]);
        exit();
    }
    $stmt = $pdo->prepare("SELECT $field FROM paragraph_details WHERE book_id = :bookId AND paragraph_id = :paragraphId");
    $stmt->execute(['bookId' => $_GET['bookId'], 'paragraphId' => $_GET['paragraphId']]);
    $result = $stmt->fetchColumn();
    
    if ($result !== false) {
        if ($field === 'answers' || $field === 'main_points') {
            echo json_encode([$field => json_decode($result, true)]);
        } else {
            echo json_encode([$field => $result]);
        }
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Data not found"]);
    }
    exit();
}

// --- ЭНДПОИНТ ДЛЯ ПОЛУЧЕНИЯ СЛУЧАЙНОГО ФАКТА ---
function getRandomFact($pdo) {
    $stmt = $pdo->query("SELECT name, fact FROM local_celebrities WHERE fact IS NOT NULL AND fact != '' ORDER BY RAND() LIMIT 1");
    $result = $stmt->fetch();
    
    if ($result) {
        echo json_encode($result);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Facts not found"]);
    }
    exit();
}

if ($request_method === 'GET') {
    if (in_array('summary', $uri)) getParagraphDetail($pdo, 'summary');
    if (in_array('answers', $uri)) getParagraphDetail($pdo, 'answers');
    if (in_array('main_points', $uri)) getParagraphDetail($pdo, 'main_points');
    
    // Эндпоинт доступен по URL содержащему 'random-fact' или 'fact'
    if (in_array('random-fact', $uri) || in_array('fact', $uri)) {
        getRandomFact($pdo);
    }
}

// --- ОСНОВНАЯ МАРШРУТИЗАЦИЯ ---
$is_books_api = in_array('books', $uri);
$id = isset($uri[count($uri)-1]) && is_numeric($uri[count($uri)-1]) ? (int)$uri[count($uri)-1] : null;

$inputData = json_decode(file_get_contents("php://input"), true);

if ($is_books_api) {
    // --- CRUD ДЛЯ КНИГ ---
    switch ($request_method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id as bookId, title FROM books ORDER BY id DESC");
            echo json_encode($stmt->fetchAll());
            break;
        case 'POST':
            $stmt = $pdo->prepare("INSERT INTO books (title) VALUES (:title)");
            $stmt->execute(['title' => $inputData['title']]);
            echo json_encode(["bookId" => $pdo->lastInsertId()]);
            break;
        case 'PUT':
            $stmt = $pdo->prepare("UPDATE books SET title = :title WHERE id = :id");
            $stmt->execute(['title' => $inputData['title'], 'id' => $id]);
            echo json_encode(["success" => true]);
            break;
        case 'DELETE':
            $pdo->prepare("DELETE FROM paragraphs WHERE book_id = :id")->execute(['id' => $id]);
            $stmt = $pdo->prepare("DELETE FROM books WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(["success" => true]);
            break;
    }
} else {
    // --- CRUD ДЛЯ ПАРАГРАФОВ ---
    switch ($request_method) {
        case 'GET':
            if (isset($_GET['bookId']) && isset($_GET['paragraphId'])) {
                $stmt = $pdo->prepare("SELECT book_id as bookId, paragraph_id as paragraphId, last_paragraph_id as lastParagraphId, title, parts, questions, has_video as hasVideo FROM paragraphs WHERE book_id = :bookId AND paragraph_id = :paragraphId");
                $stmt->execute(['bookId' => $_GET['bookId'], 'paragraphId' => $_GET['paragraphId']]);
                $row = $stmt->fetch();
                
                if ($row) {
                    $row['bookId'] = (int)$row['bookId'];
                    $row['paragraphId'] = (int)$row['paragraphId'];
                    $row['lastParagraphId'] = (int)$row['lastParagraphId'];
                    $row['hasVideo'] = (bool)$row['hasVideo'];
                    $row['parts'] = json_decode($row['parts'], true);
                    $row['questions'] = json_decode($row['questions'], true);
                    echo json_encode($row);
                } else {
                    http_response_code(404);
                    echo json_encode(["error" => "Paragraph not found"]);
                }
                break; 
            }

            $stmt = $pdo->query("SELECT id, book_id as bookId, paragraph_id as paragraphId, last_paragraph_id as lastParagraphId, title, parts, questions, has_video as hasVideo FROM paragraphs ORDER BY paragraph_id ASC");
            $result = $stmt->fetchAll();
            foreach ($result as &$row) {
                $row['hasVideo'] = (bool)$row['hasVideo'];
                $row['parts'] = json_decode($row['parts'], true);
                $row['questions'] = json_decode($row['questions'], true);
            }
            echo json_encode($result);
            break;
        case 'POST':
            $stmtMax = $pdo->prepare("SELECT MAX(paragraph_id) as maxId FROM paragraphs WHERE book_id = :book_id");
            $stmtMax->execute(['book_id' => $inputData['bookId']]);
            $maxId = $stmtMax->fetchColumn() ?: 0;
            $lastParagraphId = max($maxId, $inputData['paragraphId']);

            $stmt = $pdo->prepare("INSERT INTO paragraphs (book_id, paragraph_id, last_paragraph_id, title, parts, questions, has_video) VALUES (:book_id, :paragraph_id, :last_paragraph_id, :title, :parts, :questions, :has_video)");
            $stmt->execute([
                'book_id' => $inputData['bookId'],
                'paragraph_id' => $inputData['paragraphId'],
                'last_paragraph_id' => $lastParagraphId,
                'title' => $inputData['title'],
                'parts' => json_encode($inputData['parts']),
                'questions' => json_encode($inputData['questions']),
                'has_video' => !empty($inputData['hasVideo']) ? 1 : 0
            ]);
            
            $pdo->prepare("UPDATE paragraphs SET last_paragraph_id = :max_id WHERE book_id = :book_id")
                ->execute(['max_id' => $lastParagraphId, 'book_id' => $inputData['bookId']]);

            echo json_encode(["id" => $pdo->lastInsertId()]);
            break;
        case 'PUT':
            $stmt = $pdo->prepare("UPDATE paragraphs SET book_id = :book_id, paragraph_id = :paragraph_id, title = :title, parts = :parts, questions = :questions, has_video = :has_video WHERE paragraph_id = :id");
            $stmt->execute([
                'book_id' => $inputData['bookId'],
                'paragraph_id' => $inputData['paragraphId'],
                'title' => $inputData['title'],
                'parts' => json_encode($inputData['parts']),
                'questions' => json_encode($inputData['questions']),
                'has_video' => !empty($inputData['hasVideo']) ? 1 : 0,
                'id' => $id
            ]);

            $stmtMax = $pdo->prepare("SELECT MAX(paragraph_id) as maxId FROM paragraphs WHERE book_id = :book_id");
            $stmtMax->execute(['book_id' => $inputData['bookId']]);
            $maxId = $stmtMax->fetchColumn() ?: 0;
            
            $pdo->prepare("UPDATE paragraphs SET last_paragraph_id = :max_id WHERE book_id = :book_id")
                ->execute(['max_id' => $maxId, 'book_id' => $inputData['bookId']]);

            echo json_encode(["success" => true]);
            break;
        case 'DELETE':
            $stmtInfo = $pdo->prepare("SELECT book_id FROM paragraphs WHERE paragraph_id = :id");
            $stmtInfo->execute(['id' => $id]);
            $bookId = $stmtInfo->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM paragraphs WHERE paragraph_id = :id");
            $stmt->execute(['id' => $id]);

            if ($bookId) {
                $stmtMax = $pdo->prepare("SELECT MAX(paragraph_id) as maxId FROM paragraphs WHERE book_id = :book_id");
                $stmtMax->execute(['book_id' => $bookId]);
                $maxId = $stmtMax->fetchColumn() ?: 0;
                
                $pdo->prepare("UPDATE paragraphs SET last_paragraph_id = :max_id WHERE book_id = :book_id")
                    ->execute(['max_id' => $maxId, 'book_id' => $bookId]);
            }

            echo json_encode(["success" => true]);
            break;
    }
}
?>