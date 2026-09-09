<?php

header("Content-Type: application/json; charset=UTF-8");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

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


    $data = json_decode(
        $body,
        true
    );


    if (!is_array($data)) {

        sendJson([
            "error" => "Invalid JSON body"
        ], 400);

    }


    return $data;
}


/*
|--------------------------------------------------------------------------
| GET USER ID
|--------------------------------------------------------------------------
*/

function getId()
{
    if (
        !isset($_GET["id"]) ||
        !is_numeric($_GET["id"])
    ) {

        sendJson([
            "error" => "Valid user ID is required"
        ], 400);

    }


    $id = (int)$_GET["id"];


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

        "name" =>
            trim((string)$data["name"]),

        "dob" =>
            trim((string)$data["dob"]),

        "fathername" =>
            trim((string)$data["fathername"]),

        "qualification" =>
            trim((string)$data["qualification"]),

        "city" =>
            trim((string)$data["city"]),

        "mobileno" =>
            trim((string)$data["mobileno"]),

        "address" =>
            trim((string)$data["address"])

    ];
}


/*
|--------------------------------------------------------------------------
| CREATE HISTORY LOG
|--------------------------------------------------------------------------
|
| This stores the COMPLETE user state.
|
|--------------------------------------------------------------------------
*/

function createHistoryLog(
    $connection,
    $user,
    $action
) {

    $stmt = $connection->prepare(
        "INSERT INTO timelog
        (
            user_id,
            action,
            times,
            name,
            dob,
            fathername,
            qualification,
            city,
            mobileno,
            address,
            status
        )
        VALUES
        (
            ?,
            ?,
            NOW(),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )"
    );


    if (!$stmt) {

        throw new Exception(
            "History preparation failed: "
            . $connection->error
        );

    }


    $stmt->bind_param(
        "isssssssss",

        $user["id"],

        $action,

        $user["name"],

        $user["dob"],

        $user["fathername"],

        $user["qualification"],

        $user["city"],

        $user["mobileno"],

        $user["address"],

        $user["status"]
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "History insert failed: "
            . $stmt->error
        );

    }


    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| CONNECTION
|--------------------------------------------------------------------------
*/

$connection = con();

$method =
    $_SERVER["REQUEST_METHOD"];


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($method === "GET") {


    /*
    |--------------------------------------------------------------------------
    | GET HISTORY
    |--------------------------------------------------------------------------
    |
    | /user.php?id=31&his=true
    |
    |--------------------------------------------------------------------------
    */

    if (
        isset($_GET["his"]) &&
        filter_var(
            $_GET["his"],
            FILTER_VALIDATE_BOOLEAN
        )
    ) {

        $id = getId();


        /*
        Check user exists.
        */

        $stmt = $connection->prepare(
            "SELECT id, status
             FROM users
             WHERE id = ?"
        );


        if (!$stmt) {

            sendJson([
                "error" => "User lookup failed",
                "details" => $connection->error
            ], 500);

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            sendJson([
                "error" => "User lookup failed",
                "details" => $stmt->error
            ], 500);

        }


        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$user) {

            sendJson([
                "error" => "User not found"
            ], 404);

        }


        /*
        Inactive users cannot view history.
        */

        if (
            $user["status"] === "inactive"
        ) {

            sendJson([
                "error" =>
                    "Inactive users cannot be viewed"
            ], 403);

        }


        /*
        Get complete history.
        */

        $stmt = $connection->prepare(
            "SELECT
                id,
                user_id,
                action,
                times,
                name,
                dob,
                fathername,
                qualification,
                city,
                mobileno,
                address,
                status
             FROM timelog
             WHERE user_id = ?
             ORDER BY id DESC"
        );


        if (!$stmt) {

            sendJson([
                "error" => "History query failed",
                "details" => $connection->error
            ], 500);

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            sendJson([
                "error" => "History query failed",
                "details" => $stmt->error
            ], 500);

        }


        $result =
            $stmt->get_result();


        $history = [];


        while (
            $row = $result->fetch_assoc()
        ) {

            $history[] = $row;

        }


        $stmt->close();


        sendJson([
            "history" => $history
        ]);

    }


    /*
    |--------------------------------------------------------------------------
    | GET ONE USER
    |--------------------------------------------------------------------------
    |
    | /user.php?id=31
    |
    |--------------------------------------------------------------------------
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
                "error" => "User query failed",
                "details" => $connection->error
            ], 500);

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            sendJson([
                "error" => "User query failed",
                "details" => $stmt->error
            ], 500);

        }


        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$user) {

            sendJson([
                "error" => "User not found"
            ], 404);

        }


        /*
        Inactive users cannot be updated.
        */

        if (
            $user["status"] === "inactive"
        ) {

            sendJson([
                "error" =>
                    "Inactive users cannot be updated"
            ], 403);

        }


        sendJson($user);

    }


    /*
    |--------------------------------------------------------------------------
    | GET ALL USERS
    |--------------------------------------------------------------------------
    */

    $page =
        isset($_GET["page"])
        ? (int)$_GET["page"]
        : 1;


    $limit =
        isset($_GET["limit"])
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


    /*
    Count users.
    */

    $countResult =
        $connection->query(
            "SELECT COUNT(*) AS total
             FROM users"
        );


    if (!$countResult) {

        sendJson([
            "error" => "Could not count users",
            "details" => $connection->error
        ], 500);

    }


    $total =
        (int)$countResult
            ->fetch_assoc()["total"];


    $totalPages =
        max(
            1,
            (int)ceil(
                $total / $limit
            )
        );


    if (
        $page > $totalPages
    ) {

        $page =
            $totalPages;

    }


    $offset =
        ($page - 1) * $limit;


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
            "error" => "User query failed",
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


    $result =
        $stmt->get_result();


    $users = [];


    while (
        $row = $result->fetch_assoc()
    ) {

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
| POST - CREATE USER
|--------------------------------------------------------------------------
*/

if ($method === "POST") {

    $data =
        validateUser(
            getJsonBody()
        );


    $connection->begin_transaction();


    try {


        /*
        Insert user.
        */

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
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )"
        );


        if (!$stmt) {

            throw new Exception(
                "User insert preparation failed"
            );

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

            throw new Exception(
                "User creation failed: "
                . $stmt->error
            );

        }


        $newId =
            $connection->insert_id;


        $stmt->close();


        /*
        Get newly created user.
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
             WHERE id = ?"
        );


        if (!$stmt) {

            throw new Exception(
                "Created user lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $newId
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Created user lookup failed: "
                . $stmt->error
            );

        }


        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$user) {

            throw new Exception(
                "Created user could not be found"
            );

        }


        /*
        Create history.
        */

        createHistoryLog(
            $connection,
            $user,
            "CREATE"
        );


        $connection->commit();


        sendJson([

            "message" =>
                "User created successfully",

            "id" =>
                $newId

        ], 201);

    }


    catch (Exception $e) {

        $connection->rollback();


        sendJson([

            "error" =>
                "User creation failed",

            "details" =>
                $e->getMessage()

        ], 500);

    }

}



/*
|--------------------------------------------------------------------------
| PUT - UPDATE USER
|--------------------------------------------------------------------------
*/

if ($method === "PUT") {

    $id =
        getId();


    $data =
        validateUser(
            getJsonBody()
        );


    $connection->begin_transaction();


    try {


        /*
        Get current user.
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
             WHERE id = ?
             FOR UPDATE"
        );


        if (!$stmt) {

            throw new Exception(
                "User lookup preparation failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "User lookup failed: "
                . $stmt->error
            );

        }


        $oldUser =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$oldUser) {

            throw new Exception(
                "USER_NOT_FOUND"
            );

        }


        /*
        Inactive user cannot be updated.
        */

        if (
            $oldUser["status"] === "inactive"
        ) {

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
                "Update preparation failed"
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
                "Update failed: "
                . $stmt->error
            );

        }


        $stmt->close();


        /*
        Get updated user.
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
             WHERE id = ?"
        );


        if (!$stmt) {

            throw new Exception(
                "Updated user lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Updated user lookup failed: "
                . $stmt->error
            );

        }


        $updatedUser =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        /*
        Create complete UPDATE snapshot.
        */

        createHistoryLog(
            $connection,
            $updatedUser,
            "UPDATE"
        );


        $connection->commit();


        sendJson([

            "message" =>
                "User updated successfully"

        ]);

    }


    catch (Exception $e) {

        $connection->rollback();


        if (
            $e->getMessage()
            === "USER_NOT_FOUND"
        ) {

            sendJson([
                "error" =>
                    "User not found"
            ], 404);

        }


        if (
            $e->getMessage()
            === "USER_INACTIVE"
        ) {

            sendJson([
                "error" =>
                    "Inactive users cannot be updated"
            ], 403);

        }


        sendJson([

            "error" =>
                "User update failed",

            "details" =>
                $e->getMessage()

        ], 500);

    }

}



/*
|--------------------------------------------------------------------------
| PATCH - STATUS
|--------------------------------------------------------------------------
|
| PATCH /user.php?id=31
|
| {
|     "status": "inactive"
| }
|
|--------------------------------------------------------------------------
*/

if ($method === "PATCH") {

    $id =
        getId();


    $data =
        getJsonBody();


    if (
        !isset($data["status"])
    ) {

        sendJson([
            "error" =>
                "Status is required"
        ], 400);

    }


    if (
        $data["status"] !== "active" &&
        $data["status"] !== "inactive"
    ) {

        sendJson([
            "error" =>
                "Status must be active or inactive"
        ], 400);

    }


    $newStatus =
        $data["status"];


    $connection->begin_transaction();


    try {


        /*
        Get current user.
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
             WHERE id = ?
             FOR UPDATE"
        );


        if (!$stmt) {

            throw new Exception(
                "User lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "User lookup failed: "
                . $stmt->error
            );

        }


        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$user) {

            throw new Exception(
                "USER_NOT_FOUND"
            );

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
                "Status update preparation failed"
            );

        }


        $stmt->bind_param(
            "si",
            $newStatus,
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Status update failed: "
                . $stmt->error
            );

        }


        $stmt->close();


        /*
        Get updated user.
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
             WHERE id = ?"
        );


        if (!$stmt) {

            throw new Exception(
                "Updated user lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Updated user lookup failed: "
                . $stmt->error
            );

        }


        $updatedUser =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        /*
        Log status operation.
        */

        createHistoryLog(
            $connection,
            $updatedUser,
            "DELETE"
        );


        $connection->commit();


        sendJson([

            "message" =>
                "Status changed successfully",

            "status" =>
                $newStatus

        ]);

    }


    catch (Exception $e) {

        $connection->rollback();


        if (
            $e->getMessage()
            === "USER_NOT_FOUND"
        ) {

            sendJson([
                "error" =>
                    "User not found"
            ], 404);

        }


        sendJson([

            "error" =>
                "Status change failed",

            "details" =>
                $e->getMessage()

        ], 500);

    }

}



/*
|--------------------------------------------------------------------------
| DELETE - TOGGLE STATUS
|--------------------------------------------------------------------------
|
| Your current HTML uses DELETE for the D button.
|
| active   -> inactive
| inactive -> active
|
| It does NOT actually delete the user.
|
|--------------------------------------------------------------------------
*/

if ($method === "DELETE") {

    $id =
        getId();


    $connection->begin_transaction();


    try {


        /*
        Get current user.
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
             WHERE id = ?
             FOR UPDATE"
        );


        if (!$stmt) {

            throw new Exception(
                "User lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "User lookup failed: "
                . $stmt->error
            );

        }


        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$user) {

            throw new Exception(
                "USER_NOT_FOUND"
            );

        }


        /*
        Toggle status.
        */

        if (
            $user["status"] === "active"
        ) {

            $newStatus =
                "inactive";

        }

        else {

            $newStatus =
                "active";

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
                "Status update preparation failed"
            );

        }


        $stmt->bind_param(
            "si",
            $newStatus,
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Status update failed: "
                . $stmt->error
            );

        }


        $stmt->close();


        /*
        Get updated user.
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
             WHERE id = ?"
        );


        if (!$stmt) {

            throw new Exception(
                "Updated user lookup failed"
            );

        }


        $stmt->bind_param(
            "i",
            $id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Updated user lookup failed: "
                . $stmt->error
            );

        }


        $updatedUser =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        /*
        Log complete snapshot.
        */

        createHistoryLog(
            $connection,
            $updatedUser,
            "DELETE"
        );


        $connection->commit();


        sendJson([

            "message" =>
                "Status changed successfully",

            "status" =>
                $newStatus

        ]);

    }


    catch (Exception $e) {

        $connection->rollback();


        if (
            $e->getMessage()
            === "USER_NOT_FOUND"
        ) {

            sendJson([
                "error" =>
                    "User not found"
            ], 404);

        }


        sendJson([

            "error" =>
                "Status change failed",

            "details" =>
                $e->getMessage()

        ], 500);

    }

}



/*
|--------------------------------------------------------------------------
| METHOD NOT ALLOWED
|--------------------------------------------------------------------------
*/

sendJson([

    "error" =>
        "Method not allowed"

], 405);

?>
