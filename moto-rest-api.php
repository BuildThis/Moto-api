<?php
defined( 'ABSPATH' ) or die( 'No script manipulation please!' );
/*
Plugin Name: BuildThis Custom Subscriptions
Description: Creates custom API routes for adding subscription data to users as well as handling sending out email updates to users who have subscribed to different categories and tags with new posts that have recently been published.
Version: 1.0.1
Author: BuildThis
License: GPLv2
*/

class Moto_Custom_Endpoints {
	private $api_namespace = 'sos-moto/v';
	private $base;
	private $api_version = '1';
  private static $instance;
  
  public static function get_instance() {
    if ( ! self::$instance ) {
        self::$instance = new self();
    }
    return self::$instance;
  }
	public function __construct() {

  }
  public function __destruct(){
    
  }

  // Init function to 
	public function init(){
  }

  

  
  //Basic Authentication for API Endpoints
  public static function json_basic_auth_handler( $wpuser ) {
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
  public static function json_basic_auth_error( $error ) {
    // Passthrough other errors
    if ( ! empty( $error ) ) {
      return $error;
    }
    global $wp_json_basic_auth_error;
    return $wp_json_basic_auth_error;
  }
  //Register Functions to API Endpoint Routes
  public static function register_routes() {
		$namespace = 'sos-moto/v' . '1';
		
    register_rest_route( $namespace, 'user/', array(
      'methods' => 'POST',
      'callback' => array( 'Moto_Custom_Endpoints', 'create_or_update_user' ),
      'permission_callback'   => array( 'Moto_Custom_Endpoints', 'set_permission' )

    ) );
    register_rest_route( $namespace, 'user/delete/', array(
      'methods' => "DELETE",
      'callback' => array( 'Moto_Custom_Endpoints', 'delete_user' ),
      'permission_callback'   => array( 'Moto_Custom_Endpoints', 'set_permission' )
    ) );
    register_rest_route( $namespace, 'send_trigger/', array(
      'methods' => "post",
      'callback' => array('Moto_Custom_Endpoints','send_subscription_emails_function') ,
      'permission_callback'   => array( 'Moto_Custom_Endpoints', 'set_permission' )
    ) );
    register_rest_route( 'wp/v2', 'users/', array(
      'methods' => "get",
      'callback' => array('Moto_Custom_Endpoints','all_users') ,
      'permission_callback'   => array( 'Moto_Custom_Endpoints', 'set_permission' )
    ) );
    register_rest_route( 'wp/v2', 'categories/', array(
      'methods' => "get",
      'callback' => array('Moto_Custom_Endpoints','cats_minus_uncat') ,
      'permission_callback'   => ''
    ) );
    register_rest_route( 'wp/v2', 'tags/', array(
      'methods' => "get",
      'callback' => array('Moto_Custom_Endpoints','all_tags') ,
      'permission_callback'   => ''
    ) );

  }
  //Permissions Callback for the API Endpoints
  public static function set_permission(){
    if ( ! current_user_can( 'edit_users' ) ) {
          return new WP_Error( 'rest_forbidden', esc_html__( 'You do not have permissions to perform this action.', 'my-text-domain' ), array( 'status' => 401 ) );
      }
 
      // This approach blocks the endpoint operation. You could alternatively do this by an un-blocking approach, by returning false here and changing the permissions check.
      return true;
  }
  //Set Options needed for the plugin
  public static function register_moto_settings(){
    register_setting('bt-moto','sendgrid_api_key');
    register_setting('bt-moto','moto_email_trigger_timestamp');
  }
  //Adds The BT-Moto Options Link to The Menu
  public static function bt_moto_menu() {
    add_menu_page( 'BT-Moto Options', 'BT-Moto', 'edit_pages', 'bt-moto', array('Moto_Custom_Endpoints','bt_moto_options'),plugins_url('moto-rest-api/images/Codey-20x20.png'),4);
  }
  //Adds the content for the BT-Moto Options Page
  public static function bt_moto_options() {
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
        <h2>Last Sent Email:</h2>
        <?php
        if( esc_attr( get_option('moto_email_trigger_timestamp') ) ){
          ?>
            <div> 
              <?php 
                $next_send =  new DateTime(esc_attr( get_option('moto_email_trigger_timestamp') ));
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
      //echo '<pre>'; print_r( _get_cron_array() ); echo '</pre>';
  }
  /***** Custom API EndPoints *****/
  //Function to Create or Update User based on JSON Data passed in - API ENDPOINT
  public static function create_or_update_user( WP_REST_Request $request  ) {
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
  public static function delete_user( WP_REST_Request $request  ) {
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
  public static function cats_minus_uncat(){
    $category_to_exclude = get_category_by_slug('uncategorized');
    $categories = get_categories( array(
      'orderby' => 'id',
      'exclude' => array($category_to_exclude->term_id)
    ) );
    return $categories;
  }
  public static function all_tags(){
    //$category_to_exclude = get_category_by_slug('uncategorized');
    $tags = get_tags();
    return $tags;
  }
  public static function all_users(){
    $users = get_users();
    return $users;
  }
  public static function send_subscription_emails_function( ) {

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
      
      $html = Moto_Custom_Endpoints::generate_email_content($posts);
      
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
        //Commented out my section
        curl_setopt($session, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($session, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $apikey));
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    
        // obtain response
        $response = curl_exec($session);
  
        curl_close($session);
  
    
        // print everything out
        print_r($response);
    }
    return true;

  }
  public static function generate_email_content($posts){
    if(count($posts) == 0){
      return false;
    }
    global $post;
    $post = $posts[0];
    setup_postdata( $post );
    $unique_token = '<div style="">'. get_the_date('F j, Y',$post->ID) .'</div>';
    $html = '<table width="100%" bgcolor="#F2F2F2" border="0" cellpadding="0" cellspacing="0" style="padding:20px 0px;">'.
        '<tr>'.
           '<td>'.
              '<table width="600px" bgcolor="#FFFFFF" align="center" cellpadding="0" cellspacing="0"  border="0" style="max-width:600px;font-family:arial;overflow:-webkit-paged-x;-webkit-font-smoothing:antialiased;background-color: #FCFCFC;margin:auto;">'.
                 '<tr>'.
                    '<td>'. 
                       '<span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">'.
                       get_the_excerpt($post->ID).
                       '</span>'.
                       '<table border="0" cellpadding="0" cellspacing="0" >'.
                          '<tr>'.
                             '<td style="padding: 10px 40px 0px 40px;">'.
                                '<div style="width:100%"><a class="ms-logo" title="Motorola Solutions Logo(EN_US)" href="http://www.motorolasolutions.com"><img style="width:270px;height:31px" width="270" src="https://www.motorolasolutions.com/etc/designs/msi/assets/images/components/header-components/header_logo/assets/motoSolutions@2x.png"></a></div>';


      
      foreach ($posts as $post){
        
        setup_postdata( $post ); 
        //$test = get_post($post->ID);
        $html .=
        '<!--[if (gte mso 9)|(IE)]>'.
          '<table><tr><td width="520">'.
         '<![endif]-->'.
        '<div style="width:100%;">'.
          '<h1 class="test" style="font-size:29px;font-weight:bold;color:#343434;margin-top:60px;display:block;"><a style="font-weight:bold;color:#343434;text-decoration:none;display:block;" href="'. get_post_permalink($post->ID) .'">' . $post->post_title . '</a></h1>'.
             '</div>'.

           '<div class="image-section" style="margin-bottom:20px;">'.
          '<a href="'. get_post_permalink($post->ID) .'">'. 
           str_replace('<img','<img style="max-width:520px;width:520px;" width="520" ', get_the_post_thumbnail($post->ID)).
           '</a>'.
          '</div>'.
              '<!--[if (gte mso 9)|(IE)]>'.
               '</td></tr></table>'.
              '<![endif]-->'.
      '<div class="author-section" style="margin-bottom:20px;font-size:14px;color:#767676;">'.
          Moto_Custom_Endpoints::generate_author_section($post).
        '</div>'.
         '<!--[if (gte mso 9)|(IE)]>'.
         '<table><tr><td width="520">'.
        '<![endif]--> '.
        '<div class="article-excerpt-section" style="mso-default-width:100px;font-size:16px;color:#767676;margin-bottom:20px;width:100%">'.
          Moto_Custom_Endpoints::generate_article_excerpt_section($post).  
        '</div>'.
        '<!--[if (gte mso 9)|(IE)]>'.
        '</td></tr></table>'.
        '<![endif]-->'.       

        '<div class="term-section" style="">'.
          Moto_Custom_Endpoints::generate_post_term_section($post).
        '</div>';
      }
      $html .=

        '</td></tr>'.
        '<tr><td width="520">'.
        Moto_Custom_Endpoints::generate_post_footer_section($post).
        '</td></tr></table>'.
      '</td></tr></table>'.
    '</td></tr></table>';
    
    return $html;


  }
  public static function generate_author_section($post){
    $author_meta = get_post_meta($post->ID,'motorola_author',true);
    $author_id = (!empty($author_meta) ? $author_meta[0] : NULL);

    if(!empty($author_id)){
      $author = get_post($author_id);
      $img_url = get_the_post_thumbnail_url($author_id,[50,50]);
    }
    $html = '';
    if(!empty($img_url))
    {
      $html .= "<img src='". $img_url ."' width='50' height='50' style='vertical-align: middle;display: inline-table;padding-right:14px;width:50px;height:50px;'>";
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
  public static function generate_article_excerpt_section($post){
    $html = get_the_excerpt($post);
    $html = str_replace('<a','<div style="margin-top:20px;"><a style="text-decoration:none;"',$html);
    $html = str_replace('</a>','</a></div>',$html);
    $html = str_replace('Read More','Read Full Article',$html);
    return $html;
  }
  public static function generate_post_term_section($post){
    $html = '';
    $categories = get_the_category($post->ID);
    if(!empty($categories))
    {
      $html .= '<div style="font-size:13px; margin-bottom:10px;"><strong style="color:#343434">Industries: </strong>';
      $count = 0;
      foreach($categories as $category){
        $html .= '<a style="text-decoration:none;" href="' . get_site_url() . '/' . $category->slug . '">' . $category->name . '</a>';
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
        $html .= '<a style="text-decoration:none;" href="' . get_site_url() . '/topic/' . $tag->slug . '">' . $tag->name . '</a>';
        if(count($tags) - 1 != $count){
          $html .= ', ';
        }
        $count++;
      }
      $html .= '</div>';

    }

    return $html;
    
  }
  public static function generate_post_footer_section($post){
    $html = 

    '<div class="post-footer-section" style="padding-top:90px; text-decoration:none;">'.
      '<h2 style="font-size:19px; font-weight:300; max-width:456px;margin:auto;padding-left:12%;padding-right:12%; text-align:center; margin-bottom:30px; color:#343434">'.
        'Stay up to date with the latest industry and technology insights at blog.motorolasolutions.com.'.
      '</h2>'.
      '<!--[if gte mso 11]>'.
      '<div style="text-align:center;">'.
        '<a style="line-height: 1.86;letter-spacing: 1.29px;margin: auto;font-size:14px; text-transform: uppercase; margin-bottom:80px; color:black; padding:12px 32px; background-color:white;border:1px #707070 solid; text-decoration:none;border-radius:40px;display:inline-block;overflow:auto;" href="https://blog.motorolasolutions.com">'.
        'Visit our Blog'.
        '</a>'.
      '</div>'.
      '<div style="clear:both;margin:20px 0;overflow:auto;">&nbsp;</div>'.
      '<![endif]-->'.
      '<!--[if !gte mso 11]><!---->'.
      '<div style="text-align:center;"><a style="line-height: 1.86;letter-spacing: 1.29px; margin: auto; font-size:14px; text-transform: uppercase; margin-bottom:80px; color:#FFFFFF; padding:12px 32px; background-color:#232323;border:1px #707070 solid; text-decoration:none;border-radius:40px;display:inline-block;overflow:auto;" href="https://blog.motorolasolutions.com">'.
      'Visit our Blog'.
        '</a>'.
      '</div>'.
      '<!--<![endif]-->'.
      '<!--[if gte mso 11]>'.
      '<div style="height:30px;background-color:#0662BE;color:white;text-align:center;overflow:hidden;width:100%;clear:both;">'.
      '<![endif]-->'.
      '<!--[if !gte mso 11]><!---->'.
      '<div style="height:60px;background-color:#0662BE;color:white;text-align:center;overflow:hidden;width:100%;">'.
      '<!--<![endif]-->'.
      '<table style="background-color:#0662BE;width:600px;" align="center" cellpadding="0" cellspacing="15" border="0">'.
        '<tr>'.
        '<td width="24px" height="24px" style="display:cell-table;">&nbsp;</td>'.
        '<td width="24px" height="24px" style="display:cell-table;">&nbsp;</td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="http://www.facebook.com/MotorolaSolutions" target="_blank" title="Facebook" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/facebook-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="http://twitter.com/motosolutions" target="_blank" title="Twitter" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/twitter-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="http://www.linkedin.com/company/motorolasolutions" target="_blank" title="LinkedIn" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/linkedin-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="http://www.youtube.com/user/MotorolaSolutions" target="_blank" title="YouTube" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/youtube-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="http://communities.motorolasolutions.com/welcome" target="_blank" title="Communities" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/moto-forums-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="https://www.instagram.com/motorolasolutions" target="_blank" title="Instagram" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/instagram-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="https://www.periscope.tv/MotoSolutions" target="_blank" title="Periscope" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/periscope-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px"style="display:cell-table;"><a style="margin-left:10px;display: inline-block;width: 30px;height: 30px;vertical-align: middle;overflow: hidden;text-align:center;" href="https://plus.google.com/+motorolasolutions" target="_blank" title="Google+" class=""><img src="'.plugins_url('moto-rest-api/images/social-footer/google-plus-x1.png').'" /></a></td>'.
        '<td width="24px" height="24px" style="display:cell-table;">&nbsp;</td>'.
        '<td width="24px" height="24px" style="display:cell-table;">&nbsp;</td>'.
        '</tr></table>'.
      '</div>'.
      '<div style="clear:both;"></div>'.
      '<table style="background-color:#E6E7E8;color:#767676;text-align:center;padding-top:56px;padding-bottom:48px;padding-left:10%;padding-right:10%;">'.
        '<tr>'.
          '<td style="font-size:11px;line-height:26px;width:100%;margin:auto;">'.
            'Motorola Solutions values your privacy. See our <a href="https://www.motorolasolutions.com/en_us/about/privacy-policy.html#privacystatement" style="text-decoration:none;">Privacy Policy</a> and <a href="https://www.motorolasolutions.com/en_us/about/terms-use.html" style="text-decoration:none;">Terms of Use</a>. You can manage your <a href="https://www.motorolasolutions.com/en_us/myaccount/accountpreferences/blog" style="text-decoration:none;">subscriptions</a> or <a href="https://www.motorolasolutions.com/en_us/myaccount/accountpreferences/blog" style="text-decoration:none;">unsubscribe</a> at any time. You may also write to Motorola Solutions, Inc., Attention: Privacy Compliance Program, P.O. Box 59263, Schaumburg, IL USA 60159-0263 or email <span style="text-decoration:none;">privacy1@motorolasolutions.com</span>.'.
          '</td>'.
        '</tr>'.
        '<tr>'.
          '<td style="font-size:10px;padding-top:48px;line-height:16px;width:100%;margin:auto">'.
            'MOTOROLA, MOTO, MOTOROLA SOLUTIONS and the Stylized M Logo are trademarks or registered trademarks of Motorola Trademark Holdings, LLC and are used under license. All other trademarks are the property of their respective owners. © 2021 Motorola Solutions, Inc. All rights reserved.'.
          '</td>'.
        '</tr>'.
      '</table>'.
    '</div>';
    return $html;
  }

  public static function bt_activate() {
    Moto_Custom_Endpoints::send_subscription_emails_function();
    if ( ! wp_next_scheduled( 'send_subscription_emails_function' ) ) {
      wp_schedule_event( strtotime("+1 minutes"), 'daily', 'send_subscription_emails_function' );
    }  
  }
  
  public static function bt_deactivate() {
    $timestamp = wp_next_scheduled( 'send_subscription_emails_function' );
    wp_unschedule_event( $timestamp, 'send_subscription_emails_function' );
  }

}
 
$moto_c_e = Moto_Custom_Endpoints::get_instance();



//Add Action to Register Plugin Specific Settings
add_action( 'admin_init',array('Moto_Custom_Endpoints', 'register_moto_settings') );
//Add Action to Add Options Menu Link
add_action( 'admin_menu', array('Moto_Custom_Endpoints', 'bt_moto_menu' ));
//Add Action to Register REST Endpoints
add_action( 'rest_api_init', array( 'Moto_Custom_Endpoints', 'register_routes' ) );
//Add Filter for Authentication for the API
add_filter( 'determine_current_user', array( 'Moto_Custom_Endpoints', 'json_basic_auth_handler'), 20 );
//Add Filter for handling Authentication Errors for the API
add_filter( 'rest_authentication_errors', array( 'Moto_Custom_Endpoints', 'json_basic_auth_error'), 20 );


register_activation_hook( __FILE__, array( 'Moto_Custom_Endpoints', 'bt_activate' ) );


