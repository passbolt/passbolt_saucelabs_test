<?php
define('TOGGLE_BUTTON_PRESSED', 1);
define('TOGGLE_BUTTON_UNPRESSED', 0);

/**
 * Passbolt Test Case
 * The base class for test cases related to passbolt.
 *
 * @copyright (c) 2015-present Bolt Softwares Pvt Ltd
 * @licence GNU Affero General Public License http://www.gnu.org/licenses/agpl-3.0.en.html
 */
class PassboltTestCase extends WebDriverTestCase {

	// indicate if the database should be reset at the end of the test
	protected $resetDatabase = false;

	// the current username
	protected $currentUsername = null;

	// the cookies used to log the different user.
	protected static $loginCookies = array();

	/**
	 * Called before the first test of the test case class is run
	 */
	public static function setUpBeforeClass() {
		PassboltServer::resetDatabase(Config::read('passbolt.url'));
	}

	/**
	 * Executed before every tests
	 */
	protected function setUp() {
		parent::setUp();
		$this->driver->manage()->window()->maximize();

	}

	/**
	 * Executed after every tests
	 */
	protected function tearDown() {
		parent::tearDown();
		if ($this->resetDatabase) {
			PassboltServer::resetDatabase(Config::read('passbolt.url'));
		}
	}

	/**
	 * Mark the database to be reset at the end of the test
	 */
	public function resetDatabase() {
		$this->resetDatabase = true;
	}

	/********************************************************************************
	 * Passbolt Application Helpers
	 ********************************************************************************/
	/**
	 * Goto a given url
	 * @param $url
	 */
	public function getUrl($url=null) {
		if ($url == 'debug') {
			$url = 'resource://passbolt-at-passbolt-dot-com/data/config-debug.html';
		} else {
			$url = Config::read('passbolt.url') . DS . $url;
		}
		$this->driver->get($url);
	}

	/**
	 * Switch config to use secondary domain (for multi domain testing).
	 */
	public function switchToSecondaryDomain() {
		Config::write('passbolt.url_primary', Config::read('passbolt.url'));
		Config::write('passbolt.url', Config::read('passbolt.url_secondary'));
		PassboltServer::setExtraConfig([
				'App' => [
					'fullBaseUrl' => Config::read('passbolt.url_secondary')
				]
			]);
	}

	/**
	 * Switch config to use primary domain (for multi domain testing).
	 *
	 * Switch will happen only if a first switch to secondary domain was done first.
	 */
	public function switchToPrimaryDomain() {
		// Switch needs to be done only if a switch to secondary domain was done first.
		if (Config::read('passbolt.url_primary')) {
			// Reset the config with the base url.
			Config::write('passbolt.url', Config::read('passbolt.url_primary'));
			PassboltServer::resetExtraConfig();
		}
	}

	/**
	 * Goto workspace
	 * @param $name
	 */
	public function gotoWorkspace($name) {
		$linkCssSelector = '';
		switch ($name) {
			case 'settings':
				$this->click('#js_app_profile_dropdown');
				$this->clickLink('my profile');
				$this->waitUntilISee('.page.settings.profile');
				return;
				break;
			default:
				$linkCssSelector = '#js_app_nav_left_' . $name . '_wsp_link a';
				break;
		}
		$this->click($linkCssSelector);
		$this->waitCompletion();
	}

	/**
	 * Wait until all the currently operations have been completed.
	 * @param int timeout timeout in seconds
	 * @return bool
	 * @throws Exception
	 */
	public function waitCompletion($timeout = 10, $elementSelector = null) {
		$ex = null;

		if (is_null($elementSelector)) {
			$elementSelector = 'html.loaded';
		}

		for ($i = 0; $i < $timeout * 10; $i++) {
			try {
				$elt = $this->findByCss($elementSelector);
				if(count($elt)) {
					return true;
				}
			}
			catch (Exception $e) {
				$ex = $e;
			}
			usleep(100000); // Sleep 1/10 seconds
		}

		//$backtrace = debug_backtrace();
		//throw new Exception( "Timeout thrown by " . $backtrace[1]['class'] . "::" . $backtrace[1]['function'] . "()\n .");
		$this->fail('html.loaded could not be found in time');
	}

	/**
	 * Wait until the secret is decrypted and inserted in the secret field.
	 * @throws Exception
	 */
	public function waitUntilSecretIsDecryptedInField() {
		$this->waitUntilIDontSee('#js_secret.decrypting');
	}

	/**
	 * Register a user using the registration form.
	 * @param $firstname
	 * @param $lastname
	 * @param $username
	 */
	public function registerUser($firstname, $lastname, $username) {
		// Register user.
		$this->getUrl('register');
		$this->inputText('ProfileFirstName', $firstname);
		$this->inputText('ProfileLastName', $lastname);
		$this->inputText('UserUsername', $username);
		$this->pressEnter();
		$this->waitUntilISee('.page.register.thank-you');
		$this->assertCurrentUrl('register' . DS . 'thankyou');
	}

	/**
	 * Login on the application with the given user.
	 * @param user
	 */
	public function loginAs($user) {
		if (!is_array($user)) {
			$user = [
				'Username' => $user,
				'MasterPassword' => $user
			];
		}

		// Store the current username.
		$this->currentUser = $user;

		if (isset(self::$loginCookies[$this->currentUser['Username']])) {
			$this->getUrl('login');
			foreach(self::$loginCookies[$this->currentUser['Username']] as $cookie) {
				$this->driver->manage()->addCookie($cookie);
			}
			// The application page mode needs to be restarted manually.
			$this->getUrl('debug');
			$this->click('initAppPagemod');
			$this->getUrl('');
			try {
				$this->findbyCss('.logout');
				$this->waitCompletion();
				return;
			} catch (Exception $e) {
				// If not logged in, maybe the session expired.
				// Or a test logged the user out.
				// Try to login the normal way.
			}
		}

		// If not on the login page, we redirect to it.
		try {
			$this->find('.users.login.form');
		}
		catch(Exception $e) {
			$this->getUrl('login');
		}

		$this->waitUntilISee('.plugin-check.firefox.success');
		$this->waitUntilISee('.plugin-check.gpg.success');
		$this->goIntoLoginIframe();
		$this->assertInputValue('UserUsername', $user['Username']);
		$this->inputText('js_master_password', $user['MasterPassword']);
		$this->click('loginSubmit');
		$this->goOutOfIframe();

		// wait for login to take place
		$this->waitUntilISee('.login.form .feedback');
		$this->waitCompletion();

		// wait for redirection trigger
		$this->waitUntilISee('.logout');
		$this->waitCompletion();

		// save the cookie
		self::$loginCookies[$this->currentUser['Username']] = $this->driver->manage()->getCookies();
	}

	/**
	 * Logout user.
	 */
	public function logout() {
		$this->getUrl('logout');
	}

	/**
	 * Restart the browser.
	 *
	 * We mimic the following behavior :
	 * * The user quits the browser;
	 * * The user restarts it.
	 *
	 * We expect the cookies to be as they were before quitting the browser. So if the user was logged-in on the
	 * application before quitting the browser, he should be logged-in after the browser is restarted.
	 * -> We store and reload manually the cookies
	 *
	 * The application pagemod is started after a successful authentication or when the plugin is started for the first
	 * time with a user already logged-in. However we can't load the cookies before starting the plugin.
	 * -> We implements a workaround to start the application pagemod manually, see the debug page.
	 *
	 * @param $options
	 * 	waitBeforeRestart : Should the browser be restarted after a sleep in seconds
	 */
	public function restartBrowser($options = array()) {
		$options = $options ? $options : array();
		$waitBeforeRestart = isset($options['waitBeforeRestart']) ? $options['waitBeforeRestart'] : 0;

		// Quit the browser.
		$this->driver->quit();

		// If a wait before restart option has been given.
		sleep($waitBeforeRestart);

		// Restart the brower
		$this->initBrowser();
		$this->driver->manage()->window()->maximize();

		// As the browser local storage has been cleaned.
		// Set the client config has it was before quitting.
		if (!is_null($this->currentUser)) {
			$this->setClientConfig($this->currentUser);
		}

		// Same for the cookies.
		if (!empty(self::$loginCookies[$this->currentUser['Username']])) {
			$this->getUrl('/auth/login');
			foreach(self::$loginCookies[$this->currentUser['Username']] as $cookie) {
				$this->driver->manage()->addCookie($cookie);
			}
		}

		// The application page mode needs to be restarted manually.
		$this->getUrl('debug');
		$this->click('initAppPagemod');

		// Go to the application
		$this->getUrl('');
	}

	/**
	 * Close and restore the current tab.
	 * Ensure the test run already on a second tab.
	 *
	 * @param $options
	 * 	waitBeforeRestore : Should the tab be restored after a sleep in seconds
	 */
	public function closeAndRestoreTab($options = array()) {
		$options = $options ? $options : array();
		$waitBeforeRestore = isset($options['waitBeforeRestore']) ? $options['waitBeforeRestore'] : 0;
		$this->findByCSS('html')->sendKeys(array(WebDriverKeys::CONTROL, 'w'));
		sleep($waitBeforeRestore);
		$this->findByCss('html')->sendKeys(array(WebDriverKeys::SHIFT, WebDriverKeys::CONTROL, 't'));
	}

	/**
	 * Refresh page.
	 */
	public function refresh() {
		$this->driver->execute(DriverCommand::REFRESH);
		$this->waitCompletion();
	}

	/**
	 * Trigger an event on a page.
	 * @param $eventName
	 */
	public function triggerEvent($eventName) {
		$fireEvent = 'function fireEvent(obj, evt, data){
		     var fireOnThis = obj;
		     if( document.createEvent ) {
		       var evObj = document.createEvent("MouseEvents");
		       evObj.initEvent( evt, true, false );
		       fireOnThis.dispatchEvent( evObj );
		     }
		      else if( document.createEventObject ) { //IE
		       var evObj = document.createEventObject();
		       fireOnThis.fireEvent( "on" + evt, evObj );
		     }
		}
		fireEvent(window, "' . $eventName . '");';
		$this->driver->executeScript($fireEvent);
	}

	/**
	 * Set client config data.
	 * Populate the field js_auto_settings from the debug page, with the settings given.
	 * The settings are encoded in json, and base64 to avoir return to lines which cause issues in javascript.
	 * The debug page then decode these data, and populate the settings fields.
	 * This method is much faster that asking the driver to fill the fields manually.
	 * @param $config
	 */
	function _setClientConfigData($config) {
		$configBase64 = base64_encode(json_encode($config));
		$setData = "
			document.getElementById(\"js_auto_settings\").value='$configBase64';
		";
		$this->driver->executeScript($setData);
	}

	/**
	 * Use the debug screen to set the values set by the setup
	 * @param $config array user config (see fixtures)
	 * @param $manual bool whether the data should be entered manually, or through javascript.
	 */
	public function setClientConfig($config, $manual = false) {
		$this->getUrl('debug');
		$this->waitUntilISee('.config.page');

		$userPrivateKey = '';
		// If the key provided is a path, then look in the complete path.
		if (strpos($config['PrivateKey'], '/') !== FALSE) {
			$userPrivateKey = file_get_contents( $config['PrivateKey'] );
		}
		// Else look in the fixtures only.
		else {
			$userPrivateKey = file_get_contents(GPG_FIXTURES . DS . $config['PrivateKey'] );
		}

		// Fill config data through javascript
		if (!$manual) {
			$conf = [
				'baseUrl' => isset($config['domain']) ? $config['domain'] : Config::read('passbolt.url'),
				'UserId'  => $config['id'],
				'ProfileFirstName' => $config['FirstName'],
				'ProfileLastName' => $config['LastName'],
				'UserUsername' => $config['Username'],
				'securityTokenCode' => $config['TokenCode'],
				'securityTokenColor' => $config['TokenColor'],
				'securityTokenTextColor' => $config['TokenTextColor'],
				'myKeyAscii' => $userPrivateKey,
				'serverKeyAscii' => file_get_contents(Config::read('passbolt.server_key.path'))
			];
			$this->_setClientConfigData($conf);
			$this->triggerEvent('passbolt.debug.settings.set');

			$this->waitUntilISee('.debug-data-set');
		}
		// Fill config data manually
		else {
			$this->inputText('baseUrl', isset($config['domain']) ? $config['domain'] : Config::read('passbolt.url'));
			$this->inputText('UserId',$config['id']);
			$this->inputText('ProfileFirstName',$config['FirstName']);
			$this->inputText('ProfileLastName',$config['LastName']);
			$this->inputText('UserUsername',$config['Username']);
			$this->inputText('securityTokenCode',$config['TokenCode']);
			$this->inputText('securityTokenColor',$config['TokenColor']);
			$this->inputText('securityTokenTextColor',$config['TokenTextColor']);

			// Set the keys.
			$key = '';
			// If the key provided is a path, then look in the complete path.
			if (strpos($config['PrivateKey'], '/') !== FALSE) {
				$key = file_get_contents( $config['PrivateKey'] );
			}
			// Else look in the fixtures only.
			else {
				$key = file_get_contents(GPG_FIXTURES . DS . $config['PrivateKey'] );
			}
			$this->inputText('myKeyAscii', $key);

			$key = file_get_contents(Config::read('passbolt.server_key.path'));
			$this->inputText('serverKeyAscii', $key);
		}

		$this->click('js_save_conf');
		// Assert it has been saved.
		$this->assertElementContainsText(
			$this->findbyCss('.user.settings.feedback'),
			'User and settings have been saved!'
		);

		$this->click('saveKey');
		// Assert it has been saved.
		$this->waitUntilISee('.my.key-import.feedback', '/The key has been imported succesfully/');

		$this->click('saveServerKey');
		$this->waitUntilISee('.server.key-import.feedback', '/The key has been imported successfully/');
	}

	/**
	 * Complete the setup with the data given in parameter
	 * @param $data
	 *  - username
	 *  - masterpassword
	 *
	 * @throws Exception
	 */
	public function completeSetupWithKeyGeneration($data) {
		// Check that we are on the setup page.
		$this->assertPageContainsText('Plugin check');
		// Wait for the checkbox to appear.
		$this->waitUntilISee('#js_setup_domain_check');
		// Check box domain check.
		$this->checkCheckbox('js_setup_domain_check');
		// Click Next.
		$this->clickLink("Next");
		// Wait
		$this->waitUntilISee('#js_step_content h3', '/Create a new key/i');
		// Fill master key.
		$this->inputText('KeyComment', 'This is a comment for john doe key');
		// Click Next.
		$this->clickLink("Next");
		// Check that we are now on the passphrase page
		$this->waitUntilISee('#js_step_title', '/Now let\'s setup your passphrase!/i');
		// Fill master key.
		$this->inputText('js_field_password', $data['masterpassword']);
		// Click Next.
		$this->clickLink("Next");
		// Wait until we see the title Master password.
		$this->waitUntilISee('#js_step_title', '/Success! Your secret key is ready./i');
		// Press Next.
		$this->clickLink("Next");
		// Wait.
		$this->waitUntilISee('#js_step_content h3', '/Set a security token/i');
		// Press Next.
		$this->clickLink("Next");
		// Fill up password.
		$this->waitUntilISee('#js_step_content h3', '/The setup is complete/i');
		// Wait until I see the login page.
		$this->waitUntilISee('.information h2', '/Welcome back!/i');
	}

	/**
	 * Complete the setup with key import
	 * @param $data
	 *  - private_key
	 *
	 * @throws Exception
	 */
	public function completeSetupWithKeyImport($data) {
		// Check that we are on the setup page.
		$this->assertPageContainsText('Plugin check');
		// Wait for the checkbox to appear.
		$this->waitUntilISee('#js_setup_domain_check');
		// Check box domain check.
		$this->checkCheckbox('js_setup_domain_check');
		// Click Next.
		$this->clickLink("Next");
		// Wait
		$this->waitUntilISee('#js_step_content h3', '/Create a new key/i');
		// Click on import.
		$this->clickLink('import');
		// Wait until section is displayed.
		$this->waitUntilISee('#js_step_title', '/Import an existing key or create a new one!/i');
		// Enter key in the field.
		$this->inputText('js_setup_import_key_text', $data['private_key']);
		// Click Next
		$this->clickLink('Next');
		// Wait until the key is imported
		$this->waitUntilISee('#js_step_title', '/Let\'s make sure you imported the right key/i');
		// Press Next.
		$this->clickLink("Next");
		// Wait.
		$this->waitUntilISee('#js_step_content h3', '/Set a security token/i');
		// Press Next.
		$this->clickLink("Next");
		// Fill up password.
		$this->waitUntilISee('#js_step_content h3', '/The setup is complete/i');
		// Wait until I see the login page.
		$this->waitUntilISee('.information h2', '/Welcome back!/i');
	}


	/**
	 * go To Setup page.
	 * @throws Exception
	 * @param string $username
	 * @param bool $checkPluginSuccess
	 */
	public function goToSetup($username, $checkPluginSuccess = true) {
		// Get last email.
		$this->getUrl('seleniumTests/showLastEmail/' . urlencode($username));

		// Remember setup url. (We will use it later).
		$linkElement = $this->findLinkByText('get started');
		$setupUrl = $linkElement->getAttribute('href');

		// Go to url remembered above.
		$this->driver->get($setupUrl);

		// Test that the plugin confirmation message is displayed.
		if ($checkPluginSuccess) {
			$this->waitUntilISee('.plugin-check-wrapper .plugin-check.success', '/Firefox plugin is installed and up to date/i');
		}
	}


	/**
	 * go To Recover page.
	 * @throws Exception
	 * @param string $username
	 * @param bool $checkPluginSuccess
	 */
	public function goToRecover($username, $checkPluginSuccess = true) {
		// Get last email.
		$this->getUrl('seleniumTests/showLastEmail/' . urlencode($username));

		// Remember setup url. (We will use it later).
		$linkElement = $this->findLinkByText('start recovery');
		$setupUrl = $linkElement->getAttribute('href');

		// Go to url remembered above.
		$this->driver->get($setupUrl);

		// Test that the plugin confirmation message is displayed.
		if ($checkPluginSuccess) {
			$this->waitUntilISee('.plugin-check-wrapper .plugin-check.success', '/Firefox plugin is installed and up to date/i');
		}
	}

	/**
	 * Go to the password workspace and click on the create password button
	 */
	public function gotoCreatePassword() {
		if(!$this->isVisible('.page.password')) {
			$this->getUrl('');
			$this->waitUntilISee('.page.password');
			$this->waitUntilISee('#js_wsp_create_button');
		}
		$this->click('#js_wsp_create_button');
		$this->assertVisible('.create-password-dialog');
	}

	/**
	 * Click on a password inside the password workspace.
	 * @param string $id id of the password
	 *
	 * @throws Exception
	 */
	public function clickPassword($id) {
		if(!$this->isVisible('.page.password')) {
			throw new Exception("click password requires to be on the password workspace");
		}
		$this->click('#resource_' . $id . ' .cell_name');
	}

	/**
	 * Right click on a password with a given id.
	 * @param string $id
	 *
	 * @throws Exception
	 */
	public function rightClickPassword($id) {
		if(!$this->isVisible('.page.password')) {
			throw new Exception("right click password requires to be on the password workspace");
		}
		$eltSelector = '#resource_' . $id . ' .cell_name';
		//$this->rightClick('#resource_' . $id . ' .cell_name');
		// Instead of rightClick function, we execute a script.
		// This is because passbolt opens a contextual menu on the mousedown event
		// and not on the contextMenu event. (and the primitive mouseDown doesn't exist in the webDriver).
		$this->driver->executeScript("
			jQuery('$eltSelector').trigger({
				type:'mousedown',
				which:3
			});
		");
		// Without this little interval, the menu doesn't have time to open.
		$this->waitUntilISee('#js_contextual_menu.ready');
	}

	/**
	 * Check if the password has already been selected
	 * @param $id string
	 * @return bool
	 */
	public function isPasswordFavorite($id) {
		$eltSelector = '#favorite_' . $id . ' i';
		if ($this->elementHasClass($eltSelector, 'unfav')) {
			return true;
		}
		return false;
	}

	/**
	 * Mark or unmark a password as a favorite
	 * @param $id string
	 * @throws Exception
	 */
	public function clickPasswordFavorite($id) {
		$eltSelector = '#favorite_' . $id . ' i';
		$this->click($eltSelector);
	}

	/**
	 * Check if the password has already been selected
	 * @param $id string
	 * @return bool
	 */
	public function isPasswordSelected($id) {
		$eltSelector = '#resource_' . $id;
		if ($this->elementHasClass($eltSelector, 'selected')) {
			return true;
		}
		return false;
	}

	/**
	 * Check if the password has not been selected
	 * @param $id string
	 * @return bool
	 */
	public function isPasswordNotSelected($id) {
		$eltSelector = '#resource_' . $id;
		if ($this->elementHasClass($eltSelector, 'selected')) {
			return false;
		}
		return true;
	}

	/**
	 * Check if the user has already been selected
	 * @param $id string
	 * @return bool
	 */
	public function isUserSelected($id) {
		$eltSelector = '#user_' . $id;
		if ($this->elementHasClass($eltSelector, 'selected')) {
			return true;
		}
		return false;
	}

	/**
	 * Check if the user has not been selected
	 * @param $id string
	 * @return bool
	 */
	public function isUserNotSelected($id) {
		$eltSelector = '#user_' . $id;
		if ($this->elementHasClass($eltSelector, 'selected')) {
			return false;
		}
		return true;
	}

	/**
	 * Goto the edit password dialog for a given resource id
	 * @param $id string
	 * @throws Exception
	 */
	public function gotoEditPassword($id) {
		if(!$this->isVisible('.page.password')) {
			$this->getUrl('');
			$this->waitUntilISee('.page.password');
			$this->waitUntilISee('#js_wk_menu_edition_button');
		}
		$this->releaseFocus(); // we click somewhere in case the password is already active
		if (!$this->isPasswordSelected($id)) {
			$this->clickPassword( $id );
		}
		$this->click('js_wk_menu_edition_button');
		$this->waitCompletion();
		$this->assertVisible('.edit-password-dialog');
	}

	/**
	 * Goto the share password dialog for a given resource id
	 * @param $id string
	 * @throws Exception
	 */
	public function gotoSharePassword($id) {
		if(!$this->isVisible('.page.password')) {
			$this->getUrl('');
			$this->waitUntilISee('.page.password');
			$this->waitUntilISee('#js_wk_menu_sharing_button');
		}
		if(!$this->isVisible('#js_rs_permission')) {
			$this->releaseFocus(); // we click somewhere in case the password is already active
			if (!$this->isPasswordSelected($id)) {
				$this->clickPassword($id);
			}
			$this->click('js_wk_menu_sharing_button');
			$this->waitUntilISee('.share-password-dialog #js_rs_permission.ready');
		}
	}

	/**
	 * Put the focus inside the login iframe
	 */
	public function goIntoLoginIframe() {
		$this->driver->switchTo()->frame('passbolt-iframe-login-form');
	}

	/**
	 * Input a given string in the secret field (create only)
	 * @param string $secret
	 */
	public function inputSecret($secret) {
		$this->goIntoSecretIframe();
		$this->inputText('js_secret', $secret);
		$this->goOutOfIframe();
	}

	/**
	 * Put the focus inside the secret iframe
	 */
	public function goIntoSecretIframe() {
		$this->driver->switchTo()->frame('passbolt-iframe-secret-edition');
	}

	/**
	 * Put the focus inside the password share iframe
	 */
	public function goIntoShareIframe() {
		$this->driver->switchTo()->frame('passbolt-iframe-password-share');
	}

	/**
	 * Put the focus inside the password share autocomplete iframe
	 */
	public function goIntoShareAutocompleteIframe() {
		$this->driver->switchTo()->frame('passbolt-iframe-password-share-autocomplete');
	}

	/**
	 * Dig into the passphrase iframe
	 */
	public function goIntoMasterPasswordIframe() {
		$this->driver->switchTo()->frame('passbolt-iframe-master-password');
	}

	/**
	 * Put the focus back to the normal context
	 */
	public function goOutOfIframe() {
		$this->driver->switchTo()->defaultContent();
	}

	/**
	 * Helper to create a password
	 */
	public function createPassword($password) {
		$this->gotoCreatePassword();
		$this->inputText('js_field_name', $password['name']);
		$this->inputText('js_field_username', $password['username']);
		if (isset($password['uri'])) {
			$this->inputText('js_field_uri', $password['uri']);
		}
		$this->inputSecret($password['password']);
		if (isset($password['description'])) {
			$this->inputText('js_field_description', $password['description']);
		}
		$this->click('.create-password-dialog input[type=submit]');

		$this->waitUntilISee('passbolt-iframe-progress-dialog');
		$this->waitUntilIDontSee('passbolt-iframe-progress-dialog');

		$this->assertNotification('app_resources_add_success');
	}

	/**
	 * Edit a password helper
	 * @param $password
	 * @throws Exception
	 */
	public function editPassword($password, $user = []) {
		$this->gotoEditPassword($password['id']);

		if (isset($password['name'])) {
			$this->inputText('js_field_name', $password['name']);
		}
		if (isset($password['username'])) {
			$this->inputText('js_field_username', $password['username']);
		}
		if (isset($password['uri'])) {
			$this->inputText('js_field_uri', $password['uri']);
		}
		if (isset($password['password'])) {
			if (empty($user)) {
				throw new Exception("a user must be provided to the function in order to update the secret");
			}
			$this->goIntoSecretIframe();
			$this->click('js_secret');
			$this->goOutOfIframe();
			$this->assertMasterPasswordDialog($user);
			$this->enterMasterPassword($user['MasterPassword']);
			$this->waitUntilIDontSee('passbolt-iframe-master-password');

			// Wait for password to be decrypted.
			$this->goIntoSecretIframe();
			$this->waitUntilSecretIsDecryptedInField();
			$this->goOutOfIframe();

			$this->inputSecret($password['password']);
		}
		if (isset($password['description'])) {
			$this->inputText('js_field_description', $password['description']);
		}
		$this->click('.edit-password-dialog input[type=submit]');

		if (isset($password['password'])) {
			$this->waitUntilISee('passbolt-iframe-progress-dialog');
			$this->waitUntilIDontSee('passbolt-iframe-progress-dialog');
		}
		$this->assertNotification('app_resources_edit_success');
	}

	/**
	 * Search a user to share a password with.
	 * @param $password
	 * @param $username
	 * @param $user
	 */
	public function searchUserToGrant($password, $username, $user) {
		$this->gotoSharePassword($password['id']);
		$shareWithUser = User::get($username);

		// I enter the username I want to share the password with in the autocomplete field
		$this->goIntoShareIframe();
		$this->assertSecurityToken($user, 'share');
		$this->inputText('js_perm_create_form_aro_auto_cplt', $shareWithUser['FirstName'], true);
		$this->click('.security-token');
		$this->goOutOfIframe();

		// I wait the autocomplete box is loaded.
		$this->waitCompletion(10, '#passbolt-iframe-password-share-autocomplete.loaded');
	}

	/**
	 * Add a temporary permission helper
	 * @param $password
	 * @param $username
	 * @param $user
	 * @throws Exception
	 */
	public function addTemporaryPermission($password, $username, $user) {
		$shareWithUser = User::get($username);
		$shareWithUserFullName = $shareWithUser['FirstName'] . ' ' . $shareWithUser['LastName'];

		// Search the user to grant.
		$this->searchUserToGrant($password, $username, $user);

		// I wait until I see the automplete field resolved
		$this->goIntoShareAutocompleteIframe();
		$this->waitUntilISee('.autocomplete-content', '/' . $shareWithUserFullName . '/i');

		// I click on the username link the autocomplete field retrieved.
		$this->click($shareWithUser['id']);
		$this->goOutOfIframe();

		// I can see that temporary changes are waiting to be saved
		$this->assertElementContainsText(
			$this->findByCss('.share-password-dialog #js_permissions_changes'),
			'You need to save to apply the changes'
		);
	}

	/**
	 * Share a password helper
	 * @param $password
	 * @param $username
	 * @param $user
	 * @throws Exception
	 */
	public function sharePassword($password, $username, $user) {
		$this->addTemporaryPermission($password, $username, $user);

		// When I click on the save button
		$this->click('js_rs_share_save');

		// Then I see the passphrase dialog
		$this->assertMasterPasswordDialog($user);

		// When I enter the passphrase and click submit
		$this->enterMasterPassword($user['MasterPassword']);

		// Then I see a dialog telling me encryption is in progress
		$this->waitUntilISee('passbolt-iframe-progress-dialog');
		$this->waitUntilIDontSee('passbolt-iframe-progress-dialog');

		// And I see a notice message that the operation was a success
		$this->assertNotification('app_share_update_success');

		// And I should not see the share dialog anymore
		$this->assertNotVisible('.share-password-dialog');
	}

	/**
	 * Edit temporary a permission
	 * @param $password
	 * @param $username
	 * @param $permissionType
	 * @param $user
	 * @throws Exception
	 */
	public function editTemporaryPermission($password, $username, $permissionType, $user) {
		$this->gotoSharePassword($password['id']);

		// I can see the user has a direct permission
		$this->assertElementContainsText(
			$this->findByCss('#js_permissions_list'),
			$username
		);

		// Find the permission row element
		$rowElement = $this->findByXpath('//*[@id="js_permissions_list"]//*[.="' . $username . '"]//ancestor::li[1]');

		// I change the permission
		$select = new WebDriverSelect($rowElement->findElement(WebDriverBy::cssSelector('.js_share_rs_perm_type')));
		$select->selectByVisibleText($permissionType);

		// I can see that temporary changes are waiting to be saved
		$this->assertElementContainsText(
			$this->findByCss('.share-password-dialog #js_permissions_changes'),
			'You need to save to apply the changes'
		);
	}

	/**
	 * Edit a password permission helper
	 * @param $password
	 * @param $username
	 * @param $permissionType
	 * @param $user
	 * @throws Exception
	 */
	public function editPermission($password, $username, $permissionType, $user) {
		// Make a temporary edition
		$this->editTemporaryPermission($password, $username, $permissionType, $user);

		// When I click on the save button
		$this->click('js_rs_share_save');
		$this->waitCompletion();

		// And I see a notice message that the operation was a success
		$this->assertNotification('app_share_update_success');

		// And I should not see the share dialog anymore
		$this->assertNotVisible('.share-password-dialog');
	}

	/**
	 * Delete temporary a permission helper
	 * @param $password
	 * @param $username
	 * @throws Exception
	 */
	public function deleteTemporaryPermission($password, $username) {
		$this->gotoSharePassword($password['id']);

		// I can see the user has a direct permission
		$this->assertElementContainsText(
			$this->findByCss('#js_permissions_list'),
			$username
		);

		// Find the permission row element
		$rowElement = $this->findByXpath('//*[@id="js_permissions_list"]//*[.="' . $username . '"]//ancestor::li[1]');

		// I delete the permission
		$deleteButton = $rowElement->findElement(WebDriverBy::cssSelector('.js_perm_delete'));
		$deleteButton->click();
	}

	/**
	 * Delete a password permission helper
	 * @param $password
	 * @param $username
	 * @throws Exception
	 */
	public function deletePermission($password, $username) {
		// Delete temporary the permission
		$this->deleteTemporaryPermission($password, $username);

		// I can see that temporary changes are waiting to be saved
		$this->assertElementContainsText(
			$this->findByCss('.share-password-dialog #js_permissions_changes'),
			'You need to save to apply the changes'
		);

		// When I click on the save button
		$this->click('js_rs_share_save');
		$this->waitCompletion();

		// And I see a notice message that the operation was a success
		$this->assertNotification('app_share_update_success');

		// And I should not see the share dialog anymore
		$this->assertNotVisible('.share-password-dialog');
	}

	/**
	 * Enter the password in the passphrase iframe
	 * @param $pwd
	 * @param $remember
	 */
	public function enterMasterPassword($pwd, $remember = false) {
		$this->waitUntilISee('passbolt-iframe-master-password');
		$this->goIntoMasterPasswordIframe();
		$this->inputText('js_master_password', $pwd);

		if ($remember == true) {
			$this->checkCheckbox('js_remember_master_password');
		}

		$this->click('master-password-submit');
		$this->assertElementHasClass(
			$this->find('master-password-submit'),
			'processing'
		);
		$this->goOutOfIframe();
		$this->waitUntilIDontSee('passbolt-iframe-master-password');
	}

    /**
     * Enter the password in the passphrase iframe using only keyboard, and no clicks.
     *
     * @param $pwd
     *   passphrase string
     * @param $tabFirst
     *   if tab should be pressed first to give focus
     *
     */
    public function enterMasterPasswordWithKeyboardShortcuts($pwd, $tabFirst = false) {
        $this->waitUntilISee('passbolt-iframe-master-password');
        sleep(1);
        if ($tabFirst) {
            $this->pressTab();
            $this->goIntoMasterPasswordIframe();
            $this->assertElementHasFocus('js_master_password');
            $this->goOutOfIframe();
        }
        $this->typeTextLikeAUser($pwd);
        $this->pressEnter();
        $this->goIntoMasterPasswordIframe();
        $this->assertElementHasClass(
            $this->find('master-password-submit'),
            'processing'
        );
        $this->goOutOfIframe();
    }


	/**
	 * Copy a password to clipboard
	 * @param $resource
	 * @param $user
	 */
	public function copyToClipboard($resource, $user) {
		$this->rightClickPassword($resource['id']);
		$this->waitUntilISee('js_contextual_menu');
		$this->clickLink('Copy password');
		$this->assertMasterPasswordDialog($user);
		$this->enterMasterPassword($user['MasterPassword']);
		$this->assertNotification('plugin_secret_copy_success');
	}

	/**
	 * Go to the user workspace and click on the create user button
	 */
	public function gotoCreateUser() {
		if(!$this->isVisible('.page.people')) {
			$this->getUrl('');
			$this->waitUntilISee('.page.people');
			$this->waitUntilISee('#js_wsp_create_button');
		}
		$this->click('#js_wsp_create_button');
		$this->assertVisible('.create-user-dialog');
	}

	/**
	 * Helper to create a user
	 */
	public function createUser($user) {
		$this->gotoCreateUser();
		$this->inputText('js_field_first_name', $user['first_name']);
		$this->inputText('js_field_last_name', $user['last_name']);
		$this->inputText('js_field_username', $user['username']);
		if (isset($user['admin']) && $user['admin'] === true) {
			// Check box admin
			$this->checkCheckbox('#js_field_role_id .role-admin input[type=checkbox]');
		}
		$this->click('.create-user-dialog input[type=submit]');
		$this->assertNotification('app_users_add_success');
	}

	/**
	 * Click on a user in the user workspace
	 * @param array $user array containing either id, or first name and last name or directly a uuid
	 * @throws Exception if not on the right workspace
	 */
	public function clickUser($user) {
		if(!$this->isVisible('.page.people')) {
			throw new Exception("click user requires to be on the user workspace");
		}
		// if user is not an array, then it is a uuid.
		if (!is_array($user)) {
			$user = ['id' => $user];
		}
		if (isset($user['first_name']) && isset($user['last_name'])) {
			$elt = $this->find('.tableview-content div[title="' . $user['first_name'] . ' ' . $user['last_name'] . '"]');
			$elt->click();
		}
		else {
			$this->click('#user_' . $user['id'] . ' .cell_name');
		}
	}

	/**
	 * Right click on a user with a given id.
	 * @param string $id
	 *
	 * @throws Exception
	 */
	public function rightClickUser($id) {
		if(!$this->isVisible('.page.people')) {
			throw new Exception("right click user requires to be on the user workspace");
		}
		$eltSelector = '#user_' . $id . ' .cell_name';
		$this->driver->executeScript("
			jQuery('$eltSelector').trigger({
				type:'mousedown',
				which:3
			});
		");
		// Without this little interval, the menu doesn't have time to open.
		$this->waitUntilISee('#js_contextual_menu.ready');
	}

	/**
	 * Goto the edit user dialog for a given user id
	 * @param $id string
	 * @throws Exception
	 */
	public function gotoEditUser($id) {
		if(!$this->isVisible('.page.people')) {
			$this->getUrl('');
			$this->gotoWorkspace('user');
			$this->waitUntilISee('.page.people');
			$this->waitUntilISee('#js_user_wk_menu_edition_button');
		}
		$this->releaseFocus(); // we click somewhere in case the password is already active
		$this->clickUser($id);
		$this->click('js_user_wk_menu_edition_button');
		$this->waitCompletion();
		$this->assertVisible('.edit-user-dialog');
	}

	/**
	 * Edit a user helper
	 * @param $user
	 * @throws Exception
	 */
	public function editUser($user) {
		$this->gotoEditUser($user);

		if (isset($user['first_name'])) {
			$this->inputText('js_field_first_name', $user['first_name']);
		}
		if (isset($user['last_name'])) {
			$this->inputText('js_field_last_name', $user['last_name']);
		}
		if (isset($user['admin'])) {
			// Get current state of admin
			$el = null;
			try {
				$el = $this->find('#js_field_role_id .role-admin input[type=checkbox][checked=checked]');
			}
			catch(Exception $e) {}
			// if el was found, admin checkbox is already checked.
			$isAdmin = ($el == null ? false : true);
			if ($isAdmin != $user['admin']) {
				$this->checkCheckbox('#js_field_role_id .role-admin input[type=checkbox]');
			}
		}

		$this->click('.edit-user-dialog input[type=submit]');
		$this->assertNotification('app_users_edit_success');
	}

	/**
	 * Empty a field like a user would do it.
	 * Click on the field, go at the end of the text, and backspace to remove the whole text.
	 * @param $id
	 */
	public function emptyFieldLikeAUser($id) {
		$field = $this->find($id);
		$val = $field->getAttribute('value');
		$sizeStr = strlen($val);
		$field->click();
		for ($i = 0; $i < $sizeStr; $i++) {
			$this->driver->getKeyboard()->pressKey(WebDriverKeys::ARROW_RIGHT);
		}
		for ($i = 0; $i < $sizeStr; $i++) {
			$this->driver->getKeyboard()->pressKey(WebDriverKeys::BACKSPACE);
		}
	}

    /**
     * Type text like a user would do, pressing key after key.
     * @param $text
     */
    public function typeTextLikeAUser($text) {
        $sizeStr = strlen($text);
        for ($i = 0; $i < $sizeStr; $i++) {
            $this->driver->getKeyboard()->pressKey($text[$i]);
        }
    }

	/**
	 * Click on the ok button in the confirm dialog.
	 */
	public function confirmActionInConfirmationDialog() {
		$button = $this->find('confirm-button');
		$button->click();
	}
	/**
	 *
	 * Click on the cancel button in the confirm dialog.
	 */
	public function cancelActionInConfirmationDialog() {
		$button = $this->findByCss('.dialog.confirm .js-dialog-cancel');
		$button->click();
	}

	/**
	 * Scroll the sidebar to bottom.
	 */
	public function scrollSidebarToBottom() {
		$this->scrollElementToBottom('js_pwd_details');
	}

	/**
	 * Post a comment.
	 *
	 * This expects the comment form to be shown already.
	 *
	 * @param $comment
	 *
	 * @throws Exception
	 */
	public function postCommentInSidebar($comment) {
		// Make sure password field is visible again.
		$this->waitUntilISee('#js_rs_details_comments form#js_comment_add_form');

		// Scroll down in sidebar.
		$this->scrollSidebarToBottom();

		// Fill up a second comment.
		$this->inputText('js_field_comment_content', $comment);

		// Click on submit.
		$this->click('#js_rs_details_comments a.comment-submit');

		// Assert that notification is shown.
		$this->assertNotification('app_comments_addforeigncomment_success');
	}

	/********************************************************************************
	 * Passbolt Application Asserts
	 ********************************************************************************/

	/**
	 * Wait until the url match a pattern
	 * @param string $url
	 * @param bool $addBase
	 * @param int $timeout
	 * @return bool
	 * @throws Exception
	 */
	public function waitUntilUrlMatches($url, $addBase = true, $timeout = 10) {
		for ($i = 0; $i < $timeout * 10; $i++) {
			try {
				$this->assertCurrentUrl($url, $addBase);
				return true;
			}
			catch (Exception $e) {}

			// If none of the above was found, wait for 1/10 seconds, and try again.
			usleep(100000); // Sleep 1/10 seconds
		}

		$backtrace = debug_backtrace();
		$currentUrl = $this->driver->getCurrentURL();
		throw new Exception( "waitUntilURLMatches $url : Timeout thrown by " . $backtrace[1]['class'] . "::" . $backtrace[1]['function'] . "()\n . element: $url \n . current url : $currentUrl \n");
	}

	/**
	 * Wait until the css value is equal
	 * @param string $selector
	 * @param string $name
	 * @param string $expectedValue
	 * @param int $timeout
	 * @return bool
	 * @throws Exception
	 */
	public function waitUntilCssValueEqual($selector, $name, $expectedValue, $timeout = 10) {
		$elt = $this->find($selector);

		for ($i = 0; $i < $timeout * 10 * 10; $i++) {
			try {
				$value = $elt->getCssValue($name);
				$this->assertEquals($value, $expectedValue);
				return true;
			}
			catch (Exception $e) {}

			// If none of the above was found, wait for 1/10 seconds, and try again.
			usleep(100000);
		}

		$backtrace = debug_backtrace();
		throw new Exception("waitUntilCssValueEqual $name: $expectedValue Timeout thrown by " . $backtrace[1]['class'] . "::" . $backtrace[1]['function'] . "() \n");
	}

	/**
	 * Wait until the element has focus
	 * @param string $id
	 * @param int $timeout
	 * @return bool
	 * @throws Exception
	 */
	public function waitUntilElementHasFocus($id, $timeout = 10) {
		for ($i = 0; $i < $timeout * 10 * 10; $i++) {
			try {
				$this->assertElementHasFocus($id);
				return true;
			}
			catch (Exception $e) {}

			// If none of the above was found, wait for 1/10 seconds, and try again.
			usleep(100000);
		}

		$backtrace = debug_backtrace();
		throw new Exception("waitUntilElementHasFocus $id Timeout thrown by " . $backtrace[1]['class'] . "::" . $backtrace[1]['function'] . "() \n");
	}

	/**
	 * Check if the current url match the one given in parameter
	 * @param string $url
	 * @param bool $addBase
	 */
	public function assertCurrentUrl($url, $addBase = true) {
		if($addBase) {
			$url = Config::read('passbolt.url') . DS . $url;
		}
		$this->assertEquals($url, $this->driver->getCurrentURL());
	}

	/**
	 * Check if the given role is matching the one advertised on the app side
	 * @param $role
	 */
	public function assertCurrentRole($role) {
		try {
			$e = $this->findByCSS('html.' . $role);
			if(count($e)) {
				$this->assertTrue(true);
			} else {
				$this->fail('The current user role is not ' . $role);
			}
		} catch (NoSuchElementException $e) {
			$this->fail('The current user role is not ' . $role);
		}
	}

	/**
	 * Check that there is no plugin
	 */
	public function assertNoPlugin() {
		try {
			$e = $this->findByCSS('html.no-passboltplugin');
			$this->assertTrue(count($e) === 1);
		} catch (NoSuchElementException $e) {
			$this->fail('A passbolt plugin was found');
		}
	}

	/**
	 * Check that there is a plugin
	 */
	public function assertPlugin() {
		try {
			$e = $this->findByCSS('html.passboltplugin');
			$this->assertTrue(count($e) === 1);
		} catch (NoSuchElementException $e) {
			$this->fail('A passbolt plugin was not found');
		}
	}

	/**
	 * Check that there is a plugin
	 */
	public function assertNoPluginConfig() {
		try {
			$e = $this->findByCSS('html.passboltplugin.no-passboltconfig');
			$this->assertTrue(count($e) === 0);
		} catch (NoSuchElementException $e) {
			$this->assertTrue(true);
		}
	}

	/**
	 * Check that there is a plugin with a config set
	 */
	public function assertPluginConfig() {
		try {
			$e = $this->findByCSS('html.passboltplugin-config');
			$this->assertTrue((isset($e)));
		} catch (NoSuchElementException $e) {
			$this->fail('Passbolt plugin config html header not found');
		}
	}

	/**
	 * Check that the breadcumb contains the given crumbs
	 * @param $wspName The workspace name
	 * @param $crumbs The crumbs to check
	 */
	public function assertBreadcrumb($wspName, $crumbs) {
		// Find the breadcrumb element.
		$breadcrumbElement = $this->findById('js_wsp_' . $wspName . '_breadcrumb');
		// Check that the breadcrumb element contains the given crumbs.
		for ($i=0; $i< count($crumbs); $i++) {
			$this->assertElementContainsText(
				$breadcrumbElement,
				$crumbs[$i]
			);
		}
	}

	/**
	 * Check if a notification is displayed
	 * @see in passbolt/app/webroot/js/app/config/notification.json for notification uuid seed
	 * 		example: Uuid::get('app_resources_index_success') is how to create the id from the seed
	 * @param $notificationId
	 * @param string $msg
	 */
	public function assertNotification($notificationId, $msg = null) {
		$notificationId = 'notification_' . Uuid::get($notificationId);
		$this->waitUntilISee($notificationId);
		$text = $this->find($notificationId)->getText();
		if (isset($msg)) {
			$contain = false;
			if(preg_match('/^\/.+\/[a-z]*$/i', $msg)) {
				$contain = preg_match($msg, $text) != false;
			} else {
				$contain = strpos($text, $msg) !== false;
			}
			$this->assertTrue(($contain !== false), 'fail to find the notification message ' . $msg);
		}
	}

	/**
	 * Assert if a security token match user parameters
	 * @param $user array see fixtures
	 * @param $context where is the security token (master or else)
	 */
	public function assertSecurityToken($user, $context = null)
	{
		// check base color
		$securityTokenElt = $this->findByCss('.security-token');
		$this->assertElementContainsText($securityTokenElt, $user['TokenCode']);
		$this->waitUntilCssValueEqual($securityTokenElt, 'background-color', Color::toRgba($user['TokenColor']), 2);
		$this->waitUntilCssValueEqual($securityTokenElt, 'color', Color::toRgba($user['TokenTextColor']), 2);

		if ($context != 'has_encrypted_secret') {
			// check color switch when input is selected
			switch ($context) {
				case 'master':
					$this->waitUntilElementHasFocus('js_master_password_focus_first');
					$this->waitUntilISee('js_master_password');
					$this->click('js_master_password');
					break;
				case 'login':
					$this->waitUntilISee('js_master_password');
					$this->click('js_master_password');
					break;
				case 'share':
					$this->waitUntilISee('js_perm_create_form_aro_auto_cplt');
					$this->click('js_perm_create_form_aro_auto_cplt');
					break;
				default:
					$this->waitUntilISee('js_secret');
					$this->click('js_secret');
					break;
			}

			$this->waitUntilCssValueEqual($securityTokenElt, 'background-color', Color::toRgba($user['TokenTextColor']), 2);
			$this->waitUntilCssValueEqual($securityTokenElt, 'color', Color::toRgba($user['TokenColor']), 2);

			// back to normal
			$securityTokenElt->click('.security-token');
		}
	}

	/**
	 * Check if the complexity indicators match a given strength (creation/edition context)
	 * @param $strength string
	 */
	public function assertComplexity($strength) {
		$class = str_replace(' ','_',$strength);
    $this->assertVisible('#js_secret_strength.'.$class);
    $this->assertElementHasClass(
      $this->find('#js_secret_strength .progress-bar'),
      $class
    );
    // We check visibility only if the strength is available.
    if ($strength != 'not available') {
      $this->assertVisible('#js_secret_strength .progress-bar.'.$class);
    }
		$this->assertVisible('#js_secret_strength .complexity-text');
        $labelStrength = $strength != 'not available' ? $strength : 'n/a';
		$this->assertElementContainsText('#js_secret_strength .complexity-text', 'complexity: '.$labelStrength);
	}

	/**
	 * Check if the passphrase dialog is working as expected
	 */
	public function assertMasterPasswordDialog($user) {
		// Get out of the previous iframe in case we are in one
		$this->goOutOfIframe();
		// Given I can see the iframe
		$this->waitUntilISee('passbolt-iframe-master-password');
		// When I can go into the iframe
		$this->goIntoMasterPasswordIframe();
		// Then I can see the security token is valid
		$this->assertSecurityToken($user, 'master');
		// Then I can see the title
		$this->assertElementContainsText('.master-password.dialog','Please enter your passphrase');
		// Then I can see the close dialog button
		$this->assertVisible('a.dialog-close');
		// Then I can see the OK button
		$this->assertVisible('master-password-submit');
		// Then I can see the cancel button
		$this->assertVisible('a.js-dialog-close.cancel');
		// Then I go out of the iframe
		$this->goOutOfIframe();
	}

	/**
	 * Check if confirmation dialog is displayed as it should.
	 * @param string $title
	 */
	public function assertConfirmationDialog($title = '') {
		// Assert I can see the confirm dialog.
		$this->waitUntilISee('.dialog.confirm');
		// Then I can see the close dialog button
		$this->assertVisible('.dialog.confirm a.dialog-close');
		// Then I can see the cancel link.
		$this->assertVisible('.dialog.confirm a.cancel');
		// Then I can see the Ok button.
		$this->assertVisible('.dialog.confirm input#confirm-button');
		if ($title !== '') {
			// Then I can see the title
			$this->assertElementContainsText('.dialog.confirm', $title);
		}
	}

	/**
	 * Append Html in a given element according to the given id.
	 * Beware : no multiline html will be processed.
	 * @param $elId
	 * @param $html
	 */
	public function appendHtmlInPage($elId, $html) {
		$html = str_replace("'", "\'", $html);
		$script = "
		function appendHtml(el, str) {
			var div = document.createElement('div');
			div.innerHTML = str;
			while (div.children.length > 0) {
				el.appendChild(div.children[0]);
            }
		}
		var el = document.getElementById('$elId');
		appendHtml(el, '$html');
		";
		$this->driver->executeScript($script);
	}

	/**
	 * Remove an HTML element from the page.
	 * @param $elId
	 */
	public function removeElementFromPage($elId) {
		$script = "
		var element = document.getElementById('$elId');
		element.outerHTML = '';
		delete element;
		";
		$this->driver->executeScript($script);
	}

	/**
	 * Assert that the content of the clipboard match what is given
	 * @param $content
	 */
	public function assertClipboard($content) {
		// trick: we create a temporary textarea in the page.
		// and check its content match the content given
		$this->appendHtmlInPage('container', '<textarea id="webdriver-clipboard-content" style="position:absolute; top:0; left:0; z-index:999;"></textarea>');
		$e = $this->findById('webdriver-clipboard-content');
		$e->click();
		$e->sendKeys(array(WebDriverKeys::CONTROL, 'v'));
		$this->assertTrue($e->getAttribute('value') == $content);
		$this->removeElementFromPage('webdriver-clipboard-content');
	}

	/**
	 * Assert that the password has a specific permission for a target user
	 * @param $password
	 * @param $username
	 * @param $permissionType
	 * @param $options
	 */
	public function assertPermission($password, $username, $permissionType, $options = array()) {
		$this->gotoSharePassword($password['id']);

		// I can see the user has a direct permission
		$this->assertElementContainsText(
			$this->findByCss('#js_permissions_list'),
			$username
		);

		// Find the permission row element
		$rowElement = $this->findByXpath('//*[@id="js_permissions_list"]//*[.="' . $username . '"]//ancestor::li[1]');

		// I can see the permission is as expected
		$select = new WebDriverSelect($rowElement->findElement(WebDriverBy::cssSelector('.js_share_rs_perm_type')));
		$this->assertEquals($permissionType, $select->getFirstSelectedOption()->getText());

		// Close the dialog
		if (!isset($options['closeDialog']) || $options['closeDialog'] == true) {
			$this->find('.dialog .dialog-close')->click();
		}
	}

	/**
	 * Assert that the password has a specific permission for a target user, inside the sidebar
	 * @param $password
	 * @param $username
	 * @param $permissionType
	 */
	public function assertPermissionInSidebar($username, $permissionType) {
		// Wait until the permissions are loaded. (ready state).
		$this->waitUntilISee('#js_rs_details_permissions_list.ready');

		// I can see the user has a direct permission
		$this->assertElementContainsText(
			$this->findByCss('#js_rs_details_permissions_list'),
			$username
		);

		// Find the permission row element
		$rowElement = $this->findByXpath('//*[@id="js_rs_details_permissions_list"]//*[contains(@class, "permission")]//*[contains(text(), "' . $username . '")]//ancestor::li');

		// I can see the permission is as expected
		$permissionTypeElt = $rowElement->findElement(WebDriverBy::cssSelector('.subinfo'));
		$this->assertEquals($permissionType, $permissionTypeElt->getText());
	}

	/**
	 * Assert that the password has no direct permission for a target user
	 * @param $password
	 * @param $username
	 */
	public function assertNoPermission($password, $username) {
		$this->gotoSharePassword($password['id']);

		// I can see the user has a direct permission
		$this->assertElementNotContainText(
			$this->findByCss('#js_permissions_list'),
			$username
		);
	}

	/**
	 * Assert that the toggle button is in the given status (pressed or unpressed)
	 * @param     $id
	 * @param int $status
	 *
	 * @return bool
	 */
	public function assertToggleButtonStatus($id, $status = TOGGLE_BUTTON_PRESSED) {
		$toggleButton = $this->find($id);
		$classes = $toggleButton->getAttribute('class');
		$classes = explode(' ', $classes);
		$pressed = 0;
		if (in_array('selected', $classes)) {
			$pressed = 1;
		}
		$this->assertTrue($pressed == $status);

	}

	/**
	 * Assert that 2 images are same.
	 * To compare images, it uses the ImageCompare library.
	 * this library compare the colors of the 2 resized images, and see if the
	 * average color is the same.
	 * The method is described here :
	 * http://www.hackerfactor.com/blog/index.php?/archives/432-Looks-Like-It.html
	 *
	 * @param       $image1Path
	 * @param       $image2Path
	 * @param float $tolerance
	 */
	public function assertImagesAreSame($image1Path, $image2Path, $tolerance = 0.05) {
		$image1 = Image::fromFile($image1Path);
		$image2 = Image::fromFile($image2Path);
		$diff = $image1->difference($image2);
		$scoreMin = 1 - $tolerance;
		$this->assertTrue($diff >= $scoreMin );
	}

    /**
     * Assert that a filter is selected.
     * @param $filterId
     */
    public function assertFilterIsSelected($filterId) {
        $this->assertElementHasClass(
          $this->find("#$filterId .row"),
          'selected'
        );
    }

    /**
     * Assert that a filter is not selected.
     * @param $filterId
     */
    public function assertFilterIsNotSelected($filterId) {
        $this->assertElementHasNotClass(
          $this->find("#$filterId .row"),
          'selected'
        );
    }

	/**
	 * Assert a password is selected
	 * @param $id string
	 * @return bool
	 */
	public function assertPasswordSelected($id) {
		$this->assertTrue($this->isPasswordSelected($id));
	}

	/**
	 * Assert a password is not selected
	 * @param $id string
	 * @return bool
	 */
	public function assertPasswordNotSelected($id) {
		$this->assertTrue($this->isPasswordNotSelected($id));
	}

	/**
	 * Assert a user is selected
	 * @param $id string
	 * @return bool
	 */
	public function assertUserSelected($id) {
		$this->assertTrue($this->isUserSelected($id));
	}

	/**
	 * Assert a is not selected
	 * @param $id string
	 * @return bool
	 */
	public function assertUserNotSelected($id) {
		$this->assertTrue($this->isUserNotSelected($id));
	}
}
