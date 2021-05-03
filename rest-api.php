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
      //'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
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

  $username_auth = esc_sql(trim($request->get_header('username')));
  $password_auth = esc_sql(trim($request->get_header('password')));

  //check user and password authentication
  $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "users WHERE user_login = '" . $username_auth . "' AND user_pass = '" . $password_auth . "'");
  if ($wpdb->num_rows < 1) {
    return 'Username or passowrd is invalid.';
  }

  //Get current userID from userName
  $user = get_userdatabylogin($username_auth);

  //check body exists
  if (($content_body == null || $content_body == '') && !array_key_exists("message", $data)) {
    $data['message'] = "body is required.";
  }

  //check valid json format
  $content_body = json_decode($content_body, true);
  // var_dump( !array_key_exists("message", $data));
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

  $custom_url =  esc_sql(trim($new_custom_url));
  $get_post_id = $wpdb->get_var($wpdb->prepare("SELECT `post_id` FROM wp_postmeta WHERE meta_key = 'custom_permalink' AND meta_value = %s;", $custom_url));

  // Get Product ID.
  $_productId = getid(esc_sql(trim($content_body['product'])), 'product');

  // Get Language ID based on above Product.
  $languages = $wpdb->get_results("SELECT ID FROM " . $wpdb->prefix . "posts WHERE post_title = '" . esc_sql(trim($content_body['language'])) . "' And post_type='language'");
  $_languageId = null;
  foreach ($languages as $language) {
    $_language = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "postmeta WHERE meta_key ='product_id' And post_id = '" . $language->ID . "' And meta_value='" . $_productId  . "'");
    if (count($_language) > 0) {
      $_languageId = $language->ID;
    };
  }

  // Get Framework ID based on above Language.
  $frameworks = $wpdb->get_results("SELECT ID FROM " . $wpdb->prefix . "posts WHERE post_title = '" . esc_sql(trim($content_body['framework'])) . "' And post_type='framework'");
  $_frameworkID = null;
  foreach ($frameworks as $framework) {
    $_framework = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "postmeta WHERE meta_key ='language_id' And post_id = '" . $framework->ID . "' And meta_value='" . $_languageId  . "'");
    if (count($_framework) > 0) {
      $_frameworkID = $framework->ID;
    };
  }

  // Get Section ID based on above Framework.
  $sections = $wpdb->get_results("SELECT ID FROM " . $wpdb->prefix . "posts WHERE post_title = '" . esc_sql(trim($content_body['section'])) . "' And post_type='section'");
  $_sectionID = null;
  foreach ($sections as $section) {
    $_section = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "postmeta WHERE meta_key ='framework_id' And post_id = '" . $section->ID . "' And meta_value='" . $_frameworkID  . "'");
    if (count($_section) > 0) {
      $_sectionID = $section->ID;
    };
  }

  //update Docs
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

      //if error not found.
      if (count($arr) == 0) {
          $rid = _wp_put_post_revision($get_post_id);

          $wpdb->get_var("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ('" . $rid . "', 'document_remarks', '" . (esc_sql(trim($content_body['document_remarks'])) ?: '') . "');");

          if ($rid) {
            $document_post = array(
              'ID'           => $rid,
              'post_content'   => $content_body['content'],
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
        if (!$_productId) {
          $arr[] = "product mismatch.";
        }
        if (!$_languageId) {
          $arr[] = "language mismatch.";
        }
        if (!$_frameworkID) {
          $arr[] = "framework mismatch.";
        }
        if (!$_sectionID) {
          $arr[] = "section mismatch.";
        }

        if (count($arr) == 0) {
          $document_post = array(
            'post_content'   => $content_body['content'],
            'post_title' => $content_body['title'],
            'post_name' => sanitize_title($content_body['title']).time(),
            'post_status' => 'draft',
            'post_type' => 'doc',
            'post_author'  => $user->ID
          );

          $new_post_id = wp_insert_post($document_post);
          $document_guid = generateGUID();

          add_post_meta($new_post_id, 'product_id', $_productId);
          add_post_meta($new_post_id, 'language_id', $_languageId);
          add_post_meta($new_post_id, 'framework_id', $_frameworkID);
          add_post_meta($new_post_id, 'section_id', $_sectionID);
          add_post_meta($new_post_id, 'custom_permalink', $new_custom_url);

          //Below fields are also mandatory to setup docs field.
          add_post_meta($new_post_id, 'doc_guid', $document_guid);
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