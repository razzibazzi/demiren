<?php

include "headers.php";

class Transactions
{
    function bookingList()
    {
        include "connection.php";

        $sql = "
    SELECT 
        b.reference_no AS 'Ref No',
        COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                 CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS 'Name',
        b.booking_checkin_dateandtime AS 'Check-in',
        b.booking_checkout_dateandtime AS 'Check-out',
        GROUP_CONCAT(DISTINCT rt.roomtype_name SEPARATOR ', ') AS 'Room Type',
        'Pending' AS 'Status'
    FROM 
        tbl_booking b
    LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
    LEFT JOIN tbl_customers_walk_in w ON b.customers_walk_in_id = w.customers_walk_in_id
    LEFT JOIN tbl_booking_room br ON b.booking_id = br.booking_id
    LEFT JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
    WHERE 
        b.booking_id NOT IN (
            SELECT booking_id
            FROM tbl_booking_history
            WHERE status_id IN (1, 2, 3)
        )
    GROUP BY b.reference_no;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function finalizeBookingApproval($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $reference_no = $json['reference_no'] ?? '';
        $selected_room_ids = $json['assigned_rooms'] ?? [];

        if (!$reference_no || empty($selected_room_ids)) {
            echo 'invalid';
            return;
        }

        $stmt = $conn->prepare("SELECT booking_id FROM tbl_booking WHERE reference_no = :ref");
        $stmt->bindParam(':ref', $reference_no);
        $stmt->execute();
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            echo 'not_found';
            return;
        }

        $booking_id = $booking['booking_id'];
        $employee_id = 1; // Replace with session

        // Assign rooms to booking_room
        foreach ($selected_room_ids as $room_id) {
            $stmt = $conn->prepare("
            UPDATE tbl_booking_room 
            SET roomnumber_id = :room_id 
            WHERE booking_id = :booking_id AND roomnumber_id IS NULL 
            LIMIT 1
        ");
            $stmt->bindParam(':room_id', $room_id);
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->execute();

            // Update room status to occupied (1)
            $stmt = $conn->prepare("UPDATE tbl_rooms SET room_status_id = 1 WHERE roomnumber_id = :room_id");
            $stmt->bindParam(':room_id', $room_id);
            $stmt->execute();
        }

        // Insert into history
        $stmt = $conn->prepare("INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at) VALUES (:id, :emp, 2, NOW())");
        $stmt->bindParam(':id', $booking_id);
        $stmt->bindParam(':emp', $employee_id);
        $result = $stmt->execute();

        echo $result ? 'success' : 'fail';
    }
    function getVacantRoomsByBooking($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $reference_no = $json['reference_no'] ?? null;

        if (!$reference_no) {
            echo json_encode(['error' => 'Missing reference_no']);
            return;
        }

        // Step 1: Get booking ID
        $stmt = $conn->prepare("SELECT booking_id FROM tbl_booking WHERE reference_no = :ref");
        $stmt->bindParam(':ref', $reference_no);
        $stmt->execute();
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            echo json_encode(['error' => 'Booking not found']);
            return;
        }

        $booking_id = $booking['booking_id'];

        // Step 2: Get roomtype(s) and count(s)
        $stmt = $conn->prepare("
        SELECT br.roomtype_id, rt.roomtype_name, COUNT(*) AS room_count
        FROM tbl_booking_room br
        JOIN tbl_roomtype rt ON rt.roomtype_id = br.roomtype_id
        WHERE br.booking_id = :booking_id
        GROUP BY br.roomtype_id
    ");
        $stmt->bindParam(':booking_id', $booking_id);
        $stmt->execute();
        $roomGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Step 3: Get all available rooms
        $data = [];
        foreach ($roomGroups as $group) {
            $stmt = $conn->prepare("
            SELECT r.roomnumber_id, r.roomfloor
            FROM tbl_rooms r
            WHERE r.roomtype_id = :roomtype_id AND r.room_status_id = 3
        ");
            $stmt->bindParam(':roomtype_id', $group['roomtype_id']);
            $stmt->execute();
            $vacant_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data[] = [
                'roomtype_id' => $group['roomtype_id'],
                'roomtype_name' => $group['roomtype_name'],
                'room_count' => $group['room_count'],
                'vacant_rooms' => $vacant_rooms
            ];
        }

        echo json_encode($data);
    }

    function getRooms()
    {
        include "connection.php";
        $sql = "SELECT a.roomnumber_id, b.roomtype_name, c.status_name
                FROM tbl_rooms AS a
                INNER JOIN tbl_roomtype AS b ON b.roomtype_id = a.roomtype_id
                INNER JOIN tbl_status_types AS c ON c.status_id = a.room_status_id
                WHERE a.room_status_id = 3
                ORDER BY a.roomnumber_id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function chargesMasterList()
    {
        include "connection.php";

        $sql = "
        SELECT 
            c.charges_category_name AS 'Category',
            m.charges_master_id AS 'Charge ID',
            m.charges_master_name AS 'Charge Name',
            m.charges_master_price AS 'Price'
        FROM tbl_charges_master m
        JOIN tbl_charges_category c ON m.charges_category_id = c.charges_category_id
        ORDER BY c.charges_category_name, m.charges_master_name;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function bookingChargesList()
    {
        include "connection.php";

        $sql = "
        SELECT 
            bc.booking_charges_id AS 'Charge ID',
            bc.booking_room_id AS 'Room Booking ID',
            cc.charges_category_name AS 'Category',
            cm.charges_master_name AS 'Charge Name',
            bc.booking_charges_price AS 'Price',
            bc.booking_charges_quantity AS 'Quantity',
            (bc.booking_charges_price * bc.booking_charges_quantity) AS 'Total Amount'
        FROM tbl_booking_charges bc
        JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
        JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
        ORDER BY bc.booking_charges_id;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function addChargesAmenities()
{
    include "connection.php";

    // Check if JSON exists in POST
    if (!isset($_POST['json'])) {
        echo json_encode(['status' => 'error', 'message' => 'No data sent']);
        return;
    }

    // Decode the incoming JSON
$json = json_decode($_POST['json'], true);

    if (!isset($json['charges_category_id'], $json['charges_master_name'], $json['charges_master_price'])) {
        echo json_encode(['status' => 'error', 'message' => 'Incomplete data']);
        return;
    }

    $categoryId = $json['charges_category_id'];
    $amenityName = $json['charges_master_name'];
    $price = $json['charges_master_price'];

    try {
        $stmt = $conn->prepare("INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price) VALUES (:categoryId, :name, :price)");
        $stmt->bindParam(':categoryId', $categoryId);
        $stmt->bindParam(':name', $amenityName);
        $stmt->bindParam(':price', $price);
        $success = $stmt->execute();

        echo json_encode($success ? 'success' : 'fail');
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function getChargesCategory()
{
    include "connection.php";
    
    try {
        $sql = "SELECT charges_category_id, charges_category_name FROM tbl_charges_category ORDER BY charges_category_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function saveAmenitiesCharges()
{
    include "connection.php";
    
    if (!isset($_POST['json'])) {
        echo json_encode(['status' => 'error', 'message' => 'No data sent']);
        return;
    }

    $json = json_decode($_POST['json'], true);
    
    if (!isset($json['items']) || !is_array($json['items'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data format']);
        return;
    }

    try {
        $conn->beginTransaction();
        
        foreach ($json['items'] as $item) {
            if (!isset($item['charges_category_id'], $item['charges_master_name'], $item['charges_master_price'])) {
                throw new Exception('Missing required fields');
            }

            $stmt = $conn->prepare("INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price) VALUES (:categoryId, :name, :price)");
            $stmt->bindParam(':categoryId', $item['charges_category_id']);
            $stmt->bindParam(':name', $item['charges_master_name']);
            $stmt->bindParam(':price', $item['charges_master_price']);
            $stmt->execute();
        }
        
        $conn->commit();
        echo 'success';
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function updateAmenityCharges()
{
    include "connection.php";
    
    if (!isset($_POST['json'])) {
        echo json_encode(['status' => 'error', 'message' => 'No data sent']);
        return;
    }

    $json = json_decode($_POST['json'], true);
    
    if (!isset($json['charges_master_id'], $json['charges_master_name'], $json['charges_master_price'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        return;
    }

    try {
        $stmt = $conn->prepare("UPDATE tbl_charges_master SET charges_master_name = :name, charges_master_price = :price WHERE charges_master_id = :id");
        $stmt->bindParam(':name', $json['charges_master_name']);
        $stmt->bindParam(':price', $json['charges_master_price']);
        $stmt->bindParam(':id', $json['charges_master_id']);
        
        $result = $stmt->execute();
        
        if ($result && $stmt->rowCount() > 0) {
            echo 'success';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No records updated']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}


}

$json = isset($_POST['json']) ? $_POST['json'] : 0;
$operation = isset($_POST['operation']) ? $_POST['operation'] : 0;
$transactions = new Transactions();

switch ($operation) {
    case 'bookingList':
        $transactions->bookingList();
        break;
    case 'finalizeBookingApproval':
        $transactions->finalizeBookingApproval($json);
        break;
    case 'getVacantRoomsByBooking':
        $transactions->getVacantRoomsByBooking($json);
        break;
    case "chargesMasterList":
        $transactions->chargesMasterList();
        break;
    case "bookingChargesList":
        $transactions->bookingChargesList();
        break;
    case "addChargesAmenities": 
        $transactions->addChargesAmenities();
        break;
    case "getChargesCategory":
        $transactions->getChargesCategory();
        break;
    case "chargesCategoryList":
        $transactions->getChargesCategory();
        break;
    case "saveAmenitiesCharges":
        $transactions->saveAmenitiesCharges();
        break;
    case "updateAmenityCharges":
        $transactions->updateAmenityCharges();
        break;
    default:
        echo "Invalid Operation";
        break;
}
