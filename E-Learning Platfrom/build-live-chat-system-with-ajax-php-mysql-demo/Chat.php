<?php
class Chat {
    private $host  = 'localhost';
    private $user  = 'root';
    private $password = "";
    private $database  = "phpzag_demo";      
    private $chatTable = 'chat';
    private $chatUsersTable = 'chat_users';
    private $chatLoginDetailsTable = 'chat_login_details';
    private $dbConnect = false;

    public function __construct() {
        if (!$this->dbConnect) {
            $this->dbConnect = new mysqli($this->host, $this->user, $this->password, $this->database);
            if ($this->dbConnect->connect_error) {
                die("Connection failed: " . $this->dbConnect->connect_error);
            }
        }
    }

    private function getData($sqlQuery, $params = [], $types = '') {
        $stmt = $this->dbConnect->prepare($sqlQuery);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }

    private function getNumRows($sqlQuery, $params = [], $types = '') {
        $stmt = $this->dbConnect->prepare($sqlQuery);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $stmt->store_result();
        $numRows = $stmt->num_rows;
        $stmt->close();
        return $numRows;
    }

    public function loginUsers($username, $password) {
        $sqlQuery = "SELECT user_id, username FROM $this->chatUsersTable WHERE username = ? AND password = ?";
        return $this->getData($sqlQuery, [$username, $password], 'ss');
    }

    public function chatUsers($userId) {
        $sqlQuery = "SELECT * FROM $this->chatUsersTable WHERE user_id != ?";
        return $this->getData($sqlQuery, [$userId], 'i');
    }

    public function getUserDetails($userId) {
        $sqlQuery = "SELECT * FROM $this->chatUsersTable WHERE user_id = ?";
        return $this->getData($sqlQuery, [$userId], 'i');
    }

    public function getUserAvatar($userId) {
        $sqlQuery = "SELECT avatar FROM $this->chatUsersTable WHERE user_id = ?";
        $userResult = $this->getData($sqlQuery, [$userId], 'i');
        return $userResult[0]['avatar'] ?? '';
    }

    public function updateUserOnline($userId, $online) {
        $sqlUserUpdate = "UPDATE $this->chatUsersTable SET online = ? WHERE user_id = ?";
        $stmt = $this->dbConnect->prepare($sqlUserUpdate);
        $stmt->bind_param('si', $online, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function insertChat($receiverUserId, $userId, $chatMessage) {
        $sqlInsert = "INSERT INTO $this->chatTable (receiver_user_id, sender_user_id, message, status) VALUES (?, ?, ?, '1')";
        $stmt = $this->dbConnect->prepare($sqlInsert);
        $stmt->bind_param('iis', $receiverUserId, $userId, $chatMessage);
        $stmt->execute();
        $stmt->close();

        $conversation = $this->getUserChat($userId, $receiverUserId);
        $data = ["conversation" => $conversation];
        echo json_encode($data);
    }

    public function getUserChat($fromUserId, $toUserId) {
        $fromUserAvatar = $this->getUserAvatar($fromUserId);
        $toUserAvatar = $this->getUserAvatar($toUserId);
        $sqlQuery = "SELECT * FROM $this->chatTable WHERE (sender_user_id = ? AND receiver_user_id = ?) OR (sender_user_id = ? AND receiver_user_id = ?) ORDER BY timestamp ASC";
        $userChat = $this->getData($sqlQuery, [$fromUserId, $toUserId, $toUserId, $fromUserId], 'iiii');

        $conversation = '<ul>';
        foreach ($userChat as $chat) {
            if ($chat["sender_user_id"] == $fromUserId) {
                $conversation .= '<li class="sent"><img width="22px" height="22px" src="userpics/'.$fromUserAvatar.'" alt="" />';
            } else {
                $conversation .= '<li class="replies"><img width="22px" height="22px" src="userpics/'.$toUserAvatar.'" alt="" />';
            }
            $conversation .= '<p>'.$chat["message"].'</p></li>';
        }
        $conversation .= '</ul>';
        return $conversation;
    }

    public function showUserChat($fromUserId, $toUserId) {
        $userDetails = $this->getUserDetails($toUserId);
        $userSection = '';
        if (!empty($userDetails)) {
            $user = $userDetails[0];
            $userSection = '<img src="userpics/'.$user['avatar'].'" alt="" /><p>'.$user['username'].'</p><div class="social-media"><i class="fa fa-facebook" aria-hidden="true"></i><i class="fa fa-twitter" aria-hidden="true"></i><i class="fa fa-instagram" aria-hidden="true"></i></div>';
        }

        $conversation = $this->getUserChat($fromUserId, $toUserId);

        $sqlUpdate = "UPDATE $this->chatTable SET status = '0' WHERE sender_user_id = ? AND receiver_user_id = ? AND status = '1'";
        $stmt = $this->dbConnect->prepare($sqlUpdate);
        $stmt->bind_param('ii', $toUserId, $fromUserId);
        $stmt->execute();
        $stmt->close();

        $sqlUserUpdate = "UPDATE $this->chatUsersTable SET current_session = ? WHERE user_id = ?";
        $stmt = $this->dbConnect->prepare($sqlUserUpdate);
        $stmt->bind_param('ii', $toUserId, $fromUserId);
        $stmt->execute();
        $stmt->close();

        $data = [
            "userSection" => $userSection,
            "conversation" => $conversation
        ];
        echo json_encode($data);
    }

    public function getUnreadMessageCount($senderUserId, $receiverUserId) {
        $sqlQuery = "SELECT * FROM $this->chatTable WHERE sender_user_id = ? AND receiver_user_id = ? AND status = '1'";
        return $this->getNumRows($sqlQuery, [$senderUserId, $receiverUserId], 'ii');
    }

    public function updateTypingStatus($isType, $loginDetailsId) {
        $sqlUpdate = "UPDATE $this->chatLoginDetailsTable SET is_typing = ? WHERE id = ?";
        $stmt = $this->dbConnect->prepare($sqlUpdate);
        $stmt->bind_param('si', $isType, $loginDetailsId);
        $stmt->execute();
        $stmt->close();
    }

    public function fetchIsTypeStatus($userId) {
        $sqlQuery = "SELECT is_typing FROM $this->chatLoginDetailsTable WHERE user_id = ? ORDER BY last_activity DESC LIMIT 1";
        $result = $this->getData($sqlQuery, [$userId], 'i');
        return (!empty($result) && $result[0]["is_typing"] == 'yes') ? ' - <small><em>Typing...</em></small>' : '';
    }

    public function insertUserLoginDetails($userId) {
        $sqlInsert = "INSERT INTO $this->chatLoginDetailsTable (user_id) VALUES (?)";
        $stmt = $this->dbConnect->prepare($sqlInsert);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $lastInsertId = $stmt->insert_id;
        $stmt->close();
        return $lastInsertId;
    }

    public function updateLastActivity($loginDetailsId) {
        $sqlUpdate = "UPDATE $this->chatLoginDetailsTable SET last_activity = now() WHERE id = ?";
        $stmt = $this->dbConnect->prepare($sqlUpdate);
        $stmt->bind_param('i', $loginDetailsId);
        $stmt->execute();
        $stmt->close();
    }

    public function getUserLastActivity($userId) {
        $sqlQuery = "SELECT last_activity FROM $this->chatLoginDetailsTable WHERE user_id = ? ORDER BY last_activity DESC LIMIT 1";
        $result = $this->getData($sqlQuery, [$userId], 'i');
        return $result[0]['last_activity'] ?? null;
    }
}
?>
