<?php

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


/* =========================================================
   OPTIONS / CORS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(204);

    exit;
}


/* =========================================================
   DATABASE CONNECTION
========================================================= */

$connection = new mysqli(
    "localhost",
    "root",
    "",
    "students_db"
);

if ($connection->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);

    exit;
}

$connection->set_charset("utf8mb4");


/* =========================================================
   SESSION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["action"]) &&
    $_GET["action"] === "session"
) {

    if (
        !isset($_SESSION["user_id"]) ||
        !is_numeric($_SESSION["user_id"])
    ) {

        echo json_encode([
            "logged_in" => false,
            "user" => null
        ]);

        exit;
    }

    $currentUserId = (int)$_SESSION["user_id"];

    $stmt = $connection->prepare(
        "SELECT
            au.user_id,
            au.username,
            au.last_login
         FROM auth_users au
         WHERE au.user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Session user query failed",
            "details" => $connection->error
        ]);

        exit;
    }

    $stmt->bind_param("i", $currentUserId);

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Session user query failed",
            "details" => $stmt->error
        ]);

        $stmt->close();

        exit;
    }

    $result = $stmt->get_result();
    $sessionUser = $result->fetch_assoc();

    $stmt->close();

    if (!$sessionUser) {

        $_SESSION = [];

        echo json_encode([
            "logged_in" => false,
            "user" => null
        ]);

        exit;
    }

    echo json_encode([
        "logged_in" => true,
        "user" => [
            "id" => (int)$sessionUser["user_id"],
            "username" => $sessionUser["username"],
            "last_login" => $sessionUser["last_login"]
        ]
    ]);

    exit;
}


/* =========================================================
   LOGOUT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["action"]) &&
    $_GET["action"] === "logout"
) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    echo json_encode([
        "success" => true,
        "logged_in" => false
    ]);

    exit;
}


/* =========================================================
   CHECK USERNAME
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["action"]) &&
    $_GET["action"] === "check_username"
) {

    if (!isset($_GET["username"])) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Username is required"
        ]);

        exit;
    }

    $username = trim($_GET["username"]);

    $excludeId = isset($_GET["exclude_id"])
        ? (int)$_GET["exclude_id"]
        : 0;

    if ($username === "") {

        echo json_encode([
            "available" => false
        ]);

        exit;
    }

    if ($excludeId > 0) {

        $stmt = $connection->prepare(
            "SELECT user_id
             FROM auth_users
             WHERE username = ?
             AND user_id != ?
             LIMIT 1"
        );

        if (!$stmt) {

            http_response_code(500);

            echo json_encode([
                "success" => false,
                "message" => "Query preparation failed",
                "details" => $connection->error
            ]);

            exit;
        }

        $stmt->bind_param(
            "si",
            $username,
            $excludeId
        );

    } else {

        $stmt = $connection->prepare(
            "SELECT user_id
             FROM auth_users
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {

            http_response_code(500);

            echo json_encode([
                "success" => false,
                "message" => "Query preparation failed",
                "details" => $connection->error
            ]);

            exit;
        }

        $stmt->bind_param(
            "s",
            $username
        );
    }

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Query execution failed",
            "details" => $stmt->error
        ]);

        $stmt->close();

        exit;
    }

    $result = $stmt->get_result();

    echo json_encode([
        "available" => $result->num_rows === 0
    ]);

    $stmt->close();

    exit;
}


/* =========================================================
   READ JSON
========================================================= */

$body = file_get_contents("php://input");
$data = json_decode($body, true);

if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);

    exit;
}


/* =========================================================
   REGISTER
========================================================= */

if (
    isset($data["action"]) &&
    $data["action"] === "register"
) {

    if (
        !isset($data["username"]) ||
        !isset($data["password"])
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Username and password are required"
        ]);

        exit;
    }

    $username = trim($data["username"]);
    $password = $data["password"];

    if (
        $username === "" ||
        $password === ""
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Username and password cannot be empty"
        ]);

        exit;
    }

    $stmt = $connection->prepare(
        "SELECT user_id
         FROM auth_users
         WHERE username = ?
         LIMIT 1"
    );

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Username query failed",
            "details" => $connection->error
        ]);

        exit;
    }

    $stmt->bind_param(
        "s",
        $username
    );

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Username query failed",
            "details" => $stmt->error
        ]);

        $stmt->close();

        exit;
    }

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $stmt->close();

        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "Username already exists"
        ]);

        exit;
    }

    $stmt->close();

    $stmt = $connection->prepare(
        "INSERT INTO auth_users
        (
            username,
            password_hash
        )
        VALUES
        (
            ?,
            ?
        )"
    );

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "User insert preparation failed",
            "details" => $connection->error
        ]);

        exit;
    }

    $stmt->bind_param(
        "ss",
        $username,
        $password
    );

    if ($stmt->execute()) {

        echo json_encode([
            "success" => true,
            "message" => "User registered successfully"
        ]);

    } else {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to create user",
            "details" => $stmt->error
        ]);
    }

    $stmt->close();

    exit;
}


/* =========================================================
   LOGIN
========================================================= */

if (
    isset($data["action"]) &&
    $data["action"] === "login"
) {

    if (
        !isset($data["username"]) ||
        !isset($data["password"])
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Username and password are required"
        ]);

        exit;
    }

    $username = trim($data["username"]);
    $password = $data["password"];

    $stmt = $connection->prepare(
        "SELECT
            user_id,
            username,
            password_hash,
            last_login
         FROM auth_users
         WHERE username = ?
         LIMIT 1"
    );

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Login query failed",
            "details" => $connection->error
        ]);

        exit;
    }

    $stmt->bind_param(
        "s",
        $username
    );

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Login query failed",
            "details" => $stmt->error
        ]);

        $stmt->close();

        exit;
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $stmt->close();

        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid username or password"
        ]);

        exit;
    }

    $user = $result->fetch_assoc();

    $stmt->close();


    /* =====================================================
       PASSWORD CHECK
       Kept exactly compatible with the current system.
    ===================================================== */

    if ($password !== $user["password_hash"]) {

        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid username or password"
        ]);

        exit;
    }


    /* =====================================================
       SUCCESSFUL LOGIN
    ===================================================== */

    session_regenerate_id(true);

    $_SESSION["user_id"] =
        (int)$user["user_id"];

    $_SESSION["username"] =
        $user["username"];


    /* Record this successful login. */

    $updateLogin = $connection->prepare(
        "UPDATE auth_users
         SET last_login = NOW()
         WHERE user_id = ?"
    );

    if (!$updateLogin) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Could not update last login",
            "details" => $connection->error
        ]);

        exit;
    }

    $updateLogin->bind_param(
        "i",
        $user["user_id"]
    );

    if (!$updateLogin->execute()) {

        $updateLogin->close();

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Could not update last login",
            "details" => $updateLogin->error
        ]);

        exit;
    }

    $updateLogin->close();


    /* Get the exact value stored by MySQL. */

    $lastLogin = null;

    $lastLoginStmt = $connection->prepare(
        "SELECT last_login
         FROM auth_users
         WHERE user_id = ?
         LIMIT 1"
    );

    if ($lastLoginStmt) {

        $lastLoginStmt->bind_param(
            "i",
            $user["user_id"]
        );

        if ($lastLoginStmt->execute()) {

            $lastLoginResult = $lastLoginStmt->get_result();
            $lastLoginRow = $lastLoginResult->fetch_assoc();

            if ($lastLoginRow) {
                $lastLogin = $lastLoginRow["last_login"];
            }
        }

        $lastLoginStmt->close();
    }


    $_SESSION["last_login"] = $lastLogin;

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => [
            "id" => (int)$user["user_id"],
            "username" => $user["username"],
            "last_login" => $lastLogin
        ]
    ]);

    exit;
}


/* =========================================================
   INVALID ACTION
========================================================= */

http_response_code(400);

echo json_encode([
    "success" => false,
    "message" => "Invalid action"
]);

?>
