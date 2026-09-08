<?php

header("Content-Type: application/json; charset=UTF-8");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}


/*
|--------------------------------------------------------------------------
| SEND JSON
|--------------------------------------------------------------------------
*/

function sendJson($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

function con()
{
    $connection = new mysqli(
        "localhost",
        "root",
        "",
        "students_db"
    );

    if ($connection->connect_error) {
        sendJson([
            "error" => "Database connection failed",
            "details" => $connection->connect_error
        ], 500);
    }

    $connection->set_charset("utf8mb4");

    return $connection;
}


/*
|--------------------------------------------------------------------------
| READ JSON BODY
|--------------------------------------------------------------------------
*/

function getJsonBody()
{
    $body = file_get_contents("php://input");

    if ($body === false || trim($body) === "") {
        sendJson([
            "error" => "Request body is empty"
        ], 400);
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        sendJson([
            "error" => "Invalid JSON body"
        ], 400);
    }

    return $data;
}


/*
|--------------------------------------------------------------------------
| GET ID
|--------------------------------------------------------------------------
*/

function getId()
{
    if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
        sendJson([
            "error" => "Valid user ID is required"
        ], 400);
    }

    $id = (int) $_GET["id"];

    if ($id <= 0) {
        sendJson([
            "error" => "Valid user ID is required"
        ], 400);
    }

    return $id;
}


/*
|--------------------------------------------------------------------------
| VALIDATE USER DATA
|--------------------------------------------------------------------------
*/

function validateUser($data)
{
    $fields = [
        "name",
        "dob",
        "fathername",
        "qualification",
        "city",
        "mobileno",
        "address"
    ];

    foreach ($fields as $field) {

        if (
            !isset($data[$field]) ||
            trim((string)$data[$field]) === ""
        ) {
            sendJson([
                "error" => "Missing field: " . $field
            ], 400);
        }
    }

    return [
        "name" => trim((string)$data["name"]),
        "dob" => trim((string)$data["dob"]),
        "fathername" => trim((string)$data["fathername"]),
        "qualification" => trim((string)$data["qualification"]),
        "city" => trim((string)$data["city"]),
        "mobileno" => trim((string)$data["mobileno"]),
        "address" => trim((string)$data["address"])
    ];
}


/*
|--------------------------------------------------------------------------
| CONNECTION
|--------------------------------------------------------------------------
*/

$connection = con();

$method = $_SERVER["REQUEST_METHOD"];


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| GET /user.php
|       -> all users
|
| GET /user.php?id=5
|       -> one user
|
| GET /user.php?id=5&his=true
|       -> history
|
|--------------------------------------------------------------------------
*/

if ($method === "GET") {

    /*
    |----------------------------------------------------------------------
    | HISTORY
    |----------------------------------------------------------------------
    */

    if (
        isset($_GET["his"]) &&
        filter_var($_GET["his"], FILTER_VALIDATE_BOOLEAN)
    ) {

        $id = getId();


        // Check user exists and is active

        $check = $connection->prepare(
            "SELECT status
             FROM users
             WHERE id = ?"
        );

        if (!$check) {
            sendJson([
                "error" => "Database query preparation failed",
                "details" => $connection->error
            ], 500);
        }

        $check->bind_param("i", $id);

        if (!$check->execute()) {
            sendJson([
                "error" => "Database query failed",
                "details" => $check->error
            ], 500);
        }

        $result = $check->get_result();
        $user = $result->fetch_assoc();

        $check->close();


        if (!$user) {
            sendJson([
                "error" => "User not found"
            ], 404);
        }


        if ($user["status"] === "inactive") {
            sendJson([
                "error" => "Inactive users cannot be viewed"
            ], 403);
        }


        /*
        Get history.
        */

        $stmt = $connection->prepare(
            "SELECT
                id,
                user_id,
                times,
                action,
                fieldd,
                fromm,
                too
             FROM timelog
             WHERE user_id = ?
             ORDER BY id DESC"
        );

        if (!$stmt) {
            sendJson([
                "error" => "History query preparation failed",
                "details" => $connection->error
            ], 500);
        }

        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            sendJson([
                "error" => "History query failed",
                "details" => $stmt->error
            ], 500);
        }

        $result = $stmt->get_result();

        $history = [];

        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }

        $stmt->close();


        sendJson([
            "history" => $history
        ]);
    }


    /*
    |----------------------------------------------------------------------
    | GET ONE USER
    |----------------------------------------------------------------------
    */

    if (isset($_GET["id"])) {

        $id = getId();

        $stmt = $connection->prepare(
            "SELECT
                id,
                name,
                dob,
                fathername,
                qualification,
                city,
                mobileno,
                address,
                status
             FROM users
             WHERE id = ?"
        );

        if (!$stmt) {
            sendJson([
                "error" => "Database query preparation failed",
                "details" => $connection->error
            ], 500);
        }

        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            sendJson([
                "error" => "Database query failed",
                "details" => $stmt->error
            ], 500);
        }

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        $stmt->close();


        if (!$user) {
            sendJson([
                "error" => "User not found"
            ], 404);
        }


        if ($user["status"] === "inactive") {
            sendJson([
                "error" => "Inactive users cannot be updated"
            ], 403);
        }


        sendJson($user);
    }


    /*
    |----------------------------------------------------------------------
    | GET ALL USERS WITH PAGINATION
    |----------------------------------------------------------------------
    */

    $page = isset($_GET["page"])
        ? (int)$_GET["page"]
        : 1;

    $limit = isset($_GET["limit"])
        ? (int)$_GET["limit"]
        : 5;


    if ($page < 1) {
        $page = 1;
    }

    if ($limit < 1) {
        $limit = 5;
    }

    if ($limit > 100) {
        $limit = 100;
    }


    $offset = ($page - 1) * $limit;


    /*
    Count users.
    */

    $countResult = $connection->query(
        "SELECT COUNT(*) AS total
         FROM users"
    );

    if (!$countResult) {
        sendJson([
            "error" => "Could not count users",
            "details" => $connection->error
        ], 500);
    }

    $total = (int)$countResult->fetch_assoc()["total"];


    $totalPages = max(
        1,
        (int)ceil($total / $limit)
    );


    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }


    /*
    Get users.
    */

    $stmt = $connection->prepare(
        "SELECT
            id,
            name,
            dob,
            fathername,
            qualification,
            city,
            mobileno,
            address,
            status
         FROM users
         ORDER BY id DESC
         LIMIT ? OFFSET ?"
    );

    if (!$stmt) {
        sendJson([
            "error" => "User query preparation failed",
            "details" => $connection->error
        ], 500);
    }

    $stmt->bind_param(
        "ii",
        $limit,
        $offset
    );

    if (!$stmt->execute()) {
        sendJson([
            "error" => "User query failed",
            "details" => $stmt->error
        ], 500);
    }

    $result = $stmt->get_result();

    $users = [];

    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    $stmt->close();


    sendJson([
        "users" => $users,
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "totalPages" => $totalPages
    ]);
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
|
| Create a new user.
|
|--------------------------------------------------------------------------
*/

if ($method === "POST") {

    $data = validateUser(
        getJsonBody()
    );


    $stmt = $connection->prepare(
        "INSERT INTO users
        (
            name,
            dob,
            fathername,
            qualification,
            city,
            mobileno,
            address
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        sendJson([
            "error" => "Insert preparation failed",
            "details" => $connection->error
        ], 500);
    }


    $stmt->bind_param(
        "sssssss",
        $data["name"],
        $data["dob"],
        $data["fathername"],
        $data["qualification"],
        $data["city"],
        $data["mobileno"],
        $data["address"]
    );


    if (!$stmt->execute()) {
        sendJson([
            "error" => "User creation failed",
            "details" => $stmt->error
        ], 500);
    }


    $newId = $connection->insert_id;

    $stmt->close();


    sendJson([
        "message" => "User created successfully",
        "id" => $newId
    ], 201);
}


/*
|--------------------------------------------------------------------------
| PUT
|--------------------------------------------------------------------------
|
| Update ALL user fields.
|
|--------------------------------------------------------------------------
*/

if ($method === "PUT") {

    $id = getId();

    $data = validateUser(
        getJsonBody()
    );


    $connection->begin_transaction();


    try {

        /*
        Get old user.
        */

        $stmt = $connection->prepare(
            "SELECT
                name,
                dob,
                fathername,
                qualification,
                city,
                mobileno,
                address,
                status
             FROM users
             WHERE id = ?
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new Exception(
                "Could not prepare user lookup"
            );
        }

        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            throw new Exception(
                $stmt->error
            );
        }

        $oldUser = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();


        if (!$oldUser) {
            throw new Exception(
                "USER_NOT_FOUND"
            );
        }


        /*
        Do not update inactive users.
        */

        if ($oldUser["status"] === "inactive") {
            throw new Exception(
                "USER_INACTIVE"
            );
        }


        /*
        Update user.
        */

        $stmt = $connection->prepare(
            "UPDATE users SET
                name = ?,
                dob = ?,
                fathername = ?,
                qualification = ?,
                city = ?,
                mobileno = ?,
                address = ?
             WHERE id = ?"
        );

        if (!$stmt) {
            throw new Exception(
                "Could not prepare update"
            );
        }


        $stmt->bind_param(
            "sssssssi",
            $data["name"],
            $data["dob"],
            $data["fathername"],
            $data["qualification"],
            $data["city"],
            $data["mobileno"],
            $data["address"],
            $id
        );


        if (!$stmt->execute()) {
            throw new Exception(
                $stmt->error
            );
        }

        $stmt->close();


        /*
        Log changed fields.
        */

        $fields = [
            "name",
            "dob",
            "fathername",
            "qualification",
            "city",
            "mobileno",
            "address"
        ];


        $log = $connection->prepare(
            "INSERT INTO timelog
            (
                user_id,
                times,
                action,
                fieldd,
                fromm,
                too
            )
            VALUES (?, NOW(), ?, ?, ?, ?)"
        );


        if (!$log) {
            throw new Exception(
                "Could not prepare history insert"
            );
        }


        $action = "U";


        foreach ($fields as $field) {

            $oldValue = (string)$oldUser[$field];
            $newValue = (string)$data[$field];


            if ($oldValue !== $newValue) {

                $log->bind_param(
                    "issss",
                    $id,
                    $action,
                    $field,
                    $oldValue,
                    $newValue
                );


                if (!$log->execute()) {
                    throw new Exception(
                        $log->error
                    );
                }
            }
        }


        $log->close();


        $connection->commit();


        sendJson([
            "message" => "User updated successfully"
        ]);
    }


    catch (Exception $e) {

        $connection->rollback();


        if ($e->getMessage() === "USER_NOT_FOUND") {

            sendJson([
                "error" => "User not found"
            ], 404);
        }


        if ($e->getMessage() === "USER_INACTIVE") {

            sendJson([
                "error" => "Inactive users cannot be updated"
            ], 403);
        }


        sendJson([
            "error" => "Update failed",
            "details" => $e->getMessage()
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| PATCH
|--------------------------------------------------------------------------
|
| PATCH is useful for partial updates.
| Currently used for status if you want it later.
|
|--------------------------------------------------------------------------
*/

if ($method === "PATCH") {

    $id = getId();

    $data = getJsonBody();


    /*
    Status change.
    */

    if (isset($data["status"])) {

        if (
            $data["status"] !== "active" &&
            $data["status"] !== "inactive"
        ) {
            sendJson([
                "error" => "Status must be active or inactive"
            ], 400);
        }


        $connection->begin_transaction();


        try {

            $stmt = $connection->prepare(
                "SELECT status
                 FROM users
                 WHERE id = ?
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception(
                    "Could not prepare status lookup"
                );
            }

            $stmt->bind_param("i", $id);

            if (!$stmt->execute()) {
                throw new Exception(
                    $stmt->error
                );
            }

            $user = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            if (!$user) {
                throw new Exception(
                    "USER_NOT_FOUND"
                );
            }


            $oldStatus = $user["status"];
            $newStatus = $data["status"];


            /*
            Update status.
            */

            $stmt = $connection->prepare(
                "UPDATE users
                 SET status = ?
                 WHERE id = ?"
            );

            if (!$stmt) {
                throw new Exception(
                    "Could not prepare status update"
                );
            }


            $stmt->bind_param(
                "si",
                $newStatus,
                $id
            );


            if (!$stmt->execute()) {
                throw new Exception(
                    $stmt->error
                );
            }

            $stmt->close();


            /*
            Log status change.
            */

            if ($oldStatus !== $newStatus) {

                $log = $connection->prepare(
                    "INSERT INTO timelog
                    (
                        user_id,
                        times,
                        action,
                        fieldd,
                        fromm,
                        too
                    )
                    VALUES (?, NOW(), ?, ?, ?, ?)"
                );


                if (!$log) {
                    throw new Exception(
                        "Could not prepare status history"
                    );
                }


                $action = "D";
                $field = "status";


                $log->bind_param(
                    "issss",
                    $id,
                    $action,
                    $field,
                    $oldStatus,
                    $newStatus
                );


                if (!$log->execute()) {
                    throw new Exception(
                        $log->error
                    );
                }


                $log->close();
            }


            $connection->commit();


            sendJson([
                "message" => "Status changed successfully",
                "status" => $newStatus
            ]);
        }


        catch (Exception $e) {

            $connection->rollback();


            if ($e->getMessage() === "USER_NOT_FOUND") {

                sendJson([
                    "error" => "User not found"
                ], 404);
            }


            sendJson([
                "error" => "Status change failed",
                "details" => $e->getMessage()
            ], 500);
        }
    }


    sendJson([
        "error" => "PATCH currently supports status only"
    ], 400);
}


/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
|
| Your D button can use DELETE.
|
| IMPORTANT:
| DELETE DOES NOT DELETE THE USER.
|
| It toggles:
|
| active -> inactive
| inactive -> active
|
|--------------------------------------------------------------------------
*/

if ($method === "DELETE") {

    $id = getId();


    $connection->begin_transaction();


    try {

        /*
        Get current status.
        */

        $stmt = $connection->prepare(
            "SELECT status
             FROM users
             WHERE id = ?
             FOR UPDATE"
        );


        if (!$stmt) {
            throw new Exception(
                "Could not prepare status lookup"
            );
        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {
            throw new Exception(
                $stmt->error
            );
        }


        $user = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();


        if (!$user) {
            throw new Exception(
                "USER_NOT_FOUND"
            );
        }


        $oldStatus = $user["status"];


        /*
        Toggle status.
        */

        if ($oldStatus === "active") {
            $newStatus = "inactive";
        } else {
            $newStatus = "active";
        }


        /*
        Update status.
        */

        $stmt = $connection->prepare(
            "UPDATE users
             SET status = ?
             WHERE id = ?"
        );


        if (!$stmt) {
            throw new Exception(
                "Could not prepare status update"
            );
        }


        $stmt->bind_param(
            "si",
            $newStatus,
            $id
        );


        if (!$stmt->execute()) {
            throw new Exception(
                $stmt->error
            );
        }


        $stmt->close();


        /*
        Add history.
        */

        $log = $connection->prepare(
            "INSERT INTO timelog
            (
                user_id,
                times,
                action,
                fieldd,
                fromm,
                too
            )
            VALUES (?, NOW(), ?, ?, ?, ?)"
        );


        if (!$log) {
            throw new Exception(
                "Could not prepare history insert"
            );
        }


        $action = "D";
        $field = "status";


        $log->bind_param(
            "issss",
            $id,
            $action,
            $field,
            $oldStatus,
            $newStatus
        );


        if (!$log->execute()) {
            throw new Exception(
                $log->error
            );
        }


        $log->close();


        $connection->commit();


        sendJson([
            "message" => "Status changed successfully",
            "status" => $newStatus
        ]);
    }


    catch (Exception $e) {

        $connection->rollback();


        if ($e->getMessage() === "USER_NOT_FOUND") {

            sendJson([
                "error" => "User not found"
            ], 404);
        }


        sendJson([
            "error" => "Status change failed",
            "details" => $e->getMessage()
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| METHOD NOT ALLOWED
|--------------------------------------------------------------------------
*/

sendJson([
    "error" => "Method not allowed"
], 405);

?>