<?php
/**
 * @brief		tfRocketChatWhosOnline Widget
 * @author		<a href='https://www.tolkienforum.de'>TolkienForum.De</a>
 * @copyright	(c) 2017 TolkienForum.De
 * @license		http://www.invisionpower.com/legal/standards/
 * @package		IPS Community Suite
 * @subpackage	tfrocketchat
 * @since		04 Jun 2017
 */

namespace IPS\tfrocketchat\widgets;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * tfRocketChatWhosOnline Widget
 */
class _tfRocketChatWhosOnline extends \IPS\Widget
{
	/**
	 * @brief	Widget Key
	 */
	public $key = 'tfRocketChatWhosOnline';
	
	/**
	 * @brief	App
	 */
	public $app = 'tfrocketchat';
		
	/**
	 * @brief	Plugin
	 */
	public $plugin = '';

	/**
	 * @brief	Prevent caching for this block (caching uses a default expiration of at least a day? cant set a lower value??)
	 */
	public $neverCache = TRUE;


	public function init()
	{
		// include css for widgets:
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css('styles.css', $this->app, 'front'));

		$this->template( array( \IPS\Theme::i()->getTemplate( 'widgets', $this->app, 'front' ), $this->key ) );
		
		parent::init();
	}
	
	/**
	 * Specify widget configuration
	 *
	 * @param	null|\IPS\Helpers\Form	$form	Form object
	 * @return	null|\IPS\Helpers\Form
	 */
	public function configuration( &$form=null )
	{
 		if ( $form === null )
		{
	 		$form = new \IPS\Helpers\Form;
 		}

		$form->add(new \IPS\Helpers\Form\Text('tfrocketchat_url', isset( $this->configuration['tfrocketchat_url'] ) ? $this->configuration['tfrocketchat_url'] : ''));
		$form->add(new \IPS\Helpers\Form\Text('tfrocketchat_username', isset( $this->configuration['tfrocketchat_username'] ) ? $this->configuration['tfrocketchat_username'] : ''));
		$form->add(new \IPS\Helpers\Form\Text('tfrocketchat_password', isset( $this->configuration['tfrocketchat_password'] ) ? $this->configuration['tfrocketchat_password'] : ''));
        $form->add(new \IPS\Helpers\Form\Text('tfrocketchat_topic_channel', isset( $this->configuration['tfrocketchat_topic_channel'] ) ? $this->configuration['tfrocketchat_topic_channel'] : ''));

 		return $form;
 	} 

	public function readJsonFromUrl($url, $opts, $defaultReturn=array()) {
		$context = stream_context_create($opts);
		try {
			$response = file_get_contents($url, false, $context);
			if($response === false)	{
				throw new Exception('Could not read from (null): ' . $url);
			}
			$arr = json_decode($response, true);
			return $arr;

		} catch(\Exception $ex) {
			\IPS\Log::log('Could not read from: ' . $url , 'tfrocketchat');
			\IPS\Log::log('Exception while calling file_get_contents: ' . $ex->getMessage() , 'tfrocketchat');
//			\IPS\Log::log('Trace: ' . $ex->getTraceAsString() , 'tfrocketchat');
			return $defaultReturn;
		}
	}

	public function render()
	{
		/* Do we have permission? */
		if ( !\IPS\Member::loggedIn()->canAccessModule( \IPS\Application\Module::get( 'core', 'online' ) ) )
		{
			return "";
		}

		if(!isset($this->configuration['tfrocketchat_url']) 
			|| !isset($this->configuration['tfrocketchat_username'])
			|| !isset($this->configuration['tfrocketchat_password'])) 
		{
			return \IPS\Member::loggedIn()->language()->addToStack("tfrocketchat_no_settings");
		}

		$url = $this->configuration['tfrocketchat_url'];
		$username = $this->configuration['tfrocketchat_username'];
		$password = $this->configuration['tfrocketchat_password'];
		$channelName = $this->configuration['tfrocketchat_topic_channel'];

		// read info and version from chat.server:
		$infoOpts = array(
			'http'=>array(
				'method' => "GET",
				'header' => "Accept: application/json",
				'timeout' => 3
			)
		);

		$defaultRcInfo = array(
		    'info' => array(
		        'version' => "-1"
            )
        );

		$rcInfo = $this->readJsonFromUrl($url . "/api/v1/info", $infoOpts, $defaultRcInfo);
		$chatVersion = $rcInfo['info']['version'];

		// login to rocket chat:
		$postUsernamePassword = json_encode(
			array(
				'username' => $username,
				'password' => $password
			)
		);

		// \IPS\Log::log('Login String: ' . $postUsernamePassword , 'tfrocketchat');

		$loginOpts = array(
			'http'=>array(
				'method' => "POST",
				'header' => "Content-Type: application/json\r\n".
							"Accept: application/json",
				'ignore_errors' => true,
				'timeout' => 3,
				'content' => $postUsernamePassword
			)
		);

		$rcLogin = $this->readJsonFromUrl($url . "/api/v1/login", $loginOpts);
		$authToken = $rcLogin['data']['authToken'];
		$userId = $rcLogin['data']['userId'];


		// load list of users:
		$usersOpts = array(
			'http'=>array(
				'method' => "GET",
				'header' => "Accept: application/json\r\n" .
							"X-Auth-Token: $authToken\r\n" .
							"X-User-Id: $userId\r\n",
				'timeout' => 5
			)
		);
		$emptyUsers = array(
			'users' => array()
		);
		$rcUsers = $this->readJsonFromUrl($url . "/api/v1/users.list", $usersOpts, $emptyUsers);

		$members = array();
		$validStatus = array("online", "away", "busy");
		foreach ($rcUsers['users'] as $user) {
			// only add online users, filter out bots:
			if(in_array($user['status'], $validStatus) && $user['type'] == "user")
			{
				$member = \IPS\Member::load( $user['username'], 'name' );

				array_push($members, array(
						"id" => $user['_id'],
						"name" => $user['name'],
						"username" => $user['username'],
						"status" => $user['status'],
						"forumUserID" => $member->member_id,
						"seo_name" => $member->members_seo_name,
						"groupID" => $member->member_group_id
					)
				);
			}
		}

		$channel = array();
		if(isset($channelName)) {
            $channelOpts = array(
                'http'=>array(
                    'method' => "GET",
                    'header' => "Accept: application/json\r\n" .
                        "X-Auth-Token: $authToken\r\n" .
                        "X-User-Id: $userId\r\n",
                    'timeout' => 5
                )
            );

            $emptyChannelInfo = array(
                'channel' => array()
            );

            // https://chat.tolkienforum.de/api/v1/channels.info?roomName=general
            $channelInfo = $this->readJsonFromUrl($url . "/api/v1/channels.info?roomName=" . $channelName, $channelOpts, $emptyChannelInfo);

            $channel = $channelInfo['channel'];
            // \IPS\Log::log('Channel String: ' . print_r($channel, true) , 'tfrocketchat');

        }

		$orientation = $this->orientation;
		$memberCount = count($members);

		return $this->output($members, $memberCount, $chatVersion, $channel, $orientation);
	}
}
