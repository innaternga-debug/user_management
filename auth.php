<?php

session_start();

header("Content-Type: application/json; charset=UTF-8");

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");




if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["action"]) &&
    $_GET["action"] === "session"
) {
    echo json_encode([
        "logged_in" => isset($_SESSION["user_id"]),
        "user" => isset($_SESSION["user_id"])
            ? [
                "id" => $_SESSION["user_id"],
                "username" => $_SESSION["username"]
            ]
            : null
    ]);

    exit;
}

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








/* -------------------------
   OPTIONS / CORS
------------------------- */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(204);

    exit;
}


/* -------------------------
   DATABASE CONNECTION
------------------------- */

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


/* -------------------------
   CHECK USERNAME
   GET REQUEST
------------------------- */

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["action"]) &&
    $_GET["action"] === "check_username"
) {

    /* ==============================
       CHECK USERNAME
       ============================== */

    /* Username not provided */

    if (!isset($_GET["username"])) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Username is required"
        ]);

        exit;
    }


    /* Get username */

    $username = trim($_GET["username"]);


    /*
       When updating a user:

       exclude_id = ID of the user being edited.

       Example:

       User ID = 5
       Username = "john"

       We search for:

       username = "john"
       AND user_id != 5

       Therefore John's own username is considered available.
    */

    $excludeId = isset($_GET["exclude_id"])
        ? (int)$_GET["exclude_id"]
        : 0;


    /* Empty username */

    if ($username === "") {

        echo json_encode([
            "available" => false
        ]);

        exit;
    }


    /* ==============================
       SEARCH USERNAME
       ============================== */

    if ($excludeId > 0) {

        /*
           UPDATE MODE

           Ignore the current user's own username.
        */

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

        /*
           CREATE MODE

           No user is excluded.
        */

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


    /* ==============================
       EXECUTE QUERY
       ============================== */

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


    /* ==============================
       CHECK RESULT
       ============================== */

    $result = $stmt->get_result();


    /*
       Row exists
       → Username is already taken.

       No row exists
       → Username is available.
    */

    if ($result->num_rows > 0) {

        echo json_encode([
            "available" => false
        ]);

    } else {

        echo json_encode([
            "available" => true
        ]);
    }


    $stmt->close();

    exit;
}



/* -------------------------
   READ JSON
   POST REQUESTS
------------------------- */

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


/* -------------------------
   REGISTER
------------------------- */

if (
    isset($data["action"]) &&
    $data["action"] === "register"
){

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


        /* Check whether username already exists */

        $stmt = $connection->prepare(
            "SELECT user_id
            FROM auth_users
            WHERE username = ?"
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


        $stmt->execute();


        $result = $stmt->get_result();


        if ($result->num_rows > 0) {

            http_response_code(409);

            echo json_encode([
                "success" => false,
                "message" => "Username already exists"
            ]);

            exit;
        }


        $stmt->close();


        /* Create password hash */


        /* Insert auth user */

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


/* -------------------------
   LOGIN
------------------------- */

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


    /* Find user */

    $stmt = $connection->prepare(
        "SELECT
            user_id,
            username,
            password_hash
         FROM auth_users
         WHERE username = ?"
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


    $stmt->execute();


    $result = $stmt->get_result();


    if ($result->num_rows === 0) {

        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid username or password"
        ]);

        exit;
    }


    $user = $result->fetch_assoc();


    /* Verify password */
if ($password !== $user["password_hash"]) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid username or password"
    ]);

    exit;
}

    /* Login successful */

    session_regenerate_id(true);


    $_SESSION["user_id"] =
        $user["user_id"];

    $_SESSION["username"] =
        $user["username"];


    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => [
            "id" => $user["user_id"],
            "username" => $user["username"]
        ]
    ]);


    exit;
}

/* -------------------------
   INVALID ACTION
------------------------- */

http_response_code(400);

echo json_encode([
    "success" => false,
    "message" => "Invalid action"
]);

?>