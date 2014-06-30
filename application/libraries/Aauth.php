<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Aauth is a User Authorization Library for CodeIgniter 2.x, which aims to make 
 * easy some essential jobs such as login, permissions and access operations. 
 * Despite ease of use, it has also very advanced features like private messages, 
 * groupping, access management, public access etc..
 *
 * @author      Emre Akay <emreakayfb@hotmail.com>
 * @contributor Jacob Tomlinson
 *
 * @copyright 2014 Emre Akay
 *
 * @version 1.0
 *
 * @license LGPL
 * @license http://opensource.org/licenses/LGPL-3.0 Lesser GNU Public License
 *
 * The latest version of Aauth can be obtained from:
 * https://github.com/emreakay/CodeIgniter-Aauth
 *
 *
 *
 */
class Aauth {

    /**
     * The CodeIgniter object variable
     * @var object
     */
    public $CI;

    /**
     * Variable for loading the config array into
     * @var array
     */
    public $config_vars;

    /**
     * Array to store error messages
     * @var array
     */
    public $errors = array();

    /**
     * Array to store info messages
     * @var array
     */
    public $infos = array();

    ########################
    # Base Functions
    ########################

    /**
     * Constructor
     */
    public function __construct() {

        // Delete all errors at first
        $this->errors = array();

        // get main CI object
        $this->CI = & get_instance();

        // Dependancies
        $this->CI->load->library('session');
        $this->CI->load->library('email');
        $this->CI->load->database();
        $this->CI->load->helper('url');
        $this->CI->load->helper('string');
        $this->CI->load->helper('email');


        // config/aauth.php
        $this->CI->config->load('aauth');
        $this->config_vars = & $this->CI->config->item('aauth');
    }

    /**
     * Hash password
     * Hash the password for storage in the database
     * (thanks to Jacob Tomlinson for contribution)
     * @param string $pass Password to hash
     * @param $userid
     * @return string Hashed password
     */
    function hash_password($pass, $userid) {

        $salt = md5($userid);
        return hash('sha256', $salt.$pass);
    }

    ########################
    # User Functions
    ########################

    /**
     * Login user
     * Check provided details against the database. Add items to error array on fail, create session if success
     * @param string $email
     * @param string $pass
     * @param bool $remember
     * @return bool Indicates successful login.
     */
    public function login($email, $pass, $remember = FALSE) {

        // Remove cookies first
        $cookie = array(
            'name'   => 'user',
            'value'  => '',
            'expire' => time()-3600,
            'path'   => '/',
        );

        $this->CI->input->set_cookie($cookie);

        if( !valid_email($email) or !ctype_alnum($pass) or strlen($pass) < 5 or strlen($pass) > $this->config_vars['max'] ) {
            $this->error($this->config_vars['wrong']);
            return false;}

        $query = $this->CI->db->where('email', $email);
        $query = $this->CI->db->get($this->config_vars['users']);

        $user_id = $query->row()->id;

        if ($query->num_rows() > 0) {
            $row = $query->row();

            // DDos protection
            if ( $this->config_vars['dos_protection'] and $row->last_login_attempt != '' and (strtotime("now") + 30 * $this->config_vars['try'] ) < strtotime($row->last_login_attempt) ) {
                $this->error($this->config_vars['exceeded']);
                return false;
            }
        }

        // banned or nor verified
        $query = null;
        $query = $this->CI->db->where('email', $email);
        $query = $this->CI->db->where('banned', 1);
        $query = $this->CI->db->where('verification_code !=', '');
        $query = $this->CI->db->get($this->config_vars['users']);

        if ($query->num_rows() > 0) {
            $this->error($this->config_vars['not_verified']);
            return false;
        }

        $query = null;
        $query = $this->CI->db->where('email', $email);

        // Database stores pasword hashed password
        $query = $this->CI->db->where('pass', $this->hash_password($pass, $user_id));
        $query = $this->CI->db->where('banned', 0);
        $query = $this->CI->db->get($this->config_vars['users']);

        $row = $query->row();

        if ($query->num_rows() > 0) {

            // If email and pass matches
            // create session
            $data = array(
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'loggedin' => TRUE
            );

            $this->CI->session->set_userdata($data);

            // if remember selected
            if ($remember){
                $expire = $this->config_vars['remember'];
                $today = date("Y-m-d");
                $remember_date = date("Y-m-d", strtotime($today . $expire) );
                $random_string = random_string('alnum', 16);
                $this->update_remember($row->id, $random_string, $remember_date );

                $cookie = array(
                    'name'   => 'user',
                    'value'  => $row->id . "-" . $random_string,
                    'expire' => time() + 99*999*999,
                    'path'   => '/',
                );

                $this->CI->input->set_cookie($cookie);
            }

            // update last login
            $this->update_last_login($row->id);
            $this->update_activity();

            return TRUE;

        } else {

            $query = $this->CI->db->where('email', $email);
            $query = $this->CI->db->get($this->config_vars['users']);
            $row = $query->row();

            if ($query->num_rows() > 0) {

                if ( $row->last_login_attempt == null or  (strtotime("now") - 600) > strtotime($row->last_login_attempt) )
                {
                    $data = array(
                        'last_login_attempt' =>  date("Y-m-d H:i:s")
                    );

                } else if (!($row->last_login_attempt != '' and (strtotime("now") + 30 * $this->config_vars['try'] ) < strtotime($row->last_login_attempt))) {

                    $newtimestamp = strtotime("$row->last_login_attempt + 30 seconds");
                    $data = array(
                        'last_login_attempt' =>  date( 'Y-m-d H:i:s', $newtimestamp )
                    );
                }

                $query = $this->CI->db->where('email', $email);
                $this->CI->db->update($this->config_vars['users'], $data);
            }

            $this->error($this->config_vars['wrong']);
            return FALSE;
        }
    }

    /**
     * Check user login
     * Checks if user logged in, also checks remember.
     * @return bool
     */
    public function is_loggedin() {

        if ( $this->CI->session->userdata('loggedin') )
        { return true; }

        // cookie control
        else {
            if( ! $this->CI->input->cookie('user', TRUE) ){
                return false;
            } else {
                $cookie = explode('-', $this->CI->input->cookie('user', TRUE));
                if(!is_numeric( $cookie[0] ) or strlen($cookie[1]) < 13 ){return false;}
                else{
                    $query = $this->CI->db->where('id', $cookie[0]);
                    $query = $this->CI->db->where('remember_exp', $cookie[1]);
                    $query = $this->CI->db->get($this->config_vars['users']);

                    $row = $query->row();

                    if ($query->num_rows() < 1) {
                        $this->update_remember($cookie[0]);
                        return false;
                    }else{

                        if(strtotime($row->remember_time) > strtotime("now") ){
                            $this->login_fast($cookie[0]);
                            return true;
                        }
                        // if time is expired
                        else {
                            return false;
                        }
                    }
                }

            }
        }

        return false;
    }

    /**
     * Controls if a logged or public user has permission
     * If no permission, it stops script, it also updates last activity every time function called
     * @param bool $perm_par If not given just control user logged in or not
     */
    public function control( $perm_par ){

        // if perm_par is given
        $perm_id = $this->get_perm_id($perm_par);
        $this->update_activity();

        // if user or user's group allowed
        if ( !$this->is_allowed($perm_id) or !$this->is_group_allowed($perm_id)){
            echo $this->config_vars['no_access'];
            die();
        }

    }

    /**
     * Logout user
     * Destroys the CodeIgniter session to log out user.
     * @return bool If session destroy successful
     */
    public function logout() {

        return $this->CI->session->sess_destroy();
    }

    /**
     * List users
     * Return users as an object array
     * @param bool|int $group_par Specify group id to list group or false for all users
     * @param string $limit Limit of users to be returned
     * @param bool $offset Offset for limited number of users
     * @param bool $include_banneds Include banned users
     * @return array Array of users
     */
    public function list_users($group_par = FALSE, $limit = FALSE, $offset = FALSE, $include_banneds = FALSE) {

        // if group_par is given
        if ($group_par != FALSE) {

            $group_par = $this->get_group_id($group_par);
            $this->CI->db->select('*')
                ->from($this->config_vars['users'])
                ->join($this->config_vars['user_to_group'], $this->config_vars['users'] . ".id = " . $this->config_vars['user_to_group'] . ".user_id")
                ->where($this->config_vars['user_to_group'] . ".group_id", $group_par);

        // if group_par is not given, lists all users
        } else {

            $this->CI->db->select('*')
                ->from($this->config_vars['users']);
        }

        // banneds
        if (!$include_banneds) {
            $this->CI->db->where('banned != ', 1);
        }

        // limit
        if ($limit) {

            if ($offset == FALSE)
                $this->CI->db->limit($limit);
            else
                $this->CI->db->limit($limit, $offset);
        }

        $query = $this->CI->db->get();

        return $query->result();
    }

    /**
     * Fast login
     * Login with just a user id
     * @param int $user_id User id to log in
     */
    public function login_fast($user_id){

        $query = $this->CI->db->where('id', $user_id);
        $query = $this->CI->db->where('banned', 0);
        $query = $this->CI->db->get($this->config_vars['users']);

        $row = $query->row();

        if ($query->num_rows() > 0) {

            // if id matches
            // create session
            $data = array(
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'loggedin' => TRUE
            );

            $this->CI->session->set_userdata($data);
        }
    }

    /**
     * Create user
     * Creates a new user
     * @param string $email User's email address
     * @param string $pass User's password
     * @param string $name User's name
     * @return int|bool False if create fails or returns user id if successful
     */
    public function create_user($email, $pass, $name='') {

        $valid = true;

        if (!$this->check_email($email)) {
            $this->error($this->config_vars['email_taken']);
            $valid = false;
        }
        if (!valid_email($email)){
            $this->error($this->config_vars['email_invalid']);
            $valid = false;
        }
        if (strlen($pass) < 5 or strlen($pass) > $this->config_vars['max'] ){
            $this->error($this->config_vars['pass_invalid']);
            $valid = false;
        }
        if ($name !='' and !ctype_alnum(str_replace($this->config_vars['valid_chars'], '', $name))){
            $this->error($this->config_vars['name_invalid']);
            $valid = false;
        }

        if (!$valid) { return false; }

        $data = array(
            'email' => $email,
            'pass' => $this->hash_password($pass, 0), // Password cannot be blank but user_id required for salt, setting bad password for now
            'name' => $name,
        );

        if ( $this->CI->db->insert($this->config_vars['users'], $data )){

            $user_id = $this->CI->db->insert_id();

            // set default group
            $this->add_member($user_id, $this->config_vars['default_group']);

            // if verification activated
            if($this->config_vars['verification']){
                $data = null;
                $data['banned'] = 1;

                $this->CI->db->where('id', $user_id);
                $this->CI->db->update($this->config_vars['users'], $data);

                // sends verifition ( !! e-mail settings must be set)
                $this->send_verification($user_id);
            }

            // Update to correct salted password
            $data = null;
            $data['pass'] = $this->hash_password($pass, $user_id);
            $this->CI->db->where('id', $user_id);
            $this->CI->db->update($this->config_vars['users'], $data);

            return $user_id;

        } else {
            return FALSE;
        }
    }

    /**
     * Update user
     * Updates existing user details
     * @param int $user_id User id to update
     * @param string|bool $email User's email address, or false if not to be updated
     * @param string|bool $pass User's password, or false if not to be updated
     * @param string|bool $name User's name, or false if not to be updated
     * @return bool Update fails/succeeds
     */
    public function update_user($user_id, $email = FALSE, $pass = FALSE, $name = FALSE) {

        $data = array();

        if ($email != FALSE) {
            $data['email'] = $email;
        }

        if ($pass != FALSE) {
            $data['pass'] = $this->hash_password($pass, $user_id);
        }

        if ($name != FALSE) {
            $data['name'] = $name;
        }

        $this->CI->db->where('id', $user_id);
        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Send verification email
     * Sends a verification email based on user id
     * @param int $user_id User id to send verification email to
     */
    public function send_verification($user_id){

        $query = $this->CI->db->where( 'id', $user_id );
        $query = $this->CI->db->get( $this->config_vars['users'] );

        if ($query->num_rows() > 0){
            $row = $query->row();

            $ver_code = random_string('alnum', 16);

            $data['verification_code'] = $ver_code;

            $this->CI->db->where('id', $user_id);
            $this->CI->db->update($this->config_vars['users'], $data);

            $this->CI->email->from( $this->config_vars['email'], $this->config_vars['name']);
            $this->CI->email->to($row->email);
            $this->CI->email->subject($this->config_vars['email']);
            $this->CI->email->message($this->config_vars['code'] . $ver_code .
            $this->config_vars['link'] . $user_id . '/' . $ver_code );
            $this->CI->email->send();
        }
    }

    /**
     * Verify user
     * Activates user account based on verification code
     * @param int $user_id User id to activate
     * @param string $ver_code Code to validate against
     * @return bool Activation fails/succeeds
     */
    public function verify_user($user_id, $ver_code){

        $query = $this->CI->db->where('id', $user_id);
        $query = $this->CI->db->where('verification_code', $ver_code);
        $query = $this->CI->db->get( $this->config_vars['users'] );

        if( $query->num_rows() >0 ){

            $data =  array(
                'verification_code' => '',
                'banned' => 0
            );

            $this->CI->db->where('id', $user_id);
            $this->CI->db->update($this->config_vars['users'] , $data);
            return true;
        }
        return false;
    }

    /**
     * Reset last login attempts
     * Sets a users 'last login attempts' to null
     * @param int $user_id User id to reset
     * @return bool Reset fails/succeeds
     */
    public  function reset_login_attempts($user_id) {

        $data['last_login_attempts'] = null;
        $this->CI->db->where('id', $user_id);
        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Ban user
     * Bans a user account
     * @param int $user_id User id to ban
     * @return bool Ban fails/succeeds
     */
    public function ban_user($user_id) {

        $data = array(
            'banned' => 1
        );

        $this->CI->db->where('id', $user_id);

        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Unban user
     * Activates user account
     * Same with unban_user()
     * @param int $user_id User id to activate
     * @return bool Activation fails/succeeds
     */
    public function unlock_user($user_id) {

        $data = array(
            'banned' => 0
        );

        $this->CI->db->where('id', $user_id);

        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Unban user
     * Activates user account
     * Same with unlock_user()
     * @param int $user_id User id to activate
     * @return bool Activation fails/succeeds
     */
    public function unban_user($user_id) {

        return $this->unlock_user($user_id);
    }


    /**
     * Check user banned
     * Checks if a user is banned
     * @param int $user_id User id to check
     * @return bool Flase if banned, True if not
     */
    public function is_banned($user_id) {

        $query = $this->CI->db->where('id', $user_id);
        $query = $this->CI->db->where('banned', 1);

        $query = $this->CI->db->get($this->config_vars['users']);

        if ($query->num_rows() > 0)
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Delete user
     * Delete a user from database. WARNING Can't be undone
     * @param int $user_id User id to delete
     */
    public function delete_user($user_id) {

        $this->CI->db->where('id', $user_id);
        $this->CI->db->delete($this->config_vars['users']);
    }

    /**
     * Check email
     * Checks if an email address is available
     * @param string $email Email to check
     * @return bool True if available, False if not
     */
    public function check_email($email) {

        $this->CI->db->where("email", $email);
        $query = $this->CI->db->get($this->config_vars['users']);

        if ($query->num_rows() > 0) {
            $this->info($this->config_vars['email_taken']);
            return FALSE;
        }
        else
            return TRUE;
    }

    /**
     * Remind password
     * Emails user with link to reset password
     * @param string $email Email for account to remind
     */
    public function remind_password($email){

        $query = $this->CI->db->where( 'email', $email );
        $query = $this->CI->db->get( $this->config_vars['users'] );

        if ($query->num_rows() > 0){
            $row = $query->row();

            $ver_code = random_string('alnum', 16);

            $data['verification_code'] = $ver_code;

            $this->CI->db->where('email', $email);
            $this->CI->db->update($this->config_vars['users'], $data);

            $this->CI->email->from( $this->config_vars['email'], $this->config_vars['name']);
            $this->CI->email->to($row->email);
            $this->CI->email->subject($this->config_vars['reset']);
            $this->CI->email->message($this->config_vars['remind'] . ' ' .
            $this->config_vars['remind'] . $row->id . '/' . $ver_code );
            $this->CI->email->send();
        }
    }

    /**
     * Reset password
     * Generate new password and email it to the user
     * @param int $user_id User id to reset password for
     * @param string $ver_code Verification code for account
     * @return bool Password reset fails/succeeds
     */
    public function reset_password($user_id, $ver_code){

        $query = $this->CI->db->where('id', $user_id);
        $query = $this->CI->db->where('verification_code', $ver_code);
        $query = $this->CI->db->get( $this->config_vars['users'] );

        $pass = random_string('alphanum',8);

        if( $query->num_rows() > 0 ){

            $data =  array(
                'verification_code' => '',
                'pass' => $this->hash_password($pass, $user_id)
            );

            $row = $query->row();
            $email = $row->email;

            $this->CI->db->where('id', $user_id);
            $this->CI->db->update($this->config_vars['users'] , $data);

            $this->CI->email->from( $this->config_vars['email'], $this->config_vars['name']);
            $this->CI->email->to($email);
            $this->CI->email->subject($this->config_vars['reset']);
            $this->CI->email->message($this->config_vars['new_password'] . $pass);
            $this->CI->email->send();

            return true;
        }

        return false;
    }

    /**
     * Update activity
     * Update user's last activity date
     * @param int|bool $user_id User id to update or false for current user
     * @return bool Update fails/succeeds
     */
    public function update_activity($user_id = FALSE) {

        if ($user_id == FALSE)
            $user_id = $this->CI->session->userdata('id');

        if($user_id==false){return false;}

        $data['last_activity'] = date("Y-m-d H:i:s");

        $query = $this->CI->db->where('id',$user_id);
        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Update last login
     * Update user's last login date
     * @param int|bool $user_id User id to update or false for current user
     * @return bool Update fails/succeeds
     */
    public function update_last_login($user_id = FALSE) {

        if ($user_id == FALSE)
            $user_id = $this->CI->session->userdata('id');

        $data['last_login'] = date("Y-m-d H:i:s");
        $data['ip_address'] = $this->CI->input->ip_address();

        $this->CI->db->where('id', $user_id);
        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Update remember
     * Update amount of time a user is remembered for
     * @param int $user_id User id to update 
     * @param int $expression
     * @param int $expire 
     * @return bool Update fails/succeeds
     */
    public function update_remember($user_id, $expression=null, $expire=null) {

        $data['remember_time'] = $expire;
        $data['remember_exp'] = $expression;

        $query = $this->CI->db->where('id',$user_id);
        return $this->CI->db->update($this->config_vars['users'], $data);
    }

    /**
     * Get user
     * Get user information
     * @param int|bool $user_id User id to get or false for current user
     * @return object User information
     */
    public function get_user($user_id = FALSE) {

        if ($user_id == FALSE)
            $user_id = $this->CI->session->userdata('id');

        $query = $this->CI->db->where('id', $user_id);
        $query = $this->CI->db->get($this->config_vars['users']);

        if ($query->num_rows() <= 0){
            $this->error($this->config_vars['no_user']);
            return FALSE;
        }
        return $query->row();
    }

    /**
     * Get user id
     * Get user id from email address
     * @param string $email Email address for user
     * @return int User id
     */
    public function get_user_id($email=false) {

        if(!$email){
            $query = $this->CI->db->where('id', $this->CI->session->userdata('id'));
        } else {
            $query = $this->CI->db->where('email', $email);
        }

        $query = $this->CI->db->get($this->config_vars['users']);

        if ($query->num_rows() <= 0){
            $this->error($this->config_vars['no_user']);
            return FALSE;
        }
        return $query->row()->id;
    }

    /**
     * Get user groups
     * Get groups a user is in
     * @param int|bool $user_id User id to get or false for current user
     * @return array Groups
     */
    public function get_user_groups($user_id = false){

        if ($user_id==false) { $user_id = $this->CI->session->userdata('id'); }

        $this->CI->db->select('*');
        $this->CI->db->from($this->config_vars['user_to_group']);
        $this->CI->db->join($this->config_vars['groups'], "id = group_id");
        $this->CI->db->where('user_id', $user_id);

        return $query = $this->CI->db->get()->result();
    }

    ########################
    # Group Functions
    ########################

    /**
     * Create group
     * Creates a new group
     * @param string $group_name New group name
     * @return int|bool Group id or false on fail
     */
    public function create_group($group_name) {

        $query = $this->CI->db->get_where($this->config_vars['groups'], array('name' => $group_name));

        if ($query->num_rows() < 1) {

            $data = array(
                'name' => $group_name
            );
            $this->CI->db->insert($this->config_vars['groups'], $data);
            return $this->CI->db->insert_id();
        }

        $this->error($this->config_vars['group_exist']);
        return FALSE;
    }

    /**
     * Update group
     * Change a groups name
     * @param int $group_id Group id to update
     * @param string $group_name New group name
     * @return bool Update success/failure
     */
    public function update_group($group_id, $group_name) {

        $data['name'] = $group_name;

        $this->CI->db->where('id', $group_id);
        return $this->CI->db->update($this->config_vars['groups'], $data);
    }

    /**
     * Delete group
     * Delete a group from database. WARNING Can't be undone
     * @param int $group_id User id to delete
     * @return bool Delete success/failure
     */
    public function delete_group($group_id) {

        // bug fixed
        // now users are deleted from user_to_group table
        $this->CI->db->where('group_id', $group_id);
        $this->CI->db->delete($this->config_vars['user_to_group']);

        $this->CI->db->where('id', $group_id);
        return $this->CI->db->delete($this->config_vars['groups']);
    }

    /**
     * Add member
     * Add a user to a group
     * @param int $user_id User id to add to group
     * @param int|string $group_par Group id or name to add user to
     * @return bool Add success/failure
     */
    public function add_member($user_id, $group_par) {

        $group_par = $this->get_group_id($group_par);

        $query = $this->CI->db->where('user_id',$user_id);
        $query = $this->CI->db->where('group_id',$group_par);
        $query = $this->CI->db->get($this->config_vars['user_to_group']);

        if ($query->num_rows() < 1) {
            $data = array(
                'user_id' => $user_id,
                'group_id' => $group_par
            );

            return $this->CI->db->insert($this->config_vars['user_to_group'], $data);
        }
        $this->info($this->config_vars['already_member']);
        return true;
    }

    /**
     * Remove member
     * Remove a user from a group
     * @param int $user_id User id to remove from group
     * @param int|string $group_par Group id or name to remove user from
     * @return bool Remove success/failure
     */
    public function remove_member($user_id, $group_par) {

        $group_par = $this->get_group_id($group_par);
        $this->CI->db->where('user_id', $user_id);
        $this->CI->db->where('group_id', $group_par);
        return $this->CI->db->delete($this->config_vars['user_to_group']);
    }

    /**
     * Fire member
     * Remove a user from a group same as remove member
     * @param int $user_id User id to remove from group
     * @param int|string $group_par Group id or name to remove user from
     * @return bool Remove success/failure
     */
    public function fire_member($user_id, $group_par) {

        return $this->remove_member($user_id,$group_par);
    }

    /**
     * Is member
     * Check if current user is a member of a group
     * @param int|string $group_par Group id or name to check
     * @param int|bool $user_id User id, if not given current user
     * @return bool
     */
    public function is_member( $group_par, $user_id = false ) {

        // if user_id false (not given), current user
        if(!$user_id){
            $user_id = $this->CI->session->userdata('id');
        }

        $group_id = $this->get_group_id($group_par);
        // if found
        if (is_numeric($group_id)) {

            $query = $this->CI->db->where('user_id', $user_id);
            $query = $this->CI->db->where('group_id', $group_par);
            $query = $this->CI->db->get($this->config_vars['user_to_group']);

            $row = $query->row();

            if ($query->num_rows() > 0) {
                return TRUE;
            } else {
                return FALSE;
            }
        } else {
            return false;
        }
    }

    /**
     * Is admin
     * Check if current user is a member of the admin group
     * @param int $user_id User id to check, if it is not given checks current user
     * @return bool
     */
    public function is_admin( $user_id = false ) {

        return $this->is_member($this->config_vars['admin_group'],$user_id);
    }

    /**
     * List groups
     * List all groups
     * @return object Array of groups
     */
    public function list_groups() {

        $query = $this->CI->db->get($this->config_vars['groups']);
        return $query->result();
    }

    /**
     * Get group name
     * Get group name from group id
     * @param int $group_id Group id to get
     * @return string Group name
     */
    public function get_group_name($group_id) {

        $query = $this->CI->db->where('id', $group_id);
        $query = $this->CI->db->get($this->config_vars['groups']);

        if ($query->num_rows() == 0)
            return FALSE;

        $row = $query->row();
        return $row->name;
    }

    /**
     * Get group id
     * Get group id from group name or id
     * @param int|string $group_par Group id or name to get
     * @return int Group id
     */
    public function get_group_id($group_par) {

        if( is_numeric($group_par) ) { return $group_par; }

        $query = $this->CI->db->where('name', $group_par);
        $query = $this->CI->db->get($this->config_vars['groups']);

        if ($query->num_rows() == 0)
            return FALSE;

        $row = $query->row();
        return $row->id;
    }

    ########################
    # Permission Functions
    ########################

    /**
     * Create permission
     * Creates a new permission type
     * @param string $perm_name New permission name
     * @param string $definition Permission description
     * @return int|bool Permission id or false on fail
     */
    public function create_perm($perm_name, $definition='') {

        $query = $this->CI->db->get_where($this->config_vars['perms'], array('name' => $perm_name));

        if ($query->num_rows() < 1) {

            $data = array(
                'name' => $perm_name,
                'definition'=> $definition
            );
            $this->CI->db->insert($this->config_vars['perms'], $data);
            return $this->CI->db->insert_id();
        }
        $this->error($this->config_vars['already_perm']);
        return FALSE;
    }

    /**
     * Update permission
     * Updates permission name and description
     * @param int|string $perm_par Permission id or permission name
     * @param string $perm_name New permission name
     * @param string $definition Permission description
     * @return bool Update success/failure
     */
    public function update_perm($perm_par, $perm_name, $definition=false) {

        $perm_id = $this->get_perm_id($perm_par);

        $data['name'] = $perm_name;

        if ($definition!=false)
            $data['definition'] = $perm_name;

        $this->CI->db->where('id', $perm_id);
        return $this->CI->db->update($this->config_vars['perms'], $data);
    }

    /**
     * Delete permission
     * Delete a permission from database. WARNING Can't be undone
     * @param int|string $perm_par Permission id or perm name to delete
     * @return bool Delete success/failure
     */
    public function delete_perm($perm_par) {

        $perm_id = $this->get_perm_id($perm_par);

        // deletes from perm_to_gropup table
        $this->CI->db->where('pern_id', $perm_id);
        $this->CI->db->delete($this->config_vars['perm_to_group']);

        // deletes from perm_to_user table
        $this->CI->db->where('pern_id', $perm_id);
        $this->CI->db->delete($this->config_vars['perm_to_group']);

        // deletes from permission table
        $this->CI->db->where('id', $perm_id);
        return $this->CI->db->delete($this->config_vars['perms']);
    }

    /**
     * Is user allowed
     * Check if user allowed to do specified action, admin always allowed
     * fist checks user permissions then check group permissions
     * @param int $perm_par Permission id or name to check
     * @param int|bool $user_id User id to check, or if false checks current user
     * @return bool
     */
    public function is_allowed($perm_par, $user_id=false){

        $perm_id = $this->get_perm_id($perm_par);

        if( $user_id == false){
            $user_id = $this->CI->session->userdata('id');
        }

        $query = $this->CI->db->where('perm_id', $perm_id);
        $query = $this->CI->db->where('user_id', $user_id);
        $query = $this->CI->db->get( $this->config_vars['perm_to_user'] );

        if( $query->num_rows() > 0){
            return true;
        } elseif ($this->is_group_allowed($perm_id)) {
            return true;
        } else {
            return false;
        }

    }

    /**
     * Is Group allowed
     * Check if group is allowed to do specified action, admin always allowed
     * @param int $perm_par Permission id or name to check
     * @param int|string|bool $group_par Group id or name to check, or if false checks all user groups
     * @return bool
     */
    public function is_group_allowed($perm_par, $group_par=false){

        $perm_id = $this->get_perm_id($perm_par);

        // if group par is given
        if($group_par != false){

            $group_par = $this->get_group_id($group_par);

            $query = $this->CI->db->where('perm_id', $perm_id);
            $query = $this->CI->db->where('group_id', $group_par);
            $query = $this->CI->db->get( $this->config_vars['perm_to_group'] );

            if( $query->num_rows() > 0){
                return true;
            } else {
                return false;
            }
        }
        // if group par is not given
        // checks current user's all groups
        else {
            // if public is allowed or he is admin
            if ( $this->is_admin( $this->CI->session->userdata('id')) or
                $this->is_group_allowed($perm_id, $this->config_vars['public_group']) )
            {return true;}

            // if is not login
            if (!$this->is_loggedin()){return false;}

            $group_pars = $this->list_groups( $this->CI->session->userdata('id') );

            foreach ($group_pars as $g ){
                if($this->is_group_allowed($perm_id, $g -> id)){
                    return true;
                }
            }

            return false;
        }
    }

    /**
     * Allow User
     * Add User to permission
     * @param int $user_id User id to deny
     * @param int $perm_par Permission id or name to allow
     * @return bool Allow success/failure
     */
    public function allow_user($user_id, $perm_par) {

        $perm_id = $this->get_perm_id($perm_par);

        $query = $this->CI->db->where('user_id',$user_id);
        $query = $this->CI->db->where('perm_id',$perm_id);
        $query = $this->CI->db->get($this->config_vars['perm_to_user']);

        // if not inserted before
        if ($query->num_rows() < 1) {

            $data = array(
                'user_id' => $user_id,
                'perm_id' => $perm_id
            );

            return $this->CI->db->insert($this->config_vars['perm_to_group'], $data);
        }
        return true;
    }

    /**
     * Deny User
     * Remove user from permission
     * @param int $user_id User id to deny
     * @param int $perm_par Permission id or name to deny
     * @return bool Deny success/failure
     */
    public function deny_user($user_id, $perm_par) {

        $perm_id = $this->get_perm_id($perm_par);

        $this->CI->db->where('user_id', $user_id);
        $this->CI->db->where('perm_id', $perm_id);

        return $this->CI->db->delete($this->config_vars['perm_to_group']);
    }


    /**
     * Allow Group
     * Add group to permission
     * @param int|string|bool $group_par Group id or name to allow
     * @param int $perm_par Permission id or name to allow
     * @return bool Allow success/failure
     */
    public function allow_group($group_par, $perm_par) {

        $perm_id = $this->get_perm_id($perm_par);

        $query = $this->CI->db->where('group_id',$group_par);
        $query = $this->CI->db->where('perm_id',$perm_id);
        $query = $this->CI->db->get($this->config_vars['perm_to_group']);

        if ($query->num_rows() < 1) {

            $group_par = $this->get_group_id($group_par);
            $data = array(
                'group_id' => $group_par,
                'perm_id' => $perm_id
            );

            return $this->CI->db->insert($this->config_vars['perm_to_group'], $data);
        }
        return true;
    }

    /**
     * Deny Group
     * Remove group from permission
     * @param int|string|bool $group_par Group id or name to deny
     * @param int $perm_par Permission id or name to deny
     * @return bool Deny success/failure
     */
    public function deny_group($group_par, $perm_par) {

        $perm_id = $this->get_perm_id($perm_par);

        $group_par = $this->get_group_id($group_par);
        $this->CI->db->where('group_id', $group_par);
        $this->CI->db->where('perm_id', $perm_id);

        return $this->CI->db->delete($this->config_vars['perm_to_group']);
    }

    /**
     * List Permissions
     * List all permissions
     * @return object Array of permissions
     */
    public function list_perms() {

        $query = $this->CI->db->get($this->config_vars['perms']);
        return $query->result();
    }

    /**
     * Get permission id
     * Get permission id from permisison name or id
     * @param int|string $perm_par Permission id or name to get
     * @return int Permission id
     */
    public function get_perm_id($perm_par) {

        if( is_numeric($perm_par) ) { return $perm_par; }

        $query = $this->CI->db->where('name', $perm_par);
        $query = $this->CI->db->get($this->config_vars['perms']);

        if ($query->num_rows() == 0)
            return false;

        $row = $query->row();
        return $row->id;
    }

    ########################
    # Private Message Functions
    ########################

    /**
     * Send Private Message
     * Send a private message to another user
     * @param int $sender_id User id of private message sender
     * @param int $receiver_id User id of private message receiver
     * @param string $title Message title/subject
     * @param string $message Message body/content
     * @return bool Send successful/failed
     */
    public function send_pm( $sender_id, $receiver_id, $title, $message ){

        if ( !is_numeric($receiver_id) or $sender_id == $receiver_id ){
            $this->error($this->config_vars['self_pm']);
            return false;
        }

        $query = $this->CI->db->where('id', $receiver_id);
        $query = $this->CI->db->where('banned', 0);

        $query = $this->CI->db->get( $this->config_vars['users'] );

        // if user not exist or banned
        if ( $query->num_rows() < 1 ){
            $this->error($this->config_vars['no_user']);
            return false;
        }

        $data = array(
            'sender_id' => $sender_id,
            'receiver_id' => $receiver_id,
            'title' => $title,
            'message' => $message,
            'date' => date('Y-m-d H:i:s')
        );

        return $query = $this->CI->db->insert( $this->config_vars['pms'], $data );
    }

    /**
     * List Private Messages
     * If receiver id not given retruns current user's pms, if sender_id given, it returns only pms from given sender
     * @param int $limit Number of private messages to be returned
     * @param int $offset Offset for private messages to be returned (for pagination)
     * @param int $sender_id User id of private message sender
     * @param int $receiver_id User id of private message receiver
     * @return object Array of private messages
     */
    public function list_pms($limit=5, $offset=0, $receiver_id = false, $sender_id=false){

        $query='';

        if ( $receiver_id != false){
            $query = $this->CI->db->where('receiver_id', $receiver_id);
        }

        if( $sender_id != false ){
            $query = $this->CI->db->where('sender_id', $sender_id);
        }

        $query = $this->CI->db->order_by('id','DESC');
        $query = $this->CI->db->get( $this->config_vars['pms'], $limit, $offset);
        return $query->result();
    }

    /**
     * Get Private Message
     * Get private message by id
     * @param int $pm_id Private message id to be returned
     * @param bool $set_as_read Whether or not to mark message as read
     * @return object Private message
     */
    public function get_pm($pm_id, $set_as_read = true){

        if ($set_as_read) $this->set_as_read_pm($pm_id);

        $query = $this->CI->db->where('id', $pm_id);
        $query = $this->CI->db->get( $this->config_vars['pms'] );

        if ($query->num_rows() < 1) {
            $this->error( $this->config_vars['no_pm'] );
        }

        return $query->result();
    }

    /**
     * Delete Private Message
     * Delete private message by id
     * @param int $pm_id Private message id to be deleted
     * @return bool Delete success/failure
     */
    public function delete_pm($pm_id){
        
        return $this->CI->db->delete( $this->config_vars['pms'], array('id' => $pm_id) );
    }

    /**
     * Count unread Private Message
     * Count number of unread private messages
     * @param int|bool $receiver_id User id for message receiver, if false returns for current user
     * @return int Number of unread messages
     */
    public function count_unread_pms($receiver_id=false){

        if(!$receiver_id){
            $receiver_id = $this->CI->session->userdata('id');
        }

        $query = $this->CI->db->where('reciever_id', $receiver_id);
        $query = $this->CI->db->where('read', 0);
        $query = $this->CI->db->get( $this->config_vars['pms'] );

        return $query->num_rows();
    }

    /**
     * Set Private Message as read
     * Set private message as read
     * @param int $pm_id Private message id to mark as read
     */
    public function set_as_read_pm($pm_id){

        $data = array(
            'read' => 1,
        );

        $this->CI->db->update( $this->config_vars['pms'], $data, "id = $pm_id");
    }

    ########################
    # Error / Info Functions
    ########################

    /**
     * Error
     * Add message to error array and set flash data
     * @param string $message Message to add to array
     */
    public function error($message){

        $this->errors[] = $message;
        $this->CI->session->set_flashdata('errors', $this->errors);
    }

    /**
     * Keep Errors
     * keeps the flash data flash data
     * Benefitial by using Ajax Requests
     * more info about flash data
     * http://ellislab.com/codeigniter/user-guide/libraries/sessions.html
     */
    public function keep_errors(){
        $this->session->keep_flashdata('errors');
    }

    /**
     * Get Errors Array
     * Return array of errors
     * @return array|bool Array of messages or false if no errors
     */
    public function get_errors_array(){

        if (!count($this->errors)==0){
            return $this->errors;
        } else {
            return false;
        }
    }

    /**
     * Get Errors
     * Return string of errors separated by delimiter
     * @param string $divider Separator for errors
     * @return string String of errors separated by delimiter
     */
    public function print_errors($divider = '<br />'){

        $msg = '';
        $msg_num = count($this->errors);
        $i = 1;
        foreach ($this->errors as $e) {
            $msg .= $e;

            if ($i != $msg_num)
                $msg .= $divider;

            $i++;
        }
        return $msg;
    }

    /**
     * Info
     * Add message to info array and set flash data
     * @param string $message Message to add to array
     */
    public function info($message){

        $this->infos[] = $message;
        $this->CI->session->set_flashdata('infos', $this->errors);
    }

    /**
     * Keep Infos
     * keeps the flash data
     * Benefitial by using Ajax Requests
     * more info about flash data
     * http://ellislab.com/codeigniter/user-guide/libraries/sessions.html
     */
    public function keep_infos(){
        $this->session->keep_flashdata('infos');
    }

    /**
     * Get Info Array
     * Return array of info
     * @return array|bool Array of messages or false if no errors
     */
    public function get_infos_array(){

        if (!count($this->infos)==0){
            return $this->infos;
        } else {
            return false;
        }
    }

    /**
     * Get Info
     * Return string of info separated by delimiter
     * @param string $divider Separator for info
     * @return string String of info separated by delimiter
     */
    public function print_infos($divider = '<br />'){

        $msg = '';
        $msg_num = count($this->infos);
        $i = 1;
        foreach ($this->infos as $e) {
            $msg .= $e;

            if ($i != $msg_num)
                $msg .= $divider;

            $i++;
        }
        return $msg;
    }

    ########################
    # User Variables
    ########################

    /**
     * Set User Variable as key value
     * if variable not set before, it will ve set
     * if set, overwrites the value
     * @param string $key
     * @param string $value
     * @param int $user_id ; if not given current user
     * @return bool
     */
    public function set_user_var( $key, $value, $user_id = false ) {

        if ( ! $user_id ){
            $user_id = $this->CI->session->userdata('id');
        }


    }


    /**
     * Unset User Variable as key value
     * @param string $key
     * @param int $user_id ; if not given current user
     * @return bool
     */
    public function unset_user_var( $key, $user_id = false ) {

        if ( ! $user_id ){
            $user_id = $this->CI->session->userdata('id');
        }


    }


    /**
     * Get User Variable by key
     * Return string of variable value or false
     * @param string $key
     * @param int $user_id ; if not given current user
     * @return bool|string , false if var is not set, the value of var if set
     */
    public function get_user_var( $key, $user_id = false){

        if ( ! $user_id ){
            $user_id = $this->CI->session->userdata('id');
        }

        $query = $this->CI->db->where('user_id', $user_id);
        $query = $this->CI->db->where('key', $key);

        $query = $this->CI->db->get( $this->config_vars['user_variables'] );

        // if variable not set
        if ($query->num_rows() < 1) { return false;}

        else {

            $row = $query->row();
            return $row->value;
        }

    }

    ########################
    # Aauth System Variables
    ########################

    /**
     * Set Aauth System Variable as key value
     * if variable not set before, it will be set
     * if set, overwrites the value
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function set_aauth_var( $key, $value ) {


    }

    /**
     * Get Aauth System Variable by key
     * Return string of variable value or false
     * @param string $key
     * @return bool|string , false if var is not set, the value of var if set
     */
    public function get_aauth_var( $key ){

    }

} // end class

// $this->CI->session->userdata('id')

/* coming with v3
----------------
 * captcha (hmm bi bakalım)
 * parametre olarak array alma
 * stacoverflow
 * public id sini 0 a eşitleyip öyle kontrol yapabilirdik (oni boşver uşağum)
 *
*/

/**
 * Coming with v2
 * -------------
 *
 * tmam // permission id yi permission parametre yap
 * mail fonksiyonları imtihanı
 * tamam // login e ip aderesi de eklemek lazım
 * list_users da grup_par verilirse ve adamın birden fazla grubu varsa nolurkun? // bi denemek lazım belki distinct ile düzelir
 * tamam // eğer grup silinmişse kullanıcıları da o gruptan sil (fire)
 * tamam //  ismember la is admine 2. parametre olarak user id ekle
 * tamam // kepp infos errors die bişey yap ajax requestlerinde silinir errorlar
 * tmam // user variables
 * sistem variables
 *  user perms
 * tamam gibi // 4mysql index fulltext index??
 * geçici ban ve e-mail ile tkrar aktifleştime olayı
 *
 *
 *
 * -----------
 * ok
 *
 * unban_user() added // unlock_user
 * remove member added // fire_member
 * allow() changed to allow_group
 * deny() changed to deny_group
 * is member a yeni parametre eklendi
 * allow_user() added
 * deny_user() added
 * keep_infos() added
 * kepp_errors() added
 * get_errors() changed to print_errors()
 * get_infos() changed to print_infos()
 * User and Aauth System Variables.
set_user_var( $key, $value, $user_id = false )
get_user_var( $key, $user_id = false)
set_aauth_var( $key, $value, $user_id = false )
get_aauth_var( $key, $user_id = false)
functions added
 *
 *
 *
 *
 *
 * Done staff v1
 * -----------
 * tamam hacı // control die bi fonksiyon yazıp adam önce login omuşmu sonra da yetkisi var mı die kontrol et. yetkisi yoksa yönlendir ve aktivitiyi güncelle
 * tamam hacı // grupları yetkilendirme, yetki ekleme, alma alow deny
 * tamam gibi // Email and pass validation with form helper
 * biraz oldu // laguage file support
 * tamam // forget pass
 * tamam // yetkilendirme sistemi
 * tamam // Login e remember eklencek
 * tamam // şifremi unuttum ve random string
 * sanırım şimdi tamam // hatalı girişde otomatik süreli kilit
 * ??  tamam heral // mail ile bilgilendirme
 * tamam heral // activasyon emaili
 * tamam gibi // yerine email check // username check
 * tamamlandı // public erişimi
 * tamam // Private messsages
 * tamam össen // errorlar düzenlenecek hepisiiii
 * tamam ama engelleme ve limit olayı koymadım. // pm için okundu ve göster, sil, engelle? die fonksiyonlar eklencek , gönderilen pmler, alınan pmler, arasındaki pmler,
 * tamm// already existedleri info yap onlar error değil hacım
 *
 */
