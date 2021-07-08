<?php
defined( 'ABSPATH' ) or die( 'No script manipulation please!' );
/*
Plugin Name: BuildThis Custom Subscriptions
Description: Creates custom API routes for adding subscription data to users as well as handling sending out email updates to users who have subscribed to different categories and tags with new posts that have recently been published.
Version: 1.0.1
Author: BuildThis
License: GPLv2
*/

class Moto_Custom_Endpoints extends WP_REST_Controller {
	private $api_namespace;
	private $base;
	private $api_version;
	
	public function __construct() {
		$this->api_namespace = 'sos-moto/v';
		$this->api_version = '1';
		$this->init();
  }
  public function __destruct(){
    
  }

  // Init function to 
	public function init(){
    //Add Action to Register Plugin Specific Settings
    add_action( 'admin_init',array($this, 'register_moto_settings') );
    //Add Action to Add Options Menu Link
    add_action( 'admin_menu', array($this, 'bt_moto_menu' ));
    //Add Action to Register REST Endpoints
    add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    //Add Filter for Authentication for the API
    add_filter( 'determine_current_user', array( $this, 'json_basic_auth_handler'), 20 );
    //Add Filter for handling Authentication Errors for the API
    add_filter( 'rest_authentication_errors', array( $this, 'json_basic_auth_error'), 20 );
  
    add_action( 'bt_cron_hook', 'send_subscription_emails_function' );


    
  }

  

  
  //Basic Authentication for API Endpoints
  function json_basic_auth_handler( $wpuser ) {
    global $wp_json_basic_auth_error;
    $wp_json_basic_auth_error = null;

    // Don't authenticate twice
    if ( ! empty( $wpuser ) ) {
      return $wpuser;
    }
    // Check that we're trying to authenticate
    if ( !isset( $_SERVER['PHP_AUTH_USER'] ) ) {
      return $wpuser;
    }

    $username = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'];
    /**
     * In multi-site, wp_authenticate_spam_check filter is run on authentication. This filter calls
     * get_currentuserinfo which in turn calls the determine_current_user filter. This leads to infinite
     * recursion and a stack overflow unless the current function is removed from the determine_current_user
     * filter during authentication.
     */
    remove_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );
    $wpuser = wp_authenticate( $username, $password );
    
    add_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );
    if ( is_wp_error( $wpuser ) ) {
      $wp_json_basic_auth_error = $wpuser;
      return null;
    }
    
    $wp_json_basic_auth_error = true;
    return $wpuser->ID;
  }
  //Error Handling for API Endpoints
  function json_basic_auth_error( $error ) {
    // Passthrough other errors
    if ( ! empty( $error ) ) {
      return $error;
    }
    global $wp_json_basic_auth_error;
    return $wp_json_basic_auth_error;
  }
  //Register Functions to API Endpoint Routes
  public function register_routes() {
		$namespace = $this->api_namespace . $this->api_version;
		
    register_rest_route( $namespace, 'user/', array(
      'methods' => 'POST',
      'callback' => array( $this, 'create_or_update_user' ),
      'permission_callback'   => array( $this, 'set_permission' )

    ) );
    register_rest_route( $namespace, 'user/delete/', array(
      'methods' => "DELETE",
      'callback' => array( $this, 'delete_user' ),
      'permission_callback'   => array( $this, 'set_permission' )
    ) );
    register_rest_route( $namespace, 'test/', array(
      'methods' => "get",
      'callback' => array( $this, 'send_subscription_emails_function' ),
      'permission_callback'   => ''
    ) );

  }
  //Permissions Callback for the API Endpoints
  public function set_permission(){
    if ( ! current_user_can( 'edit_users' ) ) {
          return new WP_Error( 'rest_forbidden', esc_html__( 'You do not have permissions to perform this action.', 'my-text-domain' ), array( 'status' => 401 ) );
      }
 
      // This approach blocks the endpoint operation. You could alternatively do this by an un-blocking approach, by returning false here and changing the permissions check.
      return true;
  }
  //Set Options needed for the plugin
  public function register_moto_settings(){
    register_setting('bt-moto','sendgrid_api_key');
  }
  //Adds The BT-Moto Options Link to The Menu
  function bt_moto_menu() {
    add_menu_page( 'BT-Moto Options', 'BT-Moto', 'edit_pages', 'bt-moto', array($this,'bt_moto_options'),plugins_url('moto-rest-api/images/Codey-20x20.png'),4);
  }
  //Adds the content for the BT-Moto Options Page
  function bt_moto_options() {
    if ( !current_user_can( 'manage_options' ) )  {
      wp_die( __( 'You do not have sufficient permissions to modify BT-Moto settings.' ) );
    }
      ?>
    <div class="wrap">
    <h1>BT-Moto Settings</h1>
      <form method="post" action="options.php">
      <?php settings_fields( 'bt-moto' ); ?>
      <?php do_settings_sections( 'bt-moto' ); ?>
      <table class="form-table">
          <tr valign="top">
          <th scope="row">SendGrid Api Key</th>
          <td><input type="text" name="sendgrid_api_key" style="width:100%;" value="<?php echo esc_attr( get_option('sendgrid_api_key') ); ?>" /></td>
          </tr>
      </table>
      <?php submit_button(); ?>
      </form>
      <div>
        <h2>Scheduled Sending Status:<?php ( !empty(wp_next_scheduled( 'bt_cron_hook' )) ? 'Active' : 'Inactive' ) ?></h2>
        <?php
        if(wp_next_scheduled( 'bt_cron_hook' )){
          ?>
            <div>Next Scheduled Send: 
              <?php 
                $next_send =  new DateTime("@".wp_next_scheduled( 'bt_cron_hook' ));
                $next_send->setTimezone(new DateTimeZone('America/Chicago'));
                echo $next_send->format('Y-m-d H:i:s A T');
              ?>
            </div>
          <?php
        }
        ?>
      </div>
    </div>
      <?php
  }

  /***** Custom API EndPoints *****/
  //Function to Create or Update User based on JSON Data passed in - API ENDPOINT
  function create_or_update_user( WP_REST_Request $request  ) {
    $parameters = $request->get_params();

    $user = $request->get_param( 'user' );
    if(!empty($user)){
      $user = $user[0];
    }
    else{
      return false;
    }
    //Whitelist Data Coming In
    $userdata = array(
      'user_login' => $user['username'],
      'user_email' => $user['email']
    );

    if(!empty($user['password'])){
      $userdata['user_pass']  =  $user['password'];
    }
    else{
      //$userdata['user_pass']  =  'MotoTestPassword';
    }


    if(!empty($user['name']))
      $userdata['display_name']  =  $user['name'];
    if(!empty($user['first_name']))
      $userdata['first_name']  =  $user['first_name'];
    if(!empty($user['last_name']))
      $userdata['last_name']  =  $user['last_name'];

    

    $user_id = username_exists( $userdata['user_login'] );
    if ( !$user_id && email_exists($userdata['user_email']) == false ) {
      $user_id = wp_insert_user( $userdata );
    } else {
      $user_id = email_exists( $userdata['user_email'] );
      $userdata['ID'] = $user_id;
      wp_update_user( $userdata );
    }

    $terms = get_user_meta($user_id, 'bt_term_subscription');
    //if Term subscriptions exist already
    if(!empty($terms)){
      //Delete Existing Term subscriptions
      delete_user_meta( $user_id, 'bt_term_subscription');
    }
    //Add New Tag Subscriptions
    foreach(array_column($user['tags'],'id') as $tag){
      add_user_meta($user_id, 'bt_term_subscription', $tag);
    }
    //Add New Category Subscriptions
    foreach(array_column($user['categories'],'id') as $tag){
      add_user_meta($user_id, 'bt_term_subscription', $tag);
    }

    return $user_id;
  }
  //Function to Delete a User from the Database - API ENDPOINT
  function delete_user( WP_REST_Request $request  ) {
    global $wpdb;
    require_once(ABSPATH.'wp-admin/includes/user.php' );

    $parameters = $request->get_params();

    $user = $request->get_param( 'user' );
    if(!empty($user)){
      $user = $user[0];
    }
    else{
      return false;
    }

    //Whitelist Data Coming In
    $userdata = array(
      'user_login'  =>  $user['username'],
      'user_email'  =>  $user['email']
    );
    //Search By username
    $user_id = username_exists( $userdata['user_login'] );
    //Then search by email
    if ( !$user_id && email_exists($userdata['user_email']) == false ) {
      return false;
    } else {
      //If passed, user was found by email, retrieve it 
      $user_id = email_exists( $userdata['user_email'] );
      //Delete Cache data && Remove capabilities from multisite
      wp_delete_user( $user_id );
      //Actually Delete the meta data of the user
      $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE user_id = %d", $user_id) );
      //Actually Delete the user data of the user
      $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->users WHERE ID = %d", $user_id) );
      //If we got here, it didn't fail, return true off of successful delete
      return true;
    }
  }
  public function send_subscription_emails_function(){

    $url = 'https://api.sendgrid.com/';
    $apikey = get_option('sendgrid_api_key');

    $users = get_users(
      array(
        'meta_query' => 
        array(
          array(
            'key'     => 'bt_term_subscription',
          )
        )
      ) 
    );
    $emailed_users = array();
    foreach($users as $user){
      if(in_array($user->ID,$emailed_users)){
        continue;
      }

      $terms = get_user_meta( $user->ID, 'bt_term_subscription');
      $args = array(
        'post_type' => 'post', 
        'post_status'   => 'publish',
        'date_query'    => array(
            'column'  => 'post_date',
            'after'   => '- 1 days'
        ),
        'tax_query' => array(
          'relation' => 'OR',
          array(
              'taxonomy' => 'post_tag',
              'field'    => 'term_id',
              'terms'    => $terms,
              'operation' => 'IN'
          ),
          array(
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => $terms,
            'operation' => 'IN'
          )
        )
      );

      $query = new WP_Query( $args );
      $posts = $query->posts;
      
      
      if(count($posts) < 1){
        continue;
      }
      elseif(count($posts) == 1){
        $subject = '[New Article] ' . $posts[0]->post_title;
      }
      elseif(count($posts) > 1){
        $subject = '[New Articles] Today from Motorola Solutions Blog';
      }

      global $post;
      
      $html = $this->generate_email_content($posts);
      
      $emailed_users[] = $user->ID;


      $json_string = array(

        'to' => array(
          $user->user_email
        ),
        'category' => 'test_category'
      );
      $params = array(
          'x-smtpapi' => json_encode($json_string),
          'subject'   => $subject,
          'html'      => $html,
          'from'      => 'blog@motorolasolutions.com',
          'fromname'  => 'Motorola Solutions Blog',
          'to'        => $user->user_email,
        );
    
        
        $request =  $url.'api/mail.send.json';
        //$request = 'https://api.sendgrid.com/api/mail.send.json';
        // Generate curl request
        $session = curl_init($request);
        
        // Tell curl to use HTTP POST
        curl_setopt ($session, CURLOPT_POST, true);
        // Tell curl that this is the body of the POST
        curl_setopt ($session, CURLOPT_POSTFIELDS, $params);
        // Tell curl not to return headers, but do return the response
        curl_setopt($session, CURLOPT_HEADER, false);
        // Tell PHP not to use SSLv3 (instead opting for TLS)
        curl_setopt($session, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($session, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $apikey));
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    
        // obtain response
        $response = curl_exec($session);

        curl_close($session);

    
        // print everything out
        print_r($response);
    }

    

    
  }
  private function generate_email_content($posts){
    if(count($posts) == 0){
      return false;
    }
    global $post;
    $post = $posts[0];
    setup_postdata( $post );
    $unique_token = '<div style="">'. get_the_date('F j, Y',$post->ID) .'</div>';
    
    $html = 
    '<div style="width:100%;height:100%;background-color:#F2F2F2;padding:20px 0px;">'.
      '<div style="max-width:600px;font-family:arial;overflow:-webkit-paged-x;-webkit-font-smoothing:antialiased;background-color: #FCFCFC;margin:auto;">'.
      '<span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">'.
      get_the_excerpt($post->ID).
      '</span>'.
      '<div style="padding: 10px 40px 0px 40px;">'.
      '<div style="width:100%">'.
        '<a class="ms-logo" title="Motorola Solutions Logo(EN_US)" href="https://www.motorolasolutions.com" >'.
        '<img style="width:270px" src="https://www.motorolasolutions.com/etc/designs/msi/assets/images/components/header-components/header_logo/assets/motoSolutions@2x.png" style="height:100%" width:auto; display:block;>'.
        '</a>'.
      '</div>';
      
      foreach ($posts as $post){
        
        setup_postdata( $post ); 
        //$test = get_post($post->ID);
        $html .= 
        '<h1 style="font-size:29px;font-weight:bold;color:#343434;margin-top:60px;">'.
          '<a style="font-weight:bold;color:#343434;text-decoration:none;" href="'. get_post_permalink($post->ID) .'">'.
            $post->post_title.
          '</a>'.
        '</h1>'.
        '<div class="image-section" style="margin-bottom:20px;">'.'
          <a href="'. get_post_permalink($post->ID) .'">'.
            str_replace('<img','<img style="max-width:520px" ', get_the_post_thumbnail($post->ID)).
          '</a>'.
        '</div>'. 
        '<div class="author-section" style="margin-bottom:20px;font-size:14px;color:#767676;">'.
          $this->generate_author_section($post).
        '</div>'.
        '<div class="article-excerpt-section" style="font-size:16px;color:#767676;margin-bottom:20px;" >'.
          $this->generate_article_excerpt_section($post).  
        '</div>'.
        '<div class="term-section" style="">'.
          $this->generate_post_term_section($post).
        '</div>';
      }
      $html .=

      '</div>'.
          $this->generate_post_footer_section($post).
      '</div>'.
    '</div>';
    

    return $html;


  }
  private function generate_author_section($post){
    $author_meta = get_post_meta($post->ID,'motorola_author',true);
    $author_id = (!empty($author_meta) ? $author_meta[0] : NULL);

    if(!empty($author_id)){
      $author = get_post($author_id);
      $img_url = get_the_post_thumbnail_url($author_id,[50,50]);
    }
    $html = '';
    if(!empty($img_url))
    {
      $html .= "<img src='". $img_url ."' width='50px' height='50px' style='vertical-align: middle;display: inline-table;padding-right:14px'>";
    }

    $html .= "<span style='vertical-align: middle;display: inline-table;'>";
    if(!empty($author))
    {
      $html .= "by ";
      $html .= "<a style=' text-decoration: none;' href='";
      $html .= get_post_permalink($author_id);
      $html .= "'>";
      $html .= $author->post_title ;
      $html .= "</a>";
      $html .= " | ";
    }
    $html .= get_the_date('F j, Y',$post->ID);
    $html .= "</span>" ;

    return $html ;

  }
  private function generate_article_excerpt_section($post){
    $html = get_the_excerpt($post);
    $html = str_replace('<a','<div style="margin-top:20px;"><a style="text-decoration:none;"',$html);
    $html = str_replace('</a>','</a></div>',$html);
    $html = str_replace('Read More','Read Full Article',$html);
    return $html;
  }
  private function generate_post_term_section($post){
    $html = '';
    $categories = get_the_category($post->ID);
    if(!empty($categories))
    {
      $html .= '<div style="font-size:13px; margin-bottom:10px;"><strong style="color:#343434">Industries: </strong>';
      $count = 0;
      foreach($categories as $category){
        $html .= '<a style="text-decoration:none;" href="' . get_site_url() . '/' . $category->slug . '">' . ucwords($category->name) . '</a>';
        if(count($categories) - 1 != $count){
          $html .= ', ';
        }
        $count++;
      }
      $html .= '</div>';
    }
    

    $tags = get_the_tags($post->ID);
    if(!empty($tags))
    {
      $html .= '<div style="font-size:13px;margin-bottom:60px;"><strong style="color:#343434;">Topics: </strong>';
      $count = 0;
      foreach($tags as $tag){
        $html .= '<a style="text-decoration:none;" href="' . get_site_url() . '/tag/' . $tag->slug . '">' . ucwords($tag->name) . '</a>';
        if(count($tags) - 1 != $count){
          $html .= ', ';
        }
        $count++;
      }
      $html .= '</div>';

    }

    return $html;
    
  }
  private function generate_post_footer_section($post){
    $unique_token = '<div style="">'. get_the_date('F j, Y',$post->ID) .'</div>';
    $html = 
    '<div class="post-footer-section" style="margin-left:-40px;padding-top:90px; height:290px; text-decoration:none;">'.
      '<h2 style="font-size:19px; font-weight:300; max-width:456px;margin:auto; text-align:center; margin-bottom:30px; color:#343434">'.
        'Stay up to date with the latest industry and technology insights at blog.motorolasolutions.com.'.
      '</h2>'.
      '<div style="text-align:center;">'.
        '<a style="letter-spacing: 1px; font-size:14px;margin-bottom:80px;padding:20px 26px; background-color:#232323; border:1px #707070 solid; color:#FFFFFF; text-decoration:none;border-radius:40px;display:inline-block;" href="https://blog.motorolasolutions.com">'.
        'Visit our Blog'.
        '</a>'.
      '</div>'.
      '<div style="height:55px;background-color:#0662BE;color:white;text-align:center;">'.
        '<ul style="padding:15px 0px">'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/facebook-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/facebook-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="http://www.facebook.com/MotorolaSolutions" target="_blank" title="Facebook" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/twitter-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/twitter-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="http://twitter.com/motosolutions" target="_blank" title="Twitter" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/linkedin-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/linkedin-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="http://www.linkedin.com/company/motorolasolutions" target="_blank" title="LinkedIn" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/youtube-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/youtube-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="http://www.youtube.com/user/MotorolaSolutions" target="_blank" title="YouTube" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/moto-forums-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/moto-forums-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="http://communities.motorolasolutions.com/welcome" target="_blank" title="Communities" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/instagram-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/instagram-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="https://www.instagram.com/motorolasolutions" target="_blank" title="Instagram" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/periscope-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/periscope-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="https://www.periscope.tv/MotoSolutions" target="_blank" title="Periscope" class=""></a>'.
        '</li>'.
        '<li style="width:30px;height:30px;list-style-type: none; display:inline-block; background-image: url('.plugins_url('moto-rest-api/images/social-footer/google-plus-24.svg').');">'.
          '<a style="background-image: url('.plugins_url('moto-rest-api/images/social-footer/google-plus-24.svg').');background-repeat: no-repeat;display: inline-block;width: 100%;height: 100%;vertical-align: middle;overflow: hidden;top: 0;" href="https://plus.google.com/+motorolasolutions" target="_blank" title="Google+" class=""></a>'.
        '</li>'.
        '</ul>'.
      '</div>'.
      '<div style="background-color:#E6E7E8;color:#767676;text-align:center;padding-top:56px;padding-bottom:48px;">'.
        
        '<div style="font-size:11px;line-height:26px;width:450px;margin:auto;">'.
          'Motorola Solutions values your privacy. See our <a href="https://www.motorolasolutions.com/en_us/about/privacy-policy.html#privacystatement" style="text-decoration:none;">Privacy Policy</a> and <a href="https://www.motorolasolutions.com/en_us/about/terms-use.html" style="text-decoration:none;">Terms of Use</a> You can manage your <a href="https://aem-dev-publish-cdn.motorolasolutions.com/en_us/myaccount/accountpreferences18a/blog" style="text-decoration:none;">subscriptions</a> or <a href="https://aem-dev-publish-cdn.motorolasolutions.com/en_us/myaccount/accountpreferences18a/blog" style="text-decoration:none;">unsubscribe</a> at any time. You may also write to Motorola Solutions, Inc., Attention: Privacy Compliance Program, P.O. Box 59263, Schaumburg, IL USA 60159-0263 or email <span style="text-decoration:none;">privacy1@motorolasolutions.com</span>.'.
        '</div>'.
        '<div style="font-size:10px;padding-top:48px;line-height:16px;width:450px;margin:auto">'.
          'MOTOROLA, MOTO, MOTOROLA SOLUTIONS and the Stylized M Logo are trademarks or registered trademarks of Motorola Trademark Holdings, LLC and are used under license.All other trademarks are the property of their respective owners. © 2018 Motorola Solutions, Inc. All rights reserved.'.
        '</div>'.
      '</div>'.
    '</div>';
    return $html;
  }

}
 
$moto_c_e = new Moto_Custom_Endpoints();

register_activation_hook( __FILE__, 'bt_activate' );
register_deactivation_hook( __FILE__, 'bt_deactivate' );

function bt_activate() {
  if ( ! wp_next_scheduled( 'bt_cron_hook' ) ) {
    wp_schedule_event( strtotime("+1 minutes"), 'daily', 'bt_cron_hook' );
  }  
}

function bt_deactivate() {
  $timestamp = wp_next_scheduled( 'bt_cron_hook' );
  wp_unschedule_event( $timestamp, 'bt_cron_hook' );
}

