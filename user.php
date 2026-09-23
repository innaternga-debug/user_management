<?php

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

function sendJson($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

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

function getJsonBody()
{
    $body = file_get_contents("php://input");

    if ($body === false || trim($body) === "") {
        sendJson(["error" => "Request body is empty"], 400);
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        sendJson(["error" => "Invalid JSON body"], 400);
    }

    return $data;
}

function getId()
{
    if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
        sendJson(["error" => "Valid user ID is required"], 400);
    }

    $id = (int)$_GET["id"];

    if ($id <= 0) {
        sendJson(["error" => "Valid user ID is required"], 400);
    }

    return $id;
}

/*
|--------------------------------------------------------------------------
| AUTH / HIERARCHY
|--------------------------------------------------------------------------
|
| Logged-in users can work only with their descendants.
|
| X
| ├── A
| │   ├── P
| │   └── Q
| └── B
|     ├── R
|     └── S
|
| X -> A,B,P,Q,R,S
| A -> P,Q
|
| Public POST creates a root user.
| Logged-in POST creates a child of the logged-in user.
|--------------------------------------------------------------------------
*/

function requireLogin()
{
    if (
        !isset($_SESSION["user_id"]) ||
        !is_numeric($_SESSION["user_id"])
    ) {
        sendJson(["error" => "Login required"], 401);
    }

    return (int)$_SESSION["user_id"];
}

function getAllUsersForTree($connection)
{
    $result = $connection->query(
        "SELECT id, parent_id, status FROM users"
    );

    if (!$result) {
        sendJson([
            "error" => "Could not load user hierarchy",
            "details" => $connection->error
        ], 500);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            "id" => (int)$row["id"],
            "parent_id" =>
                $row["parent_id"] === null
                ? null
                : (int)$row["parent_id"],
            "status" => $row["status"]
        ];
    }


    return $rows;
}

function getDescendantIds($connection, $rootId)
{
    $rows = getAllUsersForTree($connection);

    $children = [];

    foreach ($rows as $row) {
        if ($row["parent_id"] === null) {
            continue;
        }

        if (!isset($children[$row["parent_id"]])) {
            $children[$row["parent_id"]] = [];
        }

        $children[$row["parent_id"]][] = $row["id"];
    }

    $descendants = [];
    $queue = [$rootId];

    while (!empty($queue)) {
        $parentId = array_shift($queue);

        if (!isset($children[$parentId])) {
            continue;
        }

        foreach ($children[$parentId] as $childId) {
            if (isset($descendants[$childId])) {
                continue;
            }

            $descendants[$childId] = true;
            $queue[] = $childId;
        }
    }

    return array_map("intval", array_keys($descendants));
}

function requireDescendantAccess($connection, $targetId)
{
    $currentUserId = (int) requireLogin();
    $targetId = (int) $targetId;
 // Allow editing your own account
    if ($targetId === $currentUserId) {
        return $currentUserId;
    }

    $descendants = getDescendantIds(
        $connection,
        $currentUserId
    );

    if (!in_array($targetId, $descendants, true)) {
        sendJson([
            "error" => "You do not have access to this user"
        ], 403);
    }

    return $currentUserId;
}

function requireActiveCurrentUser($connection, $currentUserId)
{
    $stmt = $connection->prepare(
        "SELECT status
         FROM users
         WHERE id = ?"
    );

    if (!$stmt) {
        sendJson([
            "error" => "Current user lookup failed",
            "details" => $connection->error
        ], 500);
    }

    $stmt->bind_param("i", $currentUserId);

    if (!$stmt->execute()) {
        sendJson([
            "error" => "Current user lookup failed",
            "details" => $stmt->error
        ], 500);
    }

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $_SESSION = [];
        session_destroy();

        sendJson([
            "error" => "Current user not found"
        ], 401);
    }

    if ($user["status"] !== "active") {
        sendJson([
            "error" => "Your account is inactive"
        ], 403);
    }
}

function makePlaceholders($count)
{
    return implode(",", array_fill(0, $count, "?"));
}

function validateUser($data)
{
    $fields = [
        "name",
        "dob",
        "fathername",
        "qualification",
        "city",
        "mobileno",
        "address",
        "username",
        "password"
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
        "address" => trim((string)$data["address"]),
        "username" => trim((string)$data["username"]),
        "password" => (string)$data["password"]
    ];
}

function getUserSnapshot($connection, $id)
{
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
            status,
            last_login
         FROM users
         WHERE id = ?"
    );

    if (!$stmt) {
        throw new Exception(
            "User snapshot preparation failed: "
            . $connection->error
        );
    }

    $stmt->bind_param("i", $id);

    if (!$stmt->execute()) {
        throw new Exception(
            "User snapshot failed: "
            . $stmt->error
        );
    }

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("USER_NOT_FOUND");
    }

    return $user;
}

function createHistoryLog($connection, $user, $action)
{
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
        (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)"
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

function getUsersByIds($connection, $ids)
{
    if (empty($ids)) {
        return [];
    }

    $placeholders = makePlaceholders(count($ids));

    $stmt = $connection->prepare(
        "SELECT
            id,
            parent_id,
            name,
            dob,
            fathername,
            qualification,
            city,
            mobileno,
            address,
            status,
            last_login
         FROM users
         WHERE id IN ($placeholders)
         ORDER BY id DESC"
    );

    if (!$stmt) {
        sendJson([
            "error" => "User query failed",
            "details" => $connection->error
        ], 500);
    }

    $types = str_repeat("i", count($ids));
    $params = [$types];

    foreach ($ids as $id) {
        $params[] = $id;
    }

    $refs = [];

    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    call_user_func_array(
        [$stmt, "bind_param"],
        $refs
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
        $row["id"] = (int)$row["id"];

        $row["parent_id"] =
            $row["parent_id"] === null
            ? null
            : (int)$row["parent_id"];

        $users[] = $row;
    }

    $stmt->close();

    return $users;
}


/*
|--------------------------------------------------------------------------
| LAST LOGIN SUPPORT
|--------------------------------------------------------------------------
|
| The dashboard needs a persistent last-login value.  This implementation
| stores it in users.last_login.  The column is created automatically if it
| does not already exist, so the existing database does not need a manual
| ALTER TABLE step.
|
| The timestamp is recorded once per PHP session, on the first authenticated
| request to this endpoint.  This prevents every dashboard refresh from
| changing the value.
|--------------------------------------------------------------------------
*/


function formatLastLogin($value)
{
    if ($value === null || $value === "") {
        return null;
    }

    return $value;
}

function getAllUsers($connection)
{
    $result = $connection->query(
        "SELECT
            users.id,
            users.parent_id,
            users.name,
            users.dob,
            users.fathername,
            users.qualification,
            users.city,
            users.mobileno,
            users.address,
            users.status,
            auth_users.last_login,
            auth_users.username
         FROM users
         LEFT JOIN auth_users
            ON users.id = auth_users.user_id
         ORDER BY users.id ASC"
    );

    if (!$result) {
        sendJson([
            "error" => "Could not load all users",
            "details" => $connection->error
        ], 500);
    }

    $users = [];

    while ($row = $result->fetch_assoc()) {
        $row["id"] = (int)$row["id"];

        $row["parent_id"] =
            $row["parent_id"] === null
            ? null
            : (int)$row["parent_id"];

        $row["last_login"] =
            formatLastLogin($row["last_login"]);

        $users[] = $row;
    }

    $result->free();

    return $users;
}

$connection = con();
$method = $_SERVER["REQUEST_METHOD"];

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/
if ($method === "GET") {

    /*
    GET HISTORY
    /user.php?id=31&his=true
    */
    if (
        isset($_GET["his"]) &&
        filter_var($_GET["his"], FILTER_VALIDATE_BOOLEAN)
    ) {
        $id = getId();

        /*requireDescendantAccess(
            $connection,
            $id
        );*/

        $stmt = $connection->prepare(
            "SELECT
                timelog.id,
                timelog.user_id,
                timelog.action,
                timelog.times,
                timelog.name,
                timelog.dob,
                timelog.fathername,
                timelog.qualification,
                timelog.city,
                timelog.mobileno,
                timelog.address,
                timelog.status,
                auth_users.username,
                auth_users.last_login
            FROM timelog
            LEFT JOIN auth_users
                ON timelog.user_id = auth_users.user_id
            WHERE timelog.user_id = ?
            ORDER BY timelog.id DESC"
        );

        if (!$stmt) {
            sendJson([
                "error" => "History query failed",
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
    GET ONE USER
    /user.php?id=31
    */
    if (isset($_GET["id"])) {
        $id = getId();

        requireDescendantAccess(
            $connection,
            $id
        );

        $stmt = $connection->prepare(
            "SELECT
                users.id,
                users.parent_id,
                users.name,
                users.dob,
                users.fathername,
                users.qualification,
                users.city,
                users.mobileno,
                users.address,
                users.status,
                auth_users.username,
                auth_users.password_hash
             FROM users
             INNER JOIN auth_users
                ON users.id = auth_users.user_id
             WHERE users.id = ?"
        );

        if (!$stmt) {
            sendJson([
                "error" => "User query failed",
                "details" => $connection->error
            ], 500);
        }

        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            sendJson([
                "error" => "User query failed",
                "details" => $stmt->error
            ], 500);
        }

        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            sendJson([
                "error" => "User not found"
            ], 404);
        }

        $user["id"] = (int)$user["id"];

        $user["parent_id"] =
            $user["parent_id"] === null
            ? null
            : (int)$user["parent_id"];

        sendJson($user);
    }

    /*
    GET ALL USERS

    The response now contains:
      - users       -> paginated ALL users
      - allUsers    -> complete ALL users list
      - descendants -> IDs/data for users the current account may manage

    The frontend can therefore put descendants first while keeping every
    other account visible as view-only.
    */
    $currentUserId = requireLogin();

    requireActiveCurrentUser(
        $connection,
        $currentUserId
    );

    /*
    Record the login time once for this PHP session.
    */
    

    /*
    Re-read users after recording the timestamp so the current user's
    last_login value is included in the response.
    */
    $allUsers = getAllUsers($connection);

    $allDescendantIds = getDescendantIds(
        $connection,
        $currentUserId
    );

    $descendantIdMap = [];

    foreach ($allDescendantIds as $descendantId) {
        $descendantIdMap[(int)$descendantId] = true;
    }

    $allDescendants = [];

    foreach ($allUsers as $user) {
        if (isset($descendantIdMap[(int)$user["id"]])) {
            $allDescendants[] = $user;
        }
    }

    $directCount = 0;

    foreach ($allDescendants as $user) {
        if ((int)$user["parent_id"] === $currentUserId) {
            $directCount++;
        }
    }

    $totalDescendantCount = count($allDescendants);

    $indirectCount =
        $totalDescendantCount - $directCount;

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
    Pagination now applies to ALL users, because the frontend needs the
    complete global user list.
    */
    $total = count($allUsers);

    $totalPages = max(
        1,
        (int)ceil($total / $limit)
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $limit;

    $paginatedUsers = array_slice(
        $allUsers,
        $offset,
        $limit
    );

    sendJson([
        "users" => $paginatedUsers,
        "allUsers" => $allUsers,
        "descendants" => $allDescendants,
        "descendantIds" => $allDescendantIds,
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "totalPages" => $totalPages,
        "directCount" => $directCount,
        "indirectCount" => $indirectCount,
        "totalDescendantCount" => $totalDescendantCount,
        "currentUserId" => $currentUserId
    ]);
}

/*
|--------------------------------------------------------------------------
| POST - CREATE USER
|--------------------------------------------------------------------------
|
| Public POST -> root user (parent_id NULL)
| Logged-in POST -> child of logged-in user
|--------------------------------------------------------------------------
*/
if ($method === "POST") {

    $data = validateUser(
        getJsonBody()
    );

    $creatorId =
        isset($_SESSION["user_id"]) &&
        is_numeric($_SESSION["user_id"])
        ? (int)$_SESSION["user_id"]
        : null;

    if ($creatorId !== null) {
        requireActiveCurrentUser(
            $connection,
            $creatorId
        );
    }

    $connection->begin_transaction();

    try {

        /*
        Check username.
        */
        $stmt = $connection->prepare(
            "SELECT user_id
             FROM auth_users
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception(
                "Username check failed: "
                . $connection->error
            );
        }

        $stmt->bind_param(
            "s",
            $data["username"]
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Username check failed: "
                . $stmt->error
            );
        }

        $existing = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if ($existing) {
            throw new Exception(
                "USERNAME_EXISTS"
            );
        }

        /*
        Insert users.
        */
        if ($creatorId === null) {

            $stmt = $connection->prepare(
                "INSERT INTO users
                (
                    parent_id,
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
                    NULL, ?, ?, ?, ?, ?, ?, ?
                )"
            );

            if (!$stmt) {
                throw new Exception(
                    "User insert preparation failed: "
                    . $connection->error
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

        } else {

            $stmt = $connection->prepare(
                "INSERT INTO users
                (
                    parent_id,
                    name,
                    dob,
                    fathername,
                    qualification,
                    city,
                    mobileno,
                    address
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception(
                    "User insert preparation failed: "
                    . $connection->error
                );
            }

            $stmt->bind_param(
                "isssssss",
                $creatorId,
                $data["name"],
                $data["dob"],
                $data["fathername"],
                $data["qualification"],
                $data["city"],
                $data["mobileno"],
                $data["address"]
            );
        }

        if (!$stmt->execute()) {
            throw new Exception(
                "User creation failed: "
                . $stmt->error
            );
        }

        $newId = $connection->insert_id;
        $stmt->close();

        /*
        Plaintext password is intentionally kept
        to match the current auth.php system.
        */
        $stmt = $connection->prepare(
            "INSERT INTO auth_users
            (
                user_id,
                username,
                password_hash
            )
            VALUES
            (?, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception(
                "Auth user insert preparation failed: "
                . $connection->error
            );
        }

        $stmt->bind_param(
            "iss",
            $newId,
            $data["username"],
            $data["password"]
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Auth user creation failed: "
                . $stmt->error
            );
        }

        $stmt->close();

        $user = getUserSnapshot(
            $connection,
            $newId
        );

        createHistoryLog(
            $connection,
            $user,
            "CREATE"
        );

        $connection->commit();

        sendJson([
            "message" => "User created successfully",
            "id" => $newId,
            "parent_id" => $creatorId
        ], 201);

    } catch (Exception $e) {

        $connection->rollback();

        if ($e->getMessage() === "USERNAME_EXISTS") {
            sendJson([
                "error" => "Username already exists"
            ], 409);
        }

        sendJson([
            "error" => "User creation failed",
            "details" => $e->getMessage()
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| PUT - UPDATE DESCENDANT
|--------------------------------------------------------------------------
*/
if ($method === "PUT") {

    $id = getId();

    requireDescendantAccess(
        $connection,
        $id
    );

    $currentUserId =
        (int)$_SESSION["user_id"];

    requireActiveCurrentUser(
        $connection,
        $currentUserId
    );

    $data = validateUser(
        getJsonBody()
    );

    $connection->begin_transaction();

    try {

        $stmt = $connection->prepare(
            "SELECT
                id,
                parent_id,
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

        $stmt->bind_param("i", $id);

        if (!$stmt->execute()) {
            throw new Exception(
                "User lookup failed: "
                . $stmt->error
            );
        }

        $oldUser =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$oldUser) {
            throw new Exception("USER_NOT_FOUND");
        }

        if ($oldUser["status"] === "inactive") {
            throw new Exception("USER_INACTIVE");
        }

        /*
        Username must remain unique.
        */
        $stmt = $connection->prepare(
            "SELECT user_id
             FROM auth_users
             WHERE username = ?
               AND user_id <> ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception(
                "Username check failed: "
                . $connection->error
            );
        }

        $stmt->bind_param(
            "si",
            $data["username"],
            $id
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Username check failed: "
                . $stmt->error
            );
        }

        $existing = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if ($existing) {
            throw new Exception("USERNAME_EXISTS");
        }

        /*
        Update profile.
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
        Update auth information.
        */
        $stmt = $connection->prepare(
            "UPDATE auth_users SET
                username = ?,
                password_hash = ?
             WHERE user_id = ?"
        );

        if (!$stmt) {
            throw new Exception(
                "Auth update preparation failed"
            );
        }

        $stmt->bind_param(
            "ssi",
            $data["username"],
            $data["password"],
            $id
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Auth update failed: "
                . $stmt->error
            );
        }

        $stmt->close();

        $updatedUser = getUserSnapshot(
            $connection,
            $id
        );

        createHistoryLog(
            $connection,
            $updatedUser,
            "UPDATE"
        );

        $connection->commit();

        sendJson([
            "message" => "User updated successfully"
        ]);

    } catch (Exception $e) {

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

        if ($e->getMessage() === "USERNAME_EXISTS") {
            sendJson([
                "error" => "Username already exists"
            ], 409);
        }

        sendJson([
            "error" => "User update failed",
            "details" => $e->getMessage()
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| PATCH - SET STATUS
|--------------------------------------------------------------------------
*/
if ($method === "PATCH") {

    $id = getId();

    requireDescendantAccess(
        $connection,
        $id
    );

    $currentUserId =
        (int)$_SESSION["user_id"];

    requireActiveCurrentUser(
        $connection,
        $currentUserId
    );

    $data = getJsonBody();

    if (!isset($data["status"])) {
        sendJson([
            "error" => "Status is required"
        ], 400);
    }

    if (
        $data["status"] !== "active" &&
        $data["status"] !== "inactive"
    ) {
        sendJson([
            "error" => "Status must be active or inactive"
        ], 400);
    }

    $newStatus = $data["status"];

    $connection->begin_transaction();

    try {

        getUserSnapshot(
            $connection,
            $id
        );

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

        $updatedUser = getUserSnapshot(
            $connection,
            $id
        );

        createHistoryLog(
            $connection,
            $updatedUser,
            "ACTIVE_TOGGLE"
        );

        $connection->commit();

        sendJson([
            "message" => "Status changed successfully",
            "status" => $newStatus
        ]);

    } catch (Exception $e) {

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
| DELETE - TOGGLE STATUS
|--------------------------------------------------------------------------
|
| This does NOT delete a row.
| active -> inactive
| inactive -> active
|--------------------------------------------------------------------------
*/
if ($method === "DELETE") {

    $id = getId();

    requireDescendantAccess(
        $connection,
        $id
    );

    $currentUserId =
        (int)$_SESSION["user_id"];

    requireActiveCurrentUser(
        $connection,
        $currentUserId
    );

    $connection->begin_transaction();

    try {

        $user = getUserSnapshot(
            $connection,
            $id
        );

        $newStatus =
            $user["status"] === "active"
            ? "inactive"
            : "active";

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

        $updatedUser = getUserSnapshot(
            $connection,
            $id
        );

        createHistoryLog(
            $connection,
            $updatedUser,
            "ACTIVE_TOGGLE"
        );

        $connection->commit();

        sendJson([
            "message" => "Status changed successfully",
            "status" => $newStatus
        ]);

    } catch (Exception $e) {

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
    "error" => "Method not allowed"
], 405);

?>