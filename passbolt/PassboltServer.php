<?php
/**
 * PassboltServer
 * The class to interact with passbolt server/API.
 *
 * @copyright (c) 2015-present Bolt Softwares Pvt Ltd
 * @licence GNU Affero General Public License http://www.gnu.org/licenses/agpl-3.0.en.html
 */
class PassboltServer {

	/**
	 * Reset passbolt installation
	 * @return bool
	 */
	static public function resetDatabase($url, $dummy = 'seleniumtests') {
		$response = \Httpful\Request::get($url . '/seleniumTests/resetInstance/' . $dummy)
	                   ->send();
		return preg_match('/created/', $response->body);
	}

	/**
	 * Add extra server configuration.
	 */
	static public function setExtraConfig($config = []) {
		$url = Config::read('passbolt.url') . DS . '/seleniumTests/setExtraConfig';
		$request = Httpful\Request::post($url, $config);
		$request->sendsType(Httpful\Mime::JSON)->send();
	}

	/**
	 * Reset extra server configuration.
	 */
	static public function resetExtraConfig() {
		$url = Config::read('passbolt.url') . DS . '/seleniumTests/resetExtraConfig';
		$request = Httpful\Request::post($url);
		$request->sendsType(Httpful\Mime::JSON)->send();
	}
}
