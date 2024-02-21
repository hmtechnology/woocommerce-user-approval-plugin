<?php 
/*
Plugin Name: WooCommerce User Approval 
Description: Redirects users to login page if not logged in and restricts access to non-approved users.
Version: 1.0
Author: hmtechnology
Author URI: https://github.com/hmtechnology
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.txt
Plugin URI: https://github.com/hmtechnology/woocommerce-user-approval-plugin
*/

// Function to redirect unauthenticated users to the login page
function redirect_users_to_login_page() {
    if (!is_user_logged_in() && !is_page('login')) {
        $login_url = home_url('/login/');
        wp_redirect($login_url);
        exit;
    }
}
add_action('template_redirect', 'redirect_users_to_login_page');

// Prevent automatic login for newly registered users
add_filter('woocommerce_registration_auth_new_customer', '__return_false');

// Add a custom field for users approval status to user profile
function custom_add_user_approval_field($user) {
    ?>
    <h3><?php _e('Approval Status', 'text_domain'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="user_approval"><?php _e('Approved', 'text_domain'); ?></label></th>
            <td>
                <input type="checkbox" name="user_approval" id="user_approval" value="1" <?php checked(get_user_meta($user->ID, 'user_approval', true), 1); ?> />
                <span class="description"><?php _e('Select if you want to approve this user', 'text_domain'); ?></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'custom_add_user_approval_field');
add_action('edit_user_profile', 'custom_add_user_approval_field');

// Save the approval status of users
function custom_save_user_approval_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    update_user_meta($user_id, 'user_approval', isset($_POST['user_approval']) && $_POST['user_approval'] == 1 ? 1 : 0);
}
add_action('personal_options_update', 'custom_save_user_approval_field');
add_action('edit_user_profile_update', 'custom_save_user_approval_field');

// Add a column in the user list in the admin panel
function add_user_approval_column($columns) {
    $columns['user_approval'] = __('Approved', 'text_domain');
    return $columns;
}
add_filter('manage_users_columns', 'add_user_approval_column');

// Add the contents of the "Approved" column for each user
function display_user_approval_column_content($value, $column_name, $user_id) {
    if ($column_name === 'user_approval') {
        $user_approval = get_user_meta($user_id, 'user_approval', true);
        return $user_approval ? __('Yes', 'text_domain') : __('No', 'text_domain');
    }
    return $value;
}
add_filter('manage_users_custom_column', 'display_user_approval_column_content', 10, 3);

// Disable login for unapproved users for "customer" role
function custom_remove_access_for_pending_customers() {
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        if (in_array('customer', (array)$current_user->roles) && !get_user_meta($current_user->ID, 'user_approval', true)) {
            wp_logout();
            $login_url = home_url('/login/');
            wp_redirect($login_url . '?login_error=not_approved');
            exit;
        }
    }
}
add_action('template_redirect', 'custom_remove_access_for_pending_customers');

// Function to display the error message on the "My Account" page if the user is not yet approved
function display_login_error_message_on_my_account_page() {
    if (isset($_GET['login_error']) && $_GET['login_error'] === 'not_approved') {
        echo '<div class="woocommerce-error">';
        _e('Your account is not yet active.', 'text_domain');
        echo '</div>';
    }
}
add_action('woocommerce_before_customer_login_form', 'display_login_error_message_on_my_account_page');

// Function to send a personalized email to the user when the account is created
function custom_registration_email_notification($customer_id) {
    $customer = new WC_Customer($customer_id);
    $email = new WC_Emails();

    $to = $customer->get_email();
    $subject = __('Welcome to Our Store!', 'woocommerce');
    $email_heading = __('Welcome to Our Store!', 'woocommerce');
    $message = sprintf(
        __('Hello %s,<br><br>Thank you for creating an account on %s. Your username is <strong>%s</strong>. You will be able to access your account area to view orders, change your password, and more at: <a href="%s">%s/login/</a> once the store administrator approves your account.', 'woocommerce'),
        $customer->get_username(),
        get_bloginfo('name'),
        $customer->get_username(),
        home_url(),
        home_url()
    );

    // Send the email using the WooCommerce email template
    $email->send($to, $subject, $email->wrap_message($email_heading, $message), '', '');
}
add_action('woocommerce_created_customer', 'custom_registration_email_notification');

// Send a personalized email to the user when the user's approval is checked from the back office
function send_approval_notification_email($user_id) {
    $previous_approval = get_user_meta($user_id, 'previous_user_approval', true);
    
    if (isset($_POST['user_approval']) && $_POST['user_approval'] == 1 && (!isset($previous_approval) || $_POST['user_approval'] != $previous_approval)) {
        $user = get_user_by('id', $user_id);
        $to = $user->user_email;
        $subject = __('Your account has been approved', 'woocommerce');
        $email_heading = __('Account Approval', 'woocommerce');
        $message = sprintf(
            __('Hello %s,<br><br>Your account on %s has been approved. You can access your account area to view orders, change your password, and more at: <a href="%s">%s/login/</a>.', 'woocommerce'),
            $user->display_name,
            get_bloginfo('name'),
            home_url(),
            get_bloginfo('url')
        );

        $email = new WC_Emails();
        $email->send($to, $subject, $email->wrap_message($email_heading, $message), '', '');

        update_user_meta($user_id, 'previous_user_approval', 1);
    } elseif ((!isset($_POST['user_approval']) || (isset($_POST['user_approval']) && $_POST['user_approval'] != 1)) ) {
        update_user_meta($user_id, 'previous_user_approval', 0);
    }
}
add_action('profile_update', 'send_approval_notification_email');

// Function to send an email to the administrator when a new user registers
function send_admin_new_user_email_notification($customer_id) {
    $user = get_userdata($customer_id);
    $admin_email = get_option('admin_email');

    $subject = __('New user registered on ' . get_bloginfo('name'), 'woocommerce');
    $message = sprintf(
        __('A new user has registered on %s. User details:<br><br>Username: %s<br>Email: %s', 'woocommerce'),
        get_bloginfo('name'),
        $user->user_login,
        $user->user_email
    );

    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    wp_mail($admin_email, $subject, $message, $headers);
}
add_action('woocommerce_created_customer', 'send_admin_new_user_email_notification');
