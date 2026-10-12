<?php
require_once "scs_header.php";
$database->user->access("Administrator");
if ((isset($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['REQUEST_METHOD'] == "POST")) {
    $results = array();
    $cb_page   = max(1, intval($_POST['page'] ?? 1));
    $cb_search = trim($_POST['search'] ?? '');
    switch ($_POST['action']) {
      case "list":
        break;
      case "add":
        $email = strtolower(trim($_POST['email']));
        $comment = trim($_POST['comment']);
        // "@example.com" bypasses every address at that domain
        $is_domain = (substr($email, 0, 1) == "@");
        if (!strlen($email)) {
            $results['captcha_bypass_message'] = "<span style='color:red;'>Email or @domain is required</span>";
        } elseif ($is_domain && ((strpos(substr($email, 1), ".") === FALSE) || !filter_var(substr($email, 1), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            $results['captcha_bypass_message'] = "<span style='color:red;'>Invalid domain format</span>";
        } elseif (!$is_domain && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results['captcha_bypass_message'] = "<span style='color:red;'>Invalid email format</span>";
        } else {
            $database->captcha_bypass->read($email, "email");
            if ($database->captcha_bypass->meta->rows) {
                $results['captcha_bypass_message'] = "<span style='color:red;'>" . htmlspecialchars($email) . " already bypasses reCAPTCHA</span>";
            } else {
                $database->captcha_bypass->data = new captcha_bypass_data_class();
                $database->captcha_bypass->data->email = $email;
                $database->captcha_bypass->data->comment = $comment;
                $database->captcha_bypass->update(FALSE);
                if ($database->captcha_bypass->meta->error) {
                    $results['captcha_bypass_message'] = "<span style='color:red;'>Database error</span>";
                } else {
                    $options = array();
                    $options['comment'] = "reCAPTCHA bypass added: " . $email;
                    $database->log->update("CaptchaBypass:Add", $options);
                    $results['captcha_bypass_message'] = htmlspecialchars($email) . " will now bypass reCAPTCHA";
                    $cb_page = 1;
                }
            }
        }
        break;
      case "remove":
        $id = intval($_POST['id']);
        $database->captcha_bypass->read($id);
        if ($database->captcha_bypass->meta->rows) {
            $email = $database->captcha_bypass->data->email;
            $database->captcha_bypass->delete($id);
            $options = array();
            $options['comment'] = "reCAPTCHA bypass removed: " . $email;
            $database->log->update("CaptchaBypass:Remove", $options);
            $results['captcha_bypass_message'] = htmlspecialchars($email) . " has been removed from the bypass list";
            $cb_page = 1;
        }
        break;
    }
    $results['captcha_bypass_content'] = captcha_bypass_output($cb_page, $cb_search);
    die(json_encode($results));
}
$forms->title("reCAPTCHA Bypass");
$menu->head();
print $forms->message();
print "<style>#scs_content { min-height: calc(100vh - 200px); }</style>";
?>
<script>
var captcha_bypass_search_timer;

function captcha_bypass_load(page) {
    var search = jQuery("#captcha_bypass_search").val().trim();
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "list", page: page, search: search },
        success: function(response) {
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function captcha_bypass_search_keyup() {
    clearTimeout(captcha_bypass_search_timer);
    captcha_bypass_search_timer = setTimeout(function() { captcha_bypass_load(1); }, 300);
}

function captcha_bypass_add() {
    var email = jQuery("#new_email").val().trim();
    if (!email) return;
    if (!confirm("Bypass reCAPTCHA for: " + email + "?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "add", email: email, comment: jQuery("#new_comment").val(), page: 1, search: jQuery("#captcha_bypass_search").val().trim() },
        success: function(response) {
            jQuery("#captcha_bypass_message").html(response['captcha_bypass_message']);
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
            jQuery("#new_email").val("");
            jQuery("#new_comment").val("");
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}

function captcha_bypass_remove(id, email) {
    if (!confirm("Remove " + email + " from the reCAPTCHA bypass list?")) return;
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "remove", id: id, page: 1, search: jQuery("#captcha_bypass_search").val().trim() },
        success: function(response) {
            jQuery("#captcha_bypass_message").html(response['captcha_bypass_message']);
            jQuery("#captcha_bypass_content").html(response['captcha_bypass_content']);
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
</script>
<?php
print $forms->open();
$results = array();
$results[] = "<table class='standard border'>";
$results[] = "<tr>";
$results[] = "<td class='scscpq_label'>Email or @domain:</td>";
$results[] = "<td>" . $forms->text("new_email", "", 40, 190, "", array("class" => "scscpq_input", "placeholder" => "user@example.com or @example.com")) . "</td>";
$results[] = "<td class='scscpq_label'>Reason:</td>";
$results[] = "<td>" . $forms->text("new_comment", "", 40, 200, "", array("class" => "scscpq_input", "placeholder" => "Optional")) . "</td>";
$results[] = "<td>" . $forms->button("Add Bypass", array("onclick" => "captcha_bypass_add();", "class" => "red-button", "style" => "margin-top:0;padding:10px 20px;")) . "</td>";
$results[] = "</tr>";
$results[] = "</table>";
print implode("", $results);
print "<div style='padding:10px 0;'>";
print "<label style='margin-right:8px;font-weight:bold;'>Search:</label>";
print "<input type=text name='captcha_bypass_search' id='captcha_bypass_search' size='30' maxlength='200' class='scscpq_input' placeholder='Filter by email or reason...' onkeyup='captcha_bypass_search_keyup();' style='width:auto;'>";
print "</div>";
print "<div id=captcha_bypass_message style='padding:10px;'></div>";
print "<div id=captcha_bypass_content>" . captcha_bypass_output() . "</div>";
print $forms->close();
$menu->copyright();

function captcha_bypass_output($page=1, $search='') {
    global $database, $forms;
    $database->query_limit = 10;
    $query = array("select * from captcha_bypass");
    if (strlen($search)) {
        $query[] = "where email like " . fn_escape('%' . $search . '%') . " or comment like " . fn_escape('%' . $search . '%');
    }
    $query[] = "order by email";
    $query[] = $database->page_limit($page);
    $database->captcha_bypass->query($query, TRUE);
    $results = array();
    $results[] = "<table class='standard border tablesorter'>";
    $results[] = "<thead>";
    $results[] = "<tr>";
    $results[] = "<th width='10%'>Action</th>";
    $results[] = "<th width='35%'>Email / @domain</th>";
    $results[] = "<th width='35%'>Reason</th>";
    $results[] = "<th width='20%'>Date Added</th>";
    $results[] = "</tr>";
    $results[] = "</thead>";
    $results[] = "<tbody>";
    if (!$database->captcha_bypass->meta->rows) {
        $results[] = "<tr><td colspan=4>" . (strlen($search) ? "No results for &ldquo;" . htmlspecialchars($search) . "&rdquo;" : "No reCAPTCHA bypass entries") . "</td></tr>";
    } else {
        while ($database->captcha_bypass->fetch = $database->captcha_bypass->fetch_array()) {
            $database->captcha_bypass->fetch();
            $results[] = "<tr>";
            $results[] = "<td class=center>" . $forms->button("Remove", array("onclick" => "captcha_bypass_remove(" . $database->captcha_bypass->data->id . "," . htmlspecialchars(json_encode($database->captcha_bypass->data->email)) . ");", "style" => "padding:5px 10px;cursor:pointer;")) . "</td>";
            $results[] = "<td>" . htmlspecialchars($database->captcha_bypass->data->email) . "</td>";
            $results[] = "<td>" . htmlspecialchars($database->captcha_bypass->data->comment) . "</td>";
            $results[] = "<td class=center>" . ($database->captcha_bypass->data->created ? date("m/d/Y h:i A", $database->captcha_bypass->data->created) : "") . "</td>";
            $results[] = "</tr>";
        }
    }
    $database->captcha_bypass->free_result();
    $results[] = "</tbody>";
    $results[] = "</table>";
    $page_break = new page_item_break($database->captcha_bypass->meta->found_rows, "captcha_bypass_load(%page%);");
    $results[] = $page_break->page($page);
    return implode("", $results);
}
?>
