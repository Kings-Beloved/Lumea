<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

class Auth extends Config
{
  // ------------------------------------------------------------------
  // 1. CREATE CUSTOMER & APPOINTMENT (Step 3 -> Step 4 Transition)
  // ------------------------------------------------------------------
  public function create_customer()
  {
    $info = file_get_contents('php://input');
    $details = json_decode($info);

    $first_name = trim($details->firstName ?? '');
    $last_name  = trim($details->lastName ?? '');
    $email      = trim($details->email ?? '');
    $phone      = trim($details->phoneNumber ?? '');

    // Extract primitive treatment ID safely
    $treatment_id = $details->treatmentId ?? null;
    if (is_object($treatment_id) && isset($treatment_id->id)) {
      $treatment_id = $treatment_id->id;
    } elseif (is_array($treatment_id) && isset($treatment_id['id'])) {
      $treatment_id = $treatment_id['id'];
    }

    $bookingDateTime = $details->bookingDateTime ?? '';

    try {
      $dateObj = new DateTime($bookingDateTime);
      $appointment_date = $dateObj->format('Y-m-d'); // Formats to: 2026-10-30
      $start_time       = $dateObj->format('g:i A'); // Formats to: 9:00 AM
    } catch (Exception $e) {
      // Fallback if string splitting is required
      $parts = explode(' ', trim($bookingDateTime), 2);
      $appointment_date = $parts[0] ?? date('Y-m-d');
      $start_time       = $parts[1] ?? '09:00 AM';
    }
    // Check if customer already exists
    $query = "SELECT * FROM customers WHERE email = '$email'";
    $result = mysqli_query($this->connection, $query);

    if ($result && mysqli_num_rows($result) > 0) {
      $customer = mysqli_fetch_assoc($result);
      $customer_id = $customer['id'];
    } else {
      $insertQuery = "INSERT INTO customers (first_name, last_name, email, phone) 
                            VALUES ('$first_name', '$last_name', '$email', '$phone')";

      if (mysqli_query($this->connection, $insertQuery)) {
        $customer_id = mysqli_insert_id($this->connection);
      } else {
        echo json_encode([
          'status' => 400,
          'message' => 'Failed to save customer: ' . mysqli_error($this->connection)
        ]);
        return;
      }
    }

    // Fetch Treatment Name from table 'treatment'
    $treatmentName = 'Selected Treatment';
    $treatmentQuery = "SELECT name, deposit_amount FROM treatment WHERE id = '$treatment_id'";
    $treatmentResult = mysqli_query($this->connection, $treatmentQuery);
    if ($treatmentResult && mysqli_num_rows($treatmentResult) > 0) {
      $treatmentRow = mysqli_fetch_assoc($treatmentResult);
      $treatmentName = $treatmentRow['name'];
      $treatmentPrice = (float)($treatmentRow['deposit_amount'] ?? $details->price ?? 0);
    }

    // Insert Pending Appointment
    $appointmentQuery = "INSERT INTO appointment 
        (customer_id, treatment_id, appointment_date, start_time, status, payment_status) 
        VALUES 
        ('$customer_id', '$treatment_id', '$appointment_date', '$start_time', 'pending', 'unpaid')";

    $savedAppointment = mysqli_query($this->connection, $appointmentQuery);

    if ($savedAppointment) {
      $appointment_id = mysqli_insert_id($this->connection);

      // Send Unreserved/Pending Email Notice
      $this->send_pending_payment_email(
        $email,
        $first_name,
        $treatmentName,
        $appointment_date,
        $start_time,
        $appointment_id,
        $treatmentPrice // <--- Add the service amount here
      );

      // Return single clean JSON response for Angular
      echo json_encode([
        'status' => 200,
        'message' => 'Appointment created successfully!',
        'customer_id' => $customer_id,
        'appointment_id' => $appointment_id
      ]);
    } else {
      echo json_encode([
        'status' => 400,
        'message' => 'Failed to create appointment: ' . mysqli_error($this->connection)
      ]);
    }
  }

  // ------------------------------------------------------------------
  // 2. VERIFY PAYMENT & CONFIRM RESERVATION
  // ------------------------------------------------------------------
  public function verify_payment()
  {
    $info = file_get_contents('php://input');
    $data = json_decode($info);

    $appointment_id = $data->appointment_id ?? null;
    $amount         = $data->amount ?? 5000;
    $reference      = $data->reference ?? '';

    if (!$appointment_id) {
      echo json_encode([
        'status' => 400,
        'message' => 'Missing appointment ID.'
      ]);
      return;
    }

    // Update appointment status to confirmed & paid
    $updateQuery = "UPDATE appointment 
                        SET payment_status = 'paid', status = 'confirmed' 
                        WHERE id = '$appointment_id'";

    if (mysqli_query($this->connection, $updateQuery)) {

      // Fetch Customer, Appointment, and Treatment details for the confirmation email
      $getBookingDetails = "SELECT a.id, a.appointment_date, a.start_time, c.email, c.first_name, t.name AS treatment_name, t.deposit_amount AS treatment_price 
                      FROM appointment a 
                      JOIN customers c ON a.customer_id = c.id 
                      JOIN treatment t ON a.treatment_id = t.id 
                      WHERE a.id = '$appointment_id'";


      $result = mysqli_query($this->connection, $getBookingDetails);

      if ($result && mysqli_num_rows($result) > 0) {
        $booking = mysqli_fetch_assoc($result);

        // Send Reservation Confirmation Email
        // Send Reservation Confirmation Email
        $this->send_payment_confirmation_email(
          $booking['email'],
          $booking['first_name'],
          $booking['treatment_name'],
          $booking['appointment_date'],
          $booking['start_time'],
          (int)$booking['id'],
          (float)$amount,                      // 7th arg: Deposit paid via Paystack (e.g. 5000)
          (float)($booking['treatment_price'] ?? 0) // 8th arg: Total service fee (e.g. 25000)
        );
      }

      echo json_encode([
        'status' => 200,
        'message' => 'Payment verified and booking reserved successfully!'
      ]);
    } else {
      echo json_encode([
        'status' => 400,
        'message' => 'Failed to update payment status: ' . mysqli_error($this->connection)
      ]);
    }
  }

 
  // ------------------------------------------------------------------
  // HELPER: Send Pending Payment Email
  // ------------------------------------------------------------------
  private function send_pending_payment_email(string $recipientEmail, string $firstName, string $serviceName, string $date, string $time, int $bookingId, float $amount)
  {
      $secureToken = hash_hmac('sha256', (string)$bookingId, self::APP_SECRET_KEY);
      $paymentUrl = "http://localhost:4200/complete-payment?reference=" . $bookingId . "&token=" . $secureToken;

    $mail = new PHPMailer(true);

    try {
      $mail->isSMTP();
      $mail->Host       = 'smtp.gmail.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = 'olujinmioluwadarasimi01@gmail.com';
      $mail->Password   = 'gbynvngwkbewdwvl'; // App Password
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
      $mail->Port       = 465;
      $mail->Timeout    = 10;

      // Force SSL options for local XAMPP environments
      $mail->SMTPOptions = array(
        'ssl' => array(
          'verify_peer' => false,
          'verify_peer_name' => false,
          'allow_self_signed' => true
        )
      );

      $mail->setFrom('olujinmioluwadarasimi01@gmail.com', 'Luméa Spa & Wellness');
      $mail->addAddress($recipientEmail, $firstName);

      $mail->isHTML(true);

      // Format amount and date cleanly
      $formattedAmount = number_format($amount, 2);
      $formattedDate   = (strtotime($date) !== false) ? date('F j, Y', strtotime($date)) : $date;

      $mail->Subject = "Action Required: Complete Your Booking #" . $bookingId . " - Luméa Spa";

      $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; background-color: #f8f6f4; font-family: -apple-system, BlinkMacSystemFont,  Georgia, serif;'>
  <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f8f6f4; padding: 48px 12px;'>
    <tr>
      <td align='center'>
        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='max-width: 560px; background-color: #ffffff; border-radius: 4px; border: 1px solid #e8e2dc; overflow: hidden;'>
          
          <!-- Editorial Header -->
          <tr>
            <td style='padding: 40px 48px 24px 48px; text-align: center;'>
              <span style='font-size: 22px; font-weight: 400; letter-spacing: 5px; color: #1c1917; text-transform: uppercase;'>L U M É A</span>
              <div style='font-size: 10px; letter-spacing: 2.5px; color: #a8a29e; text-transform: uppercase; margin-top: 6px;'>Spa &amp; Wellness</div>
              <div style='width: 32px; height: 1px; background-color: #d6d3d1; margin: 24px auto 0 auto;'></div>
            </td>
          </tr>

          <!-- Action Notice & Messaging -->
          <tr>
            <td style='padding: 8px 48px 24px 48px; text-align: center;'>
              <span style='display: inline-block; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: #9a3412; font-weight: 600; padding: 6px 14px; background-color: #fff7ed; border-radius: 20px; border: 1px solid #ffedd5;'>
                Action Required — Slot Unreserved
              </span>
              <h1 style='font-size: 20px; font-weight: 400; color: #1c1917; margin: 20px 0 8px 0; font-family: Georgia, serif;'>
                Your appointment request is logged, " . htmlspecialchars($firstName) . ".
              </h1>
              <p style='font-size: 14px; color: #78716c; line-height: 1.6; margin: 0;'>
                We have received your booking request. To lock in your preferred time slot, please complete a deposit payment of <strong style='color: #1c1917;'>&#8358;5,000.00</strong>. Unreserved slots remain open to walk-in guests.
              </p>
            </td>
          </tr>

          <!-- Appointment Details Card -->
          <tr>
            <td style='padding: 0 48px 28px 48px;'>
              <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #fafaf9; border: 1px solid #f5f5f4; border-radius: 4px; padding: 24px;'>
                <tr>
                  <td style='padding-bottom: 12px; font-size: 11px; letter-spacing: 1.5px; color: #a8a29e; text-transform: uppercase; border-bottom: 1px solid #e7e5e4;' colspan='2'>
                    Booking Summary
                  </td>
                </tr>
                <tr>
                  <td style='padding: 12px 0 6px 0; font-size: 13px; color: #78716c;'>Booking Reference</td>
                  <td align='right' style='padding: 12px 0 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>#" . $bookingId . "</td>
                </tr>
                <tr>
                  <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Service</td>
                  <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($serviceName) . "</td>
                </tr>
                <tr>
                  <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Total Service Fee</td>
                  <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>&#8358;" . $formattedAmount . "</td>
                </tr>
                <tr>
                  <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Required Deposit</td>
                  <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #9a3412;'>&#8358;5,000.00</td>
                </tr>
                <tr>
                  <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Date</td>
                  <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($formattedDate) . "</td>
                </tr>
                <tr>
                  <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Time</td>
                  <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($time) . "</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Primary Call-to-Action Button -->
          <tr>
            <td style='padding: 0 48px 36px 48px; text-align: center;'>
              <a href='{$paymentUrl}' style='background-color: #1c1917; color: #ffffff; text-decoration: none; font-size: 13px; font-weight: 500; letter-spacing: 1.5px; text-transform: uppercase; padding: 16px 32px; border-radius: 2px; display: inline-block; box-shadow: 0 2px 8px rgba(0,0,0,0.08);'>
                Complete Deposit Payment &rarr;
              </a>
              <p style='font-size: 12px; color: #a8a29e; margin-top: 16px; margin-bottom: 0;'>
                Need assistance? Reply to this email or contact support.
              </p>
            </td>
          </tr>

          <!-- Clean Minimal Footer -->
          <tr>
            <td style='padding: 24px 48px; background-color: #fafaf9; border-top: 1px solid #f5f5f4; text-align: center;'>
              <p style='margin: 0; font-size: 12px; color: #a8a29e; letter-spacing: 0.5px;'>
                Luméa Spa &amp; Wellness &bull; Pending Reservation
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>";

      $mail->send();
    } catch (Exception $e) {
      error_log("PHPMailer Error: " . $mail->ErrorInfo);
    }
  }
  // ------------------------------------------------------------------
  // HELPER: Send Confirmed Payment Email
  // ------------------------------------------------------------------
  private function send_payment_confirmation_email(string $recipientEmail, string $firstName, string $serviceName, string $date, string $time, int $bookingId, float $amountPaid, float $totalAmount = 0.0)
  {
    $mail = new PHPMailer(true);

    try {
      $mail->isSMTP();
      $mail->Host       = 'smtp.gmail.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = 'olujinmioluwadarasimi01@gmail.com';
      $mail->Password   = 'gbynvngwkbewdwvl';
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
      $mail->Port       = 465;
      $mail->Timeout    = 10;

      $mail->SMTPOptions = array(
        'ssl' => array(
          'verify_peer' => false,
          'verify_peer_name' => false,
          'allow_self_signed' => true
        )
      );

      $mail->setFrom('olujinmioluwadarasimi01@gmail.com', 'Luméa Spa & Wellness');
      $mail->addAddress($recipientEmail, $firstName);

      $mail->isHTML(true);

      // Formatting values
      $formattedPaid  = number_format($amountPaid, 2);
      $formattedTotal = $totalAmount > 0 ? number_format($totalAmount, 2) : $formattedPaid;
      $formattedDate  = (strtotime($date) !== false) ? date('F j, Y', strtotime($date)) : $date;

      $mail->Subject = "Reservation Confirmed — #" . $bookingId . " | Luméa Spa";

      $mail->Body = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='utf-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f8f6f4; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Georgia, serif;'>
          <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f8f6f4; padding: 48px 12px;'>
            <tr>
              <td align='center'>
                <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='max-width: 560px; background-color: #ffffff; border-radius: 4px; border: 1px solid #e8e2dc; overflow: hidden;'>
                  
                  <!-- Editorial Header -->
                  <tr>
                    <td style='padding: 40px 48px 24px 48px; text-align: center;'>
                      <span style='font-size: 22px; font-weight: 400; letter-spacing: 5px; color: #1c1917; text-transform: uppercase;'>L U M É A</span>
                      <div style='font-size: 10px; letter-spacing: 2.5px; color: #a8a29e; text-transform: uppercase; margin-top: 6px;'>Spa &amp; Wellness</div>
                      <div style='width: 32px; height: 1px; background-color: #d6d3d1; margin: 24px auto 0 auto;'></div>
                    </td>
                  </tr>

                  <!-- Confirmation Badge & Message -->
                  <tr>
                    <td style='padding: 8px 48px 24px 48px; text-align: center;'>
                      <span style='display: inline-block; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: #15803d; font-weight: 600; padding: 6px 14px; background-color: #f0fdf4; border-radius: 20px; border: 1px solid #dcfce7;'>
                        Reservation Confirmed
                      </span>
                      <h1 style='font-size: 20px; font-weight: 400; color: #1c1917; margin: 20px 0 8px 0; font-family: Georgia, serif;'>
                        We look forward to seeing you, " . htmlspecialchars($firstName) . ".
                      </h1>
                      <p style='font-size: 14px; color: #78716c; line-height: 1.6; margin: 0;'>
                        Your deposit payment of <strong style='color: #1c1917;'>&#8358;" . $formattedPaid . "</strong> has been received. Your time slot is reserved.
                      </p>
                    </td>
                  </tr>

                  <!-- Appointment Details Card -->
                  <tr>
                    <td style='padding: 0 48px 32px 48px;'>
                      <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #fafaf9; border: 1px solid #f5f5f4; border-radius: 4px; padding: 24px;'>
                        <tr>
                          <td style='padding-bottom: 12px; font-size: 11px; letter-spacing: 1.5px; color: #a8a29e; text-transform: uppercase; border-bottom: 1px solid #e7e5e4;' colspan='2'>
                            Appointment Details
                          </td>
                        </tr>
                        <tr>
                          <td style='padding: 12px 0 6px 0; font-size: 13px; color: #78716c;'>Booking Reference</td>
                          <td align='right' style='padding: 12px 0 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>#" . $bookingId . "</td>
                        </tr>
                        <tr>
                          <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Service</td>
                          <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($serviceName) . "</td>
                        </tr>
                        <tr>
                          <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Total Service Fee</td>
                          <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>&#8358;" . $formattedTotal . "</td>
                        </tr>
                        <tr>
                          <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Deposit Paid</td>
                          <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #15803d;'>&#8358;" . $formattedPaid . "</td>
                        </tr>
                        <tr>
                          <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Date</td>
                          <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($formattedDate) . "</td>
                        </tr>
                        <tr>
                          <td style='padding: 6px 0; font-size: 13px; color: #78716c;'>Time</td>
                          <td align='right' style='padding: 6px 0; font-size: 13px; font-weight: 600; color: #1c1917;'>" . htmlspecialchars($time) . "</td>
                        </tr>
                      </table>
                    </td>
                  </tr>

                  <!-- Important Information -->
                  <tr>
                    <td style='padding: 0 48px 36px 48px; text-align: center;'>
                      <p style='font-size: 13px; color: #78716c; line-height: 1.5; margin: 0;'>
                        Please arrive 5 to 10 minutes before your scheduled time. If you need to reschedule or make changes, feel free to contact us.
                      </p>
                    </td>
                  </tr>

                  <!-- Clean Minimal Footer -->
                  <tr>
                    <td style='padding: 24px 48px; background-color: #fafaf9; border-top: 1px solid #f5f5f4; text-align: center;'>
                      <p style='margin: 0; font-size: 12px; color: #a8a29e; letter-spacing: 0.5px;'>
                        Luméa Spa &amp; Wellness &bull; Reserved Session Confirmation
                      </p>
                    </td>
                  </tr>

                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>";

      $mail->send();
    } catch (Exception $e) {
      error_log("Confirmation email error: " . $mail->ErrorInfo);
    }
  }



public function get_booking()
{
    // 1. Enable CORS for Angular frontend requests
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json");

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // 2. Validate input parameters
    $reference = $_GET['reference'] ?? null;
    $token = $_GET['token'] ?? null;

    if (!$reference || !$token) {
        http_response_code(400);
        echo json_encode([
            'status' => 400,
            'message' => 'Missing reference or token parameters.'
        ]);
        return;
    }

    // 3. Verify HMAC token security
    $expectedToken = hash_hmac('sha256', (string)$reference, self::APP_SECRET_KEY);
    if (!hash_equals($expectedToken, $token)) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Invalid or tampered payment link.'
        ]);
        return;
    }

    // 4. Sanitize reference ID for SQL safety
    $refId = mysqli_real_escape_string($this->connection, $reference);

    // 5. Query appointment joined with treatment details
    $query = "SELECT b.*, t.name AS treatment_name, t.deposit_amount 
              FROM appointment b 
              JOIN treatment t ON b.treatment_id = t.id 
              WHERE b.id = '$refId' 
                AND (LOWER(b.payment_status) = 'unpaid' OR LOWER(b.payment_status) = 'pending') 
              LIMIT 1";

    $result = mysqli_query($this->connection, $query);

    // 6. Return response
    if ($result && mysqli_num_rows($result) > 0) {
        $bookingData = mysqli_fetch_assoc($result);
        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'data' => $bookingData
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'message' => 'Booking record not found or payment already completed.'
        ]);
    }
}

}
