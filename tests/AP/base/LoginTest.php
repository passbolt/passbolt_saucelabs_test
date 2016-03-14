<?php
/**
 * Anonymous user with plugin but no config login test
 *
 * @copyright 	(c) 2015-present Bolt Software Pvt. Ltd.
 * @licence			GPLv3 onwards www.gnu.org/licenses/gpl-3.0.en.html
 */
class LoginTest extends PassboltTestCase {

    public function testLogin() {
        $this->getUrl('login');
        $this->waitUntIlISee('.plugin-check.firefox.warning', null, 2);
    }

	/**
	 * Test that if the wrong domain is configured, we will see a page explaining that
	 * the domain is not known.
	 * @throws Exception
	 */
	public function testWrongDomain() {
		$user = User::get('ada');
		$user['domain'] = 'https://custom.passbolt.com';
		$this->setClientConfig($user);

		$this->getUrl('login');
		$this->waitUntilISee('html.domain-unknown');
		$this->waitUntilISee('a.trusteddomain', '/https:\/\/custom\.passbolt\.com/');
	}

	/**
	 * Test that if the server verification failed, we will see a page explaining that
	 * something went wrong with a message explaining what happened
	 * @throws Exception
	 */
	public function testStage0VerifyError() {
		$user = User::get('ada');
		$this->setClientConfig($user);

		// Load a wrong public server key.
		$this->getUrl('debug');
		$this->waitUntilISee('.config.page');
		$key = file_get_contents(GPG_FIXTURES . DS . 'user_public.key');
		$this->inputText('serverKeyAscii', $key);
		$this->click('saveServerKey');
		$this->waitUntilISee('.server.key-import.feedback', '/The key has been imported successfully/');

		$this->getUrl('login');
		$this->waitUntilISee('html.server-not-verified');
		$this->assertElementContainsText('.plugin-check.gpg', 'Decryption failed');
	}

}