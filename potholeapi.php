<?php
// Set headers to support Cross-Origin Resource Sharing (CORS) and JSON formatting
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS requests instantly
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// ... (Your headers)

// Database Connection
require_once __DIR__ . '/db_config.php';

$check_col = mysqli_query($con, "SHOW COLUMNS FROM team LIKE 'username'");
if ($check_col && mysqli_num_rows($check_col) == 0) {
    mysqli_query($con, "ALTER TABLE team ADD COLUMN username VARCHAR(255) NULL");
    mysqli_query($con, "UPDATE team SET username = email");
}

$check_col2 = mysqli_query($con, "SHOW COLUMNS FROM complaint LIKE 'assigned_date'");
if ($check_col2 && mysqli_num_rows($check_col2) == 0) {
    mysqli_query($con, "ALTER TABLE complaint ADD COLUMN assigned_date DATETIME NULL");
}

// PASTE IT HERE:
$protocol = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
    ($_SERVER['SERVER_PORT'] == 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
) ? "https://" : "http://";
$base = $protocol . $_SERVER['HTTP_HOST'] . "/uploads/";
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function getImageMimeType($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml'
    ];
    return $types[$ext] ?? 'application/octet-stream';
}

function resolveImagePath($value, $uploadDir) {
    if (empty($value)) {
        return null;
    }

    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^data:/i', $raw)) {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $raw)) {
        return null;
    }

    $normalized = str_replace('\\', '/', $raw);
    $normalized = ltrim($normalized, './');

    if (preg_match('#^uploads/#i', $normalized)) {
        return $uploadDir . basename($normalized);
    }

    if (preg_match('#^/uploads/#i', $normalized)) {
        return $uploadDir . basename($normalized);
    }

    if (strpos($normalized, '/') !== false) {
        return $uploadDir . basename($normalized);
    }

    return $uploadDir . $normalized;
}

function sendImageResponse($value, $uploadDir) {
    $resolvedPath = resolveImagePath($value, $uploadDir);
    if ($resolvedPath && is_file($resolvedPath)) {
        header('Content-Type: ' . getImageMimeType($resolvedPath));
        header('Cache-Control: public, max-age=3600');
        readfile($resolvedPath);
        return true;
    }

    return false;
}

// ... (Your Universal Parser)

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ... (Your switch statement)
}

// Base URL path helper for React Native Image resolution
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . "/uploads/";

// Universal Parser: Extract payload data regardless of Content-Type (JSON or form-urlencoded)
$rawInput = file_get_contents("php://input");
$inputData = json_decode($rawInput, true);
if (!empty($inputData)) {
    $_POST = $inputData;
}

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['tag']) && $_GET['tag'] === 'getComplaintImage') {
    $imageValue = '';
    if (!empty($_GET['file'])) {
        $imageValue = $_GET['file'];
    } elseif (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $query = "SELECT image, completedimage FROM complaint WHERE cid = $id LIMIT 1";
        $result = mysqli_query($con, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $imageValue = !empty($row['image']) ? $row['image'] : (!empty($row['completedimage']) ? $row['completedimage'] : '');
        }
    }

    if (!empty($imageValue)) {
        if (sendImageResponse($imageValue, $uploadDir)) {
            exit;
        }
    }

    http_response_code(404);
    echo 'Image not found';
    mysqli_close($con);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tag = isset($_POST['tag']) ? $_POST['tag'] : (isset($_POST['action']) ? $_POST['action'] : '');
    
    if ($tag != '') {
        // Auto-reassign logic: Check for complaints older than 10 days that are still in progress
        $expired_query = "SELECT cid, teamid FROM complaint WHERE status = 'In Progress' AND assigned_date < NOW() - INTERVAL 10 DAY";
        $expired_result = mysqli_query($con, $expired_query);
        if ($expired_result && mysqli_num_rows($expired_result) > 0) {
            while ($row = mysqli_fetch_assoc($expired_result)) {
                $cid = $row['cid'];
                $old_teamid = $row['teamid'];
                
                // Find a random different team
                $new_team_query = "SELECT id FROM team WHERE id != '$old_teamid' ORDER BY RAND() LIMIT 1";
                $new_team_result = mysqli_query($con, $new_team_query);
                if ($new_team_result && mysqli_num_rows($new_team_result) > 0) {
                    $new_team_row = mysqli_fetch_assoc($new_team_result);
                    $new_teamid = $new_team_row['id'];
                    
                    // Reassign
                    mysqli_query($con, "UPDATE complaint SET teamid = '$new_teamid', assigned_date = NOW(), admin_remarks = CONCAT(IFNULL(admin_remarks, ''), '\\nAuto-reassigned from team ', '$old_teamid', ' due to 10-day timeline expiration.') WHERE cid = '$cid'");
                }
            }
        }

        switch ($tag) {
            
            case "adminlogin":
                $username = isset($_POST['username']) ? mysqli_real_escape_string($con, trim($_POST['username'])) : '';
                $password = isset($_POST['password']) ? mysqli_real_escape_string($con, trim($_POST['password'])) : '';
                if (empty($username) || empty($password)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Admin credentials cannot be left blank."]);
                    mysqli_close($con);
                    exit;
                }
                $check_query = "SELECT * FROM admin WHERE username='$username'";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) == 0) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Not registered"]);
                } else {
                    $row = mysqli_fetch_assoc($check_result);
                    if ($row['password'] === $password) {
                        echo json_encode(["success" => true, "error" => 0, "message" => "Welcome to Admin Console"]);
                    } else {
                        echo json_encode(["success" => false, "error" => 1, "message" => "Incorrect password"]);
                    }
                }
                mysqli_close($con);
                break;

            case "teamlogin":
                $email = isset($_POST['email']) ? mysqli_real_escape_string($con, trim($_POST['email'])) : '';
                $password = isset($_POST['password']) ? mysqli_real_escape_string($con, trim($_POST['password'])) : '';
                if (empty($email) || empty($password)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Team credentials cannot be left blank."]);
                    mysqli_close($con);
                    exit;
                }
                $check_query = "SELECT * FROM team WHERE username='$email' OR email='$email'";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) == 0) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Not registered"]);
                } else {
                    $row = mysqli_fetch_assoc($check_result);
                    if ($row['password'] === $password) {
                        echo json_encode(["success" => true, "error" => 0, "message" => "Welcome Field Contractor"]);
                    } else {
                        echo json_encode(["success" => false, "error" => 1, "message" => "Incorrect password"]);
                    }
                }
                mysqli_close($con);
                break;

            case "register":
                $name     = isset($_POST['name']) ? mysqli_real_escape_string($con, trim($_POST['name'])) : '';
                $mobile   = isset($_POST['mobile']) ? mysqli_real_escape_string($con, trim($_POST['mobile'])) : '';
                $email    = isset($_POST['email']) ? mysqli_real_escape_string($con, trim($_POST['email'])) : '';
                $password = isset($_POST['password']) ? mysqli_real_escape_string($con, trim($_POST['password'])) : '';
                
                if (empty($name) || empty($mobile) || empty($email) || empty($password)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Missing core user details."]);
                    mysqli_close($con);
                    exit;
                }
                
                $check_query = "SELECT id FROM user WHERE email = '$email'";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) > 0) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Email already registered!"]);
                    mysqli_close($con);
                    exit;
                }
                
                $query = "INSERT INTO user (name, mobile, email, password) VALUES ('$name', '$mobile', '$email', '$password')";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Registered successfully!", "id" => mysqli_insert_id($con), "name" => $name, "mobile" => $mobile]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Database schema insertion error."]);
                }
                mysqli_close($con);        
                break;

            case "login":
                $mobile   = isset($_POST['mobile']) ? mysqli_real_escape_string($con, trim($_POST['mobile'])) : '';
                $password = isset($_POST['password']) ? mysqli_real_escape_string($con, trim($_POST['password'])) : '';
                if (empty($mobile) || empty($password)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Login inputs cannot be left blank."]);
                    mysqli_close($con);
                    exit;
                }
                $check_query = "SELECT * FROM user WHERE mobile = '$mobile'";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) == 0) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Not registered"]);
                } else {
                    $row = mysqli_fetch_assoc($check_result);
                    if ($row['password'] === $password) {
                        $row["error"] = 0;
                        $row["success"] = true;
                        $row["message"] = "Welcome";
                        echo json_encode($row);
                    } else {
                        echo json_encode(["success" => false, "error" => 1, "message" => "Incorrect password"]);
                    }
                }
                mysqli_close($con);
                break;

            case "getUserByMobile":
                $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
                if (empty($mobile)) {
                    $jsonInput = json_decode(file_get_contents('php://input'), true);
                    $mobile = isset($jsonInput['mobile']) ? trim($jsonInput['mobile']) : '';
                }
                $mobile = mysqli_real_escape_string($con, $mobile);
                if (empty($mobile)) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Mobile string is empty."]);
                    mysqli_close($con);
                    exit;
                }
                $query = "SELECT id, name, mobile, email FROM user WHERE mobile = '$mobile'";
                $result = mysqli_query($con, $query);
                if ($result && mysqli_num_rows($result) > 0) {
                    $user = mysqli_fetch_assoc($result);
                    echo json_encode(["success" => true, "error" => 0, "id" => $user['id'], "name" => $user['name'], "email" => $user['email'], "mobile" => $user['mobile']]);
                } else {
                    echo json_encode(["success" => false, "error" => 1, "message" => "No user found matching phone: " . $mobile]);
                }
                mysqli_close($con);
                break;

            case "updateUserProfile":
                $mobile = isset($_POST['mobile']) ? mysqli_real_escape_string($con, trim($_POST['mobile'])) : '';
                $name = isset($_POST['name']) ? mysqli_real_escape_string($con, trim($_POST['name'])) : '';
                $email = isset($_POST['email']) ? mysqli_real_escape_string($con, trim($_POST['email'])) : '';
                if (empty($mobile) || empty($name)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Name and mobile number cannot be blank"]);
                    mysqli_close($con);
                    exit;
                }
                $query = "UPDATE user SET name = '$name', email = '$email' WHERE mobile = '$mobile'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Profile updated successfully!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Database update failed: " . mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            case "addTeam":
                $name        = isset($_POST['team_name']) ? mysqli_real_escape_string($con, $_POST['team_name']) : ''; 
                $leader_name = isset($_POST['leader_name']) ? mysqli_real_escape_string($con, $_POST['leader_name']) : ''; 
                $mobile      = isset($_POST['phone']) ? mysqli_real_escape_string($con, $_POST['phone']) : ''; 
                $username    = isset($_POST['username']) ? mysqli_real_escape_string($con, $_POST['username']) : ''; 
                $email       = isset($_POST['email']) ? mysqli_real_escape_string($con, $_POST['email']) : ''; 
                $password    = isset($_POST['password']) ? mysqli_real_escape_string($con, $_POST['password']) : '';
                
                $check_query = "SELECT id FROM team WHERE username = '$username'";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) > 0) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Username already taken!"]);
                    mysqli_close($con);
                    exit;
                }
                $query = "INSERT INTO team (name, mobile, email, username, address, password) VALUES ('$name', '$mobile', '$email', '$username', '$leader_name', '$password')";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Team added successfully!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Failed to add team!"]);
                }
                mysqli_close($con);
                break;
 
            case "getTeam":
            case "getTeams":
                $sql = "SELECT id, name AS team_name, address AS leader_name, mobile AS phone, email, username, address, password, 'Available' AS status FROM team";
                $result = mysqli_query($con, $sql);
                $team = [];
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $team[] = $row;
                    }
                }
                echo json_encode(["error" => 0, "success" => true, "team" => $team, "data" => $team]);
                mysqli_close($con);
                break;
 
            case "getTeamByEmail":
                $email = isset($_POST['email']) ? mysqli_real_escape_string($con, $_POST['email']) : '';
                $query = "SELECT * FROM team WHERE username = '$email' OR email = '$email'";
                $result = mysqli_query($con, $query);
                if ($result) {
                    $row = mysqli_fetch_assoc($result);
                    if ($row) {
                        $row["success"] = true; $row["error"] = 0;
                        echo json_encode($row);
                    } else {
                        echo json_encode(['success' => false, 'error' => 1, 'message' => 'No team found']);
                    }
                }
                mysqli_close($con);
                break;

            case "getUsers":
                $sql = "SELECT id, name, mobile, email FROM user"; 
                $result = mysqli_query($con, $sql);
                $users = [];
                while ($row = mysqli_fetch_assoc($result)) { $users[] = $row; }
                echo json_encode($users);
                mysqli_close($con);
                break;

            case "deleteUser":
                $id = isset($_POST['id']) ? mysqli_real_escape_string($con, $_POST['id']) : '';
                $query = "DELETE FROM user WHERE id = '$id'";
                echo json_encode(["success" => mysqli_query($con, $query), "error" => 0]);
                mysqli_close($con);
                break;

            case "forgotPasswordUser":
                $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
                if (empty($mobile) || empty($email) || empty($new_password)) {
                    $jsonInput = json_decode(file_get_contents('php://input'), true);
                    $mobile = isset($jsonInput['mobile']) ? trim($jsonInput['mobile']) : '';
                    $email = isset($jsonInput['email']) ? trim($jsonInput['email']) : '';
                    $new_password = isset($jsonInput['new_password']) ? trim($jsonInput['new_password']) : '';
                }
                $mobile = mysqli_real_escape_string($con, $mobile);
                $email = mysqli_real_escape_string($con, $email);
                $new_password = mysqli_real_escape_string($con, $new_password);

                if (empty($mobile) || empty($email) || empty($new_password)) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "All fields are required."]);
                    mysqli_close($con);
                    exit;
                }

                $check_query = "SELECT id FROM user WHERE mobile = '$mobile' AND email = '$email' LIMIT 1";
                $check_result = mysqli_query($con, $check_query);
                if (mysqli_num_rows($check_result) == 0) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Account not found with matching mobile and email."]);
                } else {
                    $update_query = "UPDATE user SET password = '$new_password' WHERE mobile = '$mobile'";
                    if (mysqli_query($con, $update_query)) {
                        echo json_encode(["success" => true, "error" => 0, "message" => "Password updated successfully."]);
                    } else {
                        echo json_encode(["success" => false, "error" => 1, "message" => "Failed to reset password."]);
                    }
                }
                mysqli_close($con);
                break;

            case "forgotPasswordAdmin":
                $username = isset($_POST['username']) ? trim($_POST['username']) : '';
                $security_code = isset($_POST['security_code']) ? trim($_POST['security_code']) : '';
                $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
                if (empty($username) || empty($security_code) || empty($new_password)) {
                    $jsonInput = json_decode(file_get_contents('php://input'), true);
                    $username = isset($jsonInput['username']) ? trim($jsonInput['username']) : '';
                    $security_code = isset($jsonInput['security_code']) ? trim($jsonInput['security_code']) : '';
                    $new_password = isset($jsonInput['new_password']) ? trim($jsonInput['new_password']) : '';
                }
                $username = mysqli_real_escape_string($con, $username);
                $security_code = mysqli_real_escape_string($con, $security_code);
                $new_password = mysqli_real_escape_string($con, $new_password);

                if (empty($username) || empty($security_code) || empty($new_password)) {
                    echo json_encode(["success" => false, "error" => 1, "message" => "All fields are required."]);
                    mysqli_close($con);
                    exit;
                }

                if ($security_code !== 'Suni@Reset' && $security_code !== 'ROADCARE-ADMIN-RESET') {
                    echo json_encode(["success" => false, "error" => 1, "message" => "Invalid security recovery code."]);
                } else {
                    $check_query = "SELECT id FROM admin WHERE username = '$username' LIMIT 1";
                    $check_result = mysqli_query($con, $check_query);
                    if (mysqli_num_rows($check_result) == 0) {
                        echo json_encode(["success" => false, "error" => 1, "message" => "Admin user not found."]);
                    } else {
                        $update_query = "UPDATE admin SET password = '$new_password' WHERE username = '$username'";
                        if (mysqli_query($con, $update_query)) {
                            echo json_encode(["success" => true, "error" => 0, "message" => "Admin password reset successful."]);
                        } else {
                            echo json_encode(["success" => false, "error" => 1, "message" => "Failed to reset password."]);
                        }
                    }
                }
                mysqli_close($con);
                break;

            case "getUnassignedPotholes":
                $sql = "SELECT cid AS id, latitude, longitude, description FROM complaint WHERE status = 'Pending' AND (teamid IS NULL OR teamid = '' OR teamid = 0)";
                $result = mysqli_query($con, $sql);
                $potholes = [];
                while ($row = mysqli_fetch_assoc($result)) { $potholes[] = $row; }
                echo json_encode($potholes);
                mysqli_close($con);
                break;

            case "assignTask":
                $cid = isset($_POST['pothole_id']) ? mysqli_real_escape_string($con, $_POST['pothole_id']) : '';
                $teamid = isset($_POST['team_id']) ? mysqli_real_escape_string($con, $_POST['team_id']) : '';
                $query = "UPDATE complaint SET teamid = '$teamid', status = 'In Progress', assigned_date = NOW() WHERE cid = '$cid'";
                echo json_encode(["success" => mysqli_query($con, $query), "status" => "success", "error" => 0]);
                mysqli_close($con);
                break;

            case "approveTask":
                $cid = isset($_POST['cid']) ? mysqli_real_escape_string($con, $_POST['cid']) : '';
                $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($con, $_POST['remarks']) : '';
                if (empty($cid)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Task ID is required"]);
                    mysqli_close($con);
                    exit;
                }
                $query = "UPDATE complaint SET status = 'Completed', admin_remarks = '$remarks' WHERE cid = '$cid'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Task approved and marked as Completed!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Failed to update task status: " . mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            case "rejectTask":
                $cid = isset($_POST['cid']) ? mysqli_real_escape_string($con, $_POST['cid']) : '';
                $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($con, $_POST['remarks']) : '';
                if (empty($cid)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Task ID is required"]);
                    mysqli_close($con);
                    exit;
                }
                // Set status back to 'In Progress' so they can submit again
                $query = "UPDATE complaint SET status = 'In Progress', admin_remarks = '$remarks' WHERE cid = '$cid'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Task rejected and reverted back to In Progress!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Failed to update task status: " . mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            // 12. VIEW STATUS PAGE RESOLVER
            // --- REPLACE THESE CASES IN YOUR SWITCH STATEMENT ---

case "getTaskStatus":
    $sql = "SELECT c.cid AS id, c.description, c.latitude, c.longitude, c.datetime AS date_added, 
                   c.status, c.image, c.completedimage, c.completed_latitude, c.completed_longitude,
                   c.remarks AS team_remarks, c.admin_remarks, c.completeddatetime,
                   IFNULL(u.name, 'No Reporter') AS reporter_name, 
                   IFNULL(u.mobile, 'N/A') AS reporter_phone,
                   IFNULL(t.name, 'Unassigned') AS team_name,
                   IFNULL(t.address, '') AS leader_name,
                   IFNULL(t.mobile, '') AS leader_contact 
            FROM complaint c 
            LEFT JOIN user u ON c.uid = u.id
            LEFT JOIN team t ON c.teamid = t.id
            ORDER BY c.datetime DESC";
    
    $result = mysqli_query($con, $sql);
    $tasks = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['image'])) {
                if (!preg_match('/^data:/i', $row['image']) && !preg_match('/^https?:\/\//i', $row['image'])) {
                    $row['image'] = $base . basename($row['image']);
                }
            } else {
                $row['image'] = "";
            }
            if (!empty($row['completedimage'])) {
                if (!preg_match('/^data:/i', $row['completedimage']) && !preg_match('/^https?:\/\//i', $row['completedimage'])) {
                    $row['completedimage'] = $base . basename($row['completedimage']);
                }
            } else {
                $row['completedimage'] = "";
            }
            $tasks[] = $row;
        }
    }
    echo json_encode(["error" => 0, "success" => true, "data" => $tasks]);
    mysqli_close($con);
    break;
case "getComplaints":
    $uid = isset($_POST['uid']) ? mysqli_real_escape_string($con, trim($_POST['uid'])) : '';
    if (!empty($uid)) {
        $query = "SELECT cid AS id, description, status, image, completedimage, datetime AS date, latitude, longitude, completed_latitude, completed_longitude, remarks, admin_remarks, uid, completeddatetime FROM complaint WHERE uid = '$uid' ORDER BY cid DESC";
    } else {
        $query = "SELECT cid AS id, description, status, image, completedimage, datetime AS date, latitude, longitude, completed_latitude, completed_longitude, remarks, admin_remarks, uid, completeddatetime FROM complaint ORDER BY cid DESC";
    }
    $result = mysqli_query($con, $query);
    $incidents = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['image'])) {
                if (!preg_match('/^data:/i', $row['image']) && !preg_match('/^https?:\/\//i', $row['image'])) {
                    $row['image'] = $base . basename($row['image']);
                }
            } else {
                $row['image'] = "";
            }
            if (!empty($row['completedimage'])) {
                if (!preg_match('/^data:/i', $row['completedimage']) && !preg_match('/^https?:\/\//i', $row['completedimage'])) {
                    $row['completedimage'] = $base . basename($row['completedimage']);
                }
            } else {
                $row['completedimage'] = "";
            }
            $incidents[] = $row;
        }
        echo json_encode(["success" => true, "incidents" => $incidents]);
    } else {
        echo json_encode(["success" => false, "incidents" => []]);
    }
    mysqli_close($con);
    break;

case "getComplaintsByUser":
    $mobile = isset($_POST['mobile']) ? mysqli_real_escape_string($con, trim($_POST['mobile'])) : '';
    if (empty($mobile)) {
        echo json_encode(["success" => false, "error" => 1, "message" => "Mobile number required", "incidents" => []]);
        mysqli_close($con);
        exit;
    }
    $query = "SELECT c.cid AS id, c.description, c.status, c.image, c.completedimage, c.datetime AS date, 
                     c.latitude, c.longitude, c.completed_latitude, c.completed_longitude, 
                     c.remarks, c.admin_remarks, c.uid, c.completeddatetime 
              FROM complaint c
              INNER JOIN user u ON c.uid = u.id
              WHERE u.mobile = '$mobile' OR u.email = '$mobile'
              ORDER BY c.cid DESC";
    $result = mysqli_query($con, $query);
    $incidents = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['image'])) {
                if (!preg_match('/^data:/i', $row['image']) && !preg_match('/^https?:\/\//i', $row['image'])) {
                    $row['image'] = $base . basename($row['image']);
                }
            } else {
                $row['image'] = "";
            }
            if (!empty($row['completedimage'])) {
                if (!preg_match('/^data:/i', $row['completedimage']) && !preg_match('/^https?:\/\//i', $row['completedimage'])) {
                    $row['completedimage'] = $base . basename($row['completedimage']);
                }
            } else {
                $row['completedimage'] = "";
            }
            $incidents[] = $row;
        }
        echo json_encode(["success" => true, "incidents" => $incidents, "complaints" => $incidents]);
    } else {
        echo json_encode(["success" => false, "incidents" => [], "complaints" => []]);
    }
    mysqli_close($con);
    break;

            case "postcomplaint":
                $imageName = "";
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $imageName = "img_" . time() . ".jpg";
                    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) { $imageName = ""; }
                } 
                if (empty($imageName)) {
                    $post_image = isset($_POST['image']) ? $_POST['image'] : '';
                    if (!empty($post_image)) {
                        if (preg_match('/^data:(image|video)\/(\w+);base64,/', $post_image, $matches)) {
                            $post_image = substr($post_image, strpos($post_image, ',') + 1);
                            $type = strtolower($matches[2]);
                            if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                                $decoded = base64_decode($post_image);
                                if ($decoded !== false) {
                                    $prefix = ($matches[1] == 'video') ? "vid_" : "img_";
                                    $imageName = $prefix . time() . "_" . uniqid() . "." . $type;
                                    file_put_contents($uploadDir . $imageName, $decoded);
                                }
                            }
                        } else {
                            $imageName = mysqli_real_escape_string($con, $post_image);
                        }
                    }
                }
                $description = isset($_POST['description']) ? mysqli_real_escape_string($con, $_POST['description']) : '';
                $uid         = isset($_POST['uid']) ? mysqli_real_escape_string($con, $_POST['uid']) : '';
                $latitude    = isset($_POST['latitude']) ? mysqli_real_escape_string($con, $_POST['latitude']) : '';
                $longitude   = isset($_POST['longitude']) ? mysqli_real_escape_string($con, $_POST['longitude']) : '';
                $datetime    = isset($_POST['datetime']) ? mysqli_real_escape_string($con, $_POST['datetime']) : '';
                
                // STRICT MOCK AI VALIDATION (Since no Vision API key is provided)
                $status = 'Pending';
                $admin_remarks = '';
                
                $desc_lower = strtolower($description);
                $is_fake = false;
                $fake_reason = "";

                // 1. Check for real On-Device AI Vision flag
                $is_ai_fake_flag = isset($_POST['is_ai_fake']) ? $_POST['is_ai_fake'] : "0";
                $ai_detected_label = isset($_POST['ai_detected_label']) ? mysqli_real_escape_string($con, $_POST['ai_detected_label']) : "Unknown Object";
                
                if ($is_ai_fake_flag === "1") {
                    $is_fake = true;
                    $fake_reason = "Invalid Image Uploaded";
                }

                // 2. Check for suspicious keywords in text (laptop, screen, test, fake, etc)
                if (!$is_fake) {
                    $suspicious_words = ['fake', 'test', 'laptop', 'screen', 'monitor', 'computer', 'display', 'keyboard', 'phone'];
                    foreach ($suspicious_words as $word) {
                        if (strpos($desc_lower, $word) !== false) {
                            $is_fake = true;
                            $fake_reason = "Fake report detected.";
                            break;
                        }
                    }
                }

                // 2. Check for required valid keywords (road, pothole, damage, etc)
                if (!$is_fake) {
                    $valid_words = ['road', 'pothole', 'damage', 'crack', 'street', 'broken', 'hole', 'asphalt'];
                    $has_valid = false;
                    foreach ($valid_words as $word) {
                        if (strpos($desc_lower, $word) !== false) {
                            $has_valid = true;
                            break;
                        }
                    }
                    if (!$has_valid) {
                        $is_fake = true;
                        $fake_reason = "No valid road damage found.";
                    }
                }
                // (Description length check removed as requested)

                if ($is_fake) {
                    $status = 'Rejected';
                    $admin_remarks = 'Auto-Rejected: ' . $fake_reason;
                }
                
                $query = "INSERT INTO complaint (image, description, uid, latitude, longitude, datetime, status, admin_remarks) 
                          VALUES ('$imageName', '$description', '$uid', '$latitude', '$longitude', '$datetime', '$status', '$admin_remarks')";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Complaint posted successfully!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Database error"]);
                }
                mysqli_close($con);        
                break;

            case "updateComplaint":
                $cid = isset($_POST['cid']) ? mysqli_real_escape_string($con, $_POST['cid']) : '';
                $status = isset($_POST['status']) ? mysqli_real_escape_string($con, $_POST['status']) : '';
                $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($con, $_POST['remarks']) : '';
                $completeddatetime = isset($_POST['completeddatetime']) ? mysqli_real_escape_string($con, $_POST['completeddatetime']) : '';
                $lat = isset($_POST['completed_latitude']) ? mysqli_real_escape_string($con, $_POST['completed_latitude']) : '';
                $lng = isset($_POST['completed_longitude']) ? mysqli_real_escape_string($con, $_POST['completed_longitude']) : '';
                
                $completedimage = "";
                if (isset($_FILES['completedimage']) && $_FILES['completedimage']['error'] === UPLOAD_ERR_OK) {
                    $completedimage = "resolved_" . time() . ".jpg";
                    move_uploaded_file($_FILES['completedimage']['tmp_name'], $uploadDir . $completedimage);
                }
                if (empty($completedimage)) {
                    $completedimage = isset($_POST['completedimage']) ? mysqli_real_escape_string($con, $_POST['completedimage']) : '';
                }
                
                $query = "UPDATE complaint SET status = '$status', remarks = '$remarks', completedimage = '$completedimage', completeddatetime = '$completeddatetime', completed_latitude = '$lat', completed_longitude = '$lng' WHERE cid = '$cid'";
                echo json_encode(["error" => mysqli_query($con, $query) ? 0 : 1, "success" => true]);
                mysqli_close($con);
                break;

            case "updateTaskCompletion":
                $cid = isset($_POST['cid']) ? mysqli_real_escape_string($con, $_POST['cid']) : '';
                $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($con, $_POST['remarks']) : '';
                $lat = isset($_POST['completed_latitude']) ? mysqli_real_escape_string($con, $_POST['completed_latitude']) : '';
                $lng = isset($_POST['completed_longitude']) ? mysqli_real_escape_string($con, $_POST['completed_longitude']) : '';
                
                $completed_image = isset($_POST['completed_image']) ? $_POST['completed_image'] : '';
                $imageName = "";
                if (!empty($completed_image)) {
                    if (preg_match('/^data:(image|video)\/(\w+);base64,/', $completed_image, $matches)) {
                        $completed_image = substr($completed_image, strpos($completed_image, ',') + 1);
                        $type = strtolower($matches[2]);
                        if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'])) {
                            $decoded = base64_decode($completed_image);
                            if ($decoded !== false) {
                                $prefix = ($matches[1] == 'video') ? "vid_resolved_" : "resolved_";
                                $imageName = $prefix . time() . "_" . uniqid() . "." . $type;
                                file_put_contents($uploadDir . $imageName, $decoded);
                            }
                        }
                    } else {
                        $imageName = mysqli_real_escape_string($con, $completed_image);
                    }
                }
                
                $completeddatetime = date("Y-m-d H:i:s");
                $query = "UPDATE complaint SET status = 'Pending Approval', remarks = '$remarks', completedimage = '$imageName', completeddatetime = '$completeddatetime', completed_latitude = '$lat', completed_longitude = '$lng' WHERE cid = '$cid'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Task updated successfully!"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Database update failed: " . mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            // 15. PUBLIC COMPLAINTS PAGE RESOLVER
            case "getComplaints":
    $uid = isset($_POST['uid']) ? mysqli_real_escape_string($con, trim($_POST['uid'])) : '';
    if (!empty($uid)) {
        $query = "SELECT cid AS id, description, status, image, completedimage, remarks, admin_remarks, datetime AS date, uid, completeddatetime FROM complaint WHERE uid = '$uid' ORDER BY cid DESC";
    } else {
        $query = "SELECT cid AS id, description, status, image, completedimage, remarks, admin_remarks, datetime AS date, uid, completeddatetime FROM complaint ORDER BY cid DESC";
    }
    $result = mysqli_query($con, $query);
    $incidents = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['image'])) {
                if (!preg_match('/^data:/i', $row['image']) && !preg_match('/^https?:\/\//i', $row['image'])) {
                    $row['image'] = $base . basename($row['image']);
                }
            } else {
                $row['image'] = "";
            }
            if (!empty($row['completedimage'])) {
                if (!preg_match('/^data:/i', $row['completedimage']) && !preg_match('/^https?:\/\//i', $row['completedimage'])) {
                    $row['completedimage'] = $base . basename($row['completedimage']);
                }
            } else {
                $row['completedimage'] = "";
            }
            $incidents[] = $row;
        }
        echo json_encode(["success" => true, "incidents" => $incidents]);
    } else {
        echo json_encode(["success" => false, "incidents" => []]);
    }
    mysqli_close($con);
    break;

            case "getComplaintsByTeam":
                $email = isset($_POST['email']) ? mysqli_real_escape_string($con, trim($_POST['email'])) : '';
                $sql = "SELECT c.cid, c.image, c.description, c.latitude, c.longitude, c.datetime, c.status, 
                               IFNULL(u.name, 'No Reporter') AS name, IFNULL(u.mobile, 'N/A') AS mobile, IFNULL(u.email, 'N/A') AS email,
                               c.remarks, c.admin_remarks, c.completedimage, c.completeddatetime, c.teamid, c.assigned_date 
                        FROM complaint c 
                        LEFT JOIN user u ON c.uid = u.id 
                        LEFT JOIN team t ON c.teamid = t.id
                        WHERE t.username = '$email' OR t.email = '$email' 
                        ORDER BY c.datetime DESC";
                $result = mysqli_query($con, $sql);
                $complaints = [];
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        if (!empty($row['image'])) {
                            if (!preg_match('/^data:/i', $row['image']) && !preg_match('/^https?:\/\//i', $row['image'])) {
                                $row['image'] = $base . basename($row['image']);
                            }
                        }
                        if (!empty($row['completedimage'])) {
                            if (!preg_match('/^data:/i', $row['completedimage']) && !preg_match('/^https?:\/\//i', $row['completedimage'])) {
                                $row['completedimage'] = $base . basename($row['completedimage']);
                            }
                        }
                        $complaints[] = $row;
                    }
                }
                echo json_encode(["error" => 0, "success" => true, "complaints" => $complaints]);
                mysqli_close($con);
                break;

            case "motiondata":
                $uid       = isset($_POST['uid']) ? mysqli_real_escape_string($con, $_POST['uid']) : '';
                $accel_x   = isset($_POST['accel_x']) ? mysqli_real_escape_string($con, $_POST['accel_x']) : '';
                $accel_y   = isset($_POST['accel_y']) ? mysqli_real_escape_string($con, $_POST['accel_y']) : '';
                $accel_z   = isset($_POST['accel_z']) ? mysqli_real_escape_string($con, $_POST['accel_z']) : '';
                $latitude  = isset($_POST['latitude']) ? mysqli_real_escape_string($con, $_POST['latitude']) : '';
                $longitude = isset($_POST['longitude']) ? mysqli_real_escape_string($con, $_POST['longitude']) : '';
                $datetime  = isset($_POST['datetime']) ? mysqli_real_escape_string($con, $_POST['datetime']) : '';
                $query = "INSERT INTO motion_data (uid, accel_x, accel_y, accel_z, latitude, longitude, datetime) VALUES ('$uid', '$accel_x', '$accel_y', '$accel_z', '$latitude', '$longitude', '$datetime')";
                echo json_encode(["error" => mysqli_query($con, $query) ? 0 : 1, "success" => true]);
                mysqli_close($con);
                break;

            case "sendSupportMessage":
                $user_mobile = isset($_POST['user_mobile']) ? mysqli_real_escape_string($con, trim($_POST['user_mobile'])) : '';
                $message = isset($_POST['message']) ? mysqli_real_escape_string($con, trim($_POST['message'])) : '';
                if (empty($user_mobile) || empty($message)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Fields cannot be blank"]);
                    mysqli_close($con);
                    exit;
                }
                
                $image = isset($_POST['image']) ? $_POST['image'] : '';
                $imageName = "";
                if (!empty($image)) {
                    if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                        $image = substr($image, strpos($image, ',') + 1);
                        $type = strtolower($type[1]);
                        if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            $decoded = base64_decode($image);
                            if ($decoded !== false) {
                                $imageName = "support_" . time() . "_" . uniqid() . "." . $type;
                                file_put_contents($uploadDir . $imageName, $decoded);
                            }
                        }
                    } else {
                        $imageName = mysqli_real_escape_string($con, $image);
                    }
                }

                $query = "INSERT INTO support_message (user_mobile, message, media_url) VALUES ('$user_mobile', '$message', " . (!empty($imageName) ? "'$imageName'" : "NULL") . ")";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Message sent successfully"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            case "getSupportMessages":
                $user_mobile = isset($_POST['user_mobile']) ? mysqli_real_escape_string($con, trim($_POST['user_mobile'])) : '';
                if (!empty($user_mobile)) {
                    $query = "SELECT * FROM support_message WHERE user_mobile = '$user_mobile' ORDER BY created_at ASC";
                } else {
                    $query = "SELECT s.*, 
                                     COALESCE(u.name, t.name, 'Citizen/Contractor') AS user_name,
                                     CASE 
                                         WHEN u.id IS NOT NULL THEN 'User'
                                         WHEN t.id IS NOT NULL THEN 'Team'
                                         ELSE 'User'
                                     END AS sender_type
                              FROM support_message s 
                              LEFT JOIN user u ON s.user_mobile = u.mobile 
                              LEFT JOIN team t ON s.user_mobile = t.mobile 
                              ORDER BY s.created_at DESC";
                }
                $result = mysqli_query($con, $query);
                $messages = [];
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        if (!empty($row['media_url'])) {
                            if (!preg_match('/^data:/i', $row['media_url']) && !preg_match('/^https?:\/\//i', $row['media_url'])) {
                                $row['media_url'] = $base . basename($row['media_url']);
                            }
                        }
                        $messages[] = $row;
                    }
                }
                echo json_encode(["error" => 0, "success" => true, "messages" => $messages]);
                mysqli_close($con);
                break;

            case "replySupportMessage":
                $id = isset($_POST['id']) ? mysqli_real_escape_string($con, trim($_POST['id'])) : '';
                $reply = isset($_POST['reply']) ? mysqli_real_escape_string($con, trim($_POST['reply'])) : '';
                if (empty($id) || empty($reply)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Fields cannot be blank"]);
                    mysqli_close($con);
                    exit;
                }
                $time = date('Y-m-d H:i:s');
                $reply_with_time = $reply . '|||' . $time;
                $query = "UPDATE support_message SET reply = '$reply_with_time' WHERE id = '$id'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Replied successfully"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            case "sendAdminMessage":
                $user_mobile = isset($_POST['user_mobile']) ? mysqli_real_escape_string($con, trim($_POST['user_mobile'])) : '';
                $message = isset($_POST['message']) ? mysqli_real_escape_string($con, trim($_POST['message'])) : '';
                if (empty($user_mobile) || empty($message)) {
                    echo json_encode(["error" => 1, "success" => false, "message" => "Fields cannot be blank"]);
                    mysqli_close($con);
                    exit;
                }
                
                // For admin initiated messages, we leave 'message' empty (so user didn't say it)
                // and we put the admin's text in 'reply' with the timestamp format expected.
                $time = date('Y-m-d H:i:s');
                $reply_with_time = $message . '|||' . $time;
                
                $query = "INSERT INTO support_message (user_mobile, message, reply) VALUES ('$user_mobile', '', '$reply_with_time')";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["error" => 0, "success" => true, "message" => "Message sent successfully"]);
                } else {
                    echo json_encode(["error" => 1, "success" => false, "message" => mysqli_error($con)]);
                }
                mysqli_close($con);
                break;

            case "deleteTeam":
                $id = isset($_POST['id']) ? mysqli_real_escape_string($con, $_POST['id']) : '';
                // Clear any references in the complaints table to prevent foreign key errors
                mysqli_query($con, "UPDATE complaint SET teamid = NULL, status = 'Pending' WHERE teamid = '$id'");
                $query = "DELETE FROM team WHERE id = '$id'";
                if (mysqli_query($con, $query)) {
                    echo json_encode(["status" => "success", "success" => true, "error" => 0]);
                } else {
                    echo json_encode(["status" => "error", "success" => false, "error" => 1, "message" => mysqli_error($con)]);
                }
                mysqli_close($con);
                break;
                
            default:
                echo json_encode(["error" => 1, "success" => false, "message" => "Invalid tag"]);
                mysqli_close($con);
                break;
        }
    }
}
?>