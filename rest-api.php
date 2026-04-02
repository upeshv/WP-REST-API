<?php
add_filter('rest_url_prefix', function ($slug) {
  return 'awesome-api';
});

add_filter('rest_authentication_errors', function ($result) {
  return $result;
});

add_action('rest_api_init', function () {
  $version = 'v1';
  $namespace = $version . '/docs';

  if ( strpos( get_home_url(), 'xyz.com') !== false) {
    register_rest_route($namespace, 'document', array(
      'methods'  => WP_REST_Server::CREATABLE,
      'callback' => 'awesome_rest_api_get_document',
      'permission_callback' => '__return_true', // Added a default permission callback
    ));
  }
});

// To get the current title
function getid($title, $type)
{
  $page = get_page_by_title($title, OBJECT, $type);
  if ($page) {
    return $page->ID;
  } else {
    return null;
  }
}

function awesome_rest_api_get_document($request)
{
  global $wpdb;
  $data = array('status' => false);
  $status_code = 200;
  $content_body = $request->get_body();

  // SECURE: used  sanitize_text_field for headers
  $username_auth = sanitize_text_field(trim($request->get_header('username')));
  $password_auth = trim($request->get_header('password'));

  // SECURE: Fetch user properly to check hashed password
  $user = get_user_by('login', $username_auth);

  // SECURE: Check password using WordPress native hashing check
  if ( !$user || !wp_check_password($password_auth, $user->data->user_pass, $user->ID) ) {
    return new WP_Error('rest_forbidden', 'Username or password is invalid.', array('status' => 403));
  }

  // Check body exists
  if (($content_body == null || $content_body == '') && !array_key_exists("message", $data)) {
    $data['message'] = "body is required.";
  }

  // Check valid json format
  $content_body = json_decode($content_body, true);
  if (($content_body == null) && !array_key_exists("message", $data)) {
    $data['message'] = "send valid json format.";
  }

  if (($content_body['content'] == null
    || $content_body['title'] == null
    || $content_body['product'] == null
    || $content_body['language'] == null
    || $content_body['framework'] == null
    || $content_body['section'] == null
    || $content_body['custom_url'] == null
    || $content_body['operation'] == null) && !array_key_exists("message", $data)) {
    $data['message'] = "required field missing from JSON.";
  }

  $data['operation'] = $content_body['operation'];
  $new_custom_url = rtrim($content_body['custom_url'], '/') . '/';

  // SECURE: used $wpdb->prepare for Meta queries
  $custom_url = trim($new_custom_url);
  $get_post_id = $wpdb->get_var($wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'custom_permalink' AND meta_value = %s",
    $custom_url
  ));

  // SECURE: Sanitize product title
  $_productId = getid(sanitize_text_field(trim($content_body['product'])), 'product');

  // SECURE: used prepare for Language ID lookup
  $language_title = sanitize_text_field(trim($content_body['language']));
  $languages = $wpdb->get_results($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'language'",
    $language_title
  ));

  $_languageId = null;
  foreach ($languages as $language) {
    // SECURE: used prepare for meta check
    $_language = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->postmeta} WHERE meta_key ='product_id' AND post_id = %d AND meta_value = %s",
        $language->ID,
        $_productId
    ));
    if (count($_language) > 0) {
      $_languageId = $language->ID;
    };
  }

  // SECURE: used prepare for Framework ID lookup
  $framework_title = sanitize_text_field(trim($content_body['framework']));
  $frameworks = $wpdb->get_results($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'framework'",
    $framework_title
  ));
  
  $_frameworkID = null;
  foreach ($frameworks as $framework) {
    $_framework = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->postmeta} WHERE meta_key ='language_id' AND post_id = %d AND meta_value = %d",
        $framework->ID,
        $_languageId
    ));
    if (count($_framework) > 0) {
      $_frameworkID = $framework->ID;
    };
  }

  // SECURE: used prepare for Section ID lookup
  $section_title = sanitize_text_field(trim($content_body['section']));
  $sections = $wpdb->get_results($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'section'",
    $section_title
  ));
  
  $_sectionID = null;
  foreach ($sections as $section) {
    $_section = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->postmeta} WHERE meta_key ='framework_id' AND post_id = %d AND meta_value = %d",
        $section->ID,
        $_frameworkID
    ));
    if (count($_section) > 0) {
      $_sectionID = $section->ID;
    };
  }

  // update Docs
  if ($content_body['operation'] == "update" && !array_key_exists("message", $data)) {
    if ($get_post_id != null && !array_key_exists("message", $data)) {
      $arr = array();
      if (get_post_meta($get_post_id, 'product_id', true) != $_productId) {
        $arr[] = "product mismatch.";
      }
      if (get_post_meta($get_post_id, 'language_id', true) != $_languageId) {
        $arr[] = "language mismatch.";
      }
      if (get_post_meta($get_post_id, 'framework_id', true) != $_frameworkID) {
        $arr[] = "framework mismatch.";
      }
      if (get_post_meta($get_post_id, 'section_id', true) != $_sectionID) {
        $arr[] = "section mismatch.";
      }

      if (count($arr) == 0) {
          $rid = _wp_put_post_revision($get_post_id);

          // SECURE: Prepare for custom meta insert
          $doc_remarks = sanitize_textarea_field(trim($content_body['document_remarks'] ?? ''));
          $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, 'document_remarks', %s)",
            $rid,
            $doc_remarks
          ));

          if ($rid) {
            $document_post = array(
              'ID'           => $rid,
              'post_content' => wp_kses_post($content_body['content']), // SECURE: Allow safe HTML
              'post_author'  => $user->ID
            );
            wp_update_post($document_post);
            $data['status'] = true;
            $data['message'] = "Revision created successfully, Revision ID=>" . $rid;
          } else {
            $data['message'] = "Revision not created";
          }
      } else {
        $data['message'] = $arr;
      }
    } else {
      $data['message'] = "Doc Id not found, make sure you are updating old doc.";
    }
  } else if ($content_body['operation'] == "create" && !array_key_exists("message", $data)) {
      if ($get_post_id == null && !array_key_exists("message", $data)) {
        $arr = array();
        if (!$_productId) { $arr[] = "product mismatch."; }
        if (!$_languageId) { $arr[] = "language mismatch."; }
        if (!$_frameworkID) { $arr[] = "framework mismatch."; }
        if (!$_sectionID) { $arr[] = "section mismatch."; }

        if (count($arr) == 0) {
          $document_post = array(
            'post_content' => wp_kses_post($content_body['content']), // SECURE: Allow safe HTML
            'post_title'   => sanitize_text_field($content_body['title']),
            'post_name'    => sanitize_title($content_body['title']).time(),
            'post_status'  => 'draft',
            'post_type'    => 'doc',
            'post_author'  => $user->ID
          );

          $new_post_id = wp_insert_post($document_post);
          
        
          if (function_exists('generateGUID')) {
             $document_guid = generateGUID();
             add_post_meta($new_post_id, 'doc_guid', $document_guid);
          }

          add_post_meta($new_post_id, 'product_id', $_productId);
          add_post_meta($new_post_id, 'language_id', $_languageId);
          add_post_meta($new_post_id, 'framework_id', $_frameworkID);
          add_post_meta($new_post_id, 'section_id', $_sectionID);
          add_post_meta($new_post_id, 'custom_permalink', sanitize_text_field($new_custom_url));
          add_post_meta($new_post_id, 'document_enable_feedback', 'on');

          $data['status'] = true;
          $data['message'] = "New Docs created successfully, Doc ID =>. $new_post_id";
        } else {
          $data['message'] = $arr;
        }
      } else {
        $data['message'] = "Doc Id already exists, make sure you are creating new doc.";
      }
    } else if (!array_key_exists("message", $data)) {
    $data['message'] = "Operation should be create or update";
  }

  return new WP_REST_Response($data, $status_code);
}
