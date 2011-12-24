<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is lisensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 * 	  the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website\'s Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attibutes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2011 (or current year) ExamNotes.net inc.
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */

namespace Lampcms\Controllers;

use \Lampcms\WebPage;
use \Lampcms\Responder;
use \Lampcms\Request;

class Loginlinkedin extends WebPage
{
	const REQUEST_TOKEN_URL = 'https://api.linkedin.com/uas/oauth/requestToken?oauth_callback=';

	const ACCESS_TOKEN_URL = 'https://api.linkedin.com/uas/oauth/accessToken';

	const AUTHORIZE_URL = 'https://www.linkedin.com/uas/oauth/authenticate';

	//,location:(name) cannot be used together with locationlocation:(country:(code)) it generates duplicate field exception
	const PROFILE_URL = 'http://api.linkedin.com/v1/people/~:(id,first-name,last-name,industry,picture-url,public-profile-url,location,summary,interests,date-of-birth,twitter-accounts,phone-numbers,skills,im-accounts,educations,certifications,languages)';

	protected $callback = '/index.php?a=loginlinkedin';

	/**
	 * Array of Tumblr's
	 * oauth_token and oauth_token_secret
	 *
	 * @var array
	 */
	protected $aAccessToken = array();


	/**
	 * Object php OAuth
	 *
	 * @var object of type php OAuth
	 * must have oauth extension for this
	 */
	protected $oAuth;


	protected $bInitPageDoc = false;


	/**
	 * Configuration of LinkedIn API
	 * this is array of values LINKEDIN section
	 * in !config.ini
	 *
	 * @var array
	 */
	protected $aTM = array();

	/**
	 * Flag indicates that this is the
	 * request to connect Twitter account
	 * with existing user account.
	 *
	 * @var bool
	 */
	protected $bConnect = false;

	/**
	 *
	 * @var array of data from LinkedIn API,
	 * created from the parsed XML response
	 */
	protected $aData;


	/**
	 * The main purpose of this class is to
	 * generate the oAuth token
	 * and then redirect browser to twitter url with
	 * this unique token
	 *
	 * No actual page generation will take place
	 *
	 * @see classes/WebPage#main()
	 */
	protected function main(){

		if(!extension_loaded('oauth')){
			throw new \Exception('Unable to use Tumblr API because OAuth extension is not available');
		}

		/**
		 * If user is logged in then this is
		 * a request to connect Twitter Account
		 * with existing account.
		 *
		 * @todo check that user does not already have
		 * Twitter credentials and if yes then call
		 * closeWindows as it would indicate that user
		 * is already connected with Twitter
		 */
		if($this->isLoggedIn()){
			$this->bConnect = true;
		}

		d('$this->bConnect: '.$this->bConnect);

		$this->callback = $this->Registry->Ini->SITE_URL.$this->callback;
		d('$this->callback: '.$this->callback);

		$this->aTm = $this->Registry->Ini['LINKEDIN'];

		try {
			$this->oAuth = new \OAuth($this->aTm['OAUTH_KEY'], $this->aTm['OAUTH_SECRET'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
			$this->oAuth->enableDebug();
		} catch(\OAuthException $e) {
			e('OAuthException: '.$e->getMessage());

			throw new \Exception('Something went wrong during authorization. Please try again later'.$e->getMessage());
		}


		/**
		 * If this is start of dance then
		 * generate token, secret and store them
		 * in session and redirect to linkedin authorization page
		 */
		if(empty($_SESSION['linkedin_oauth']) || empty($this->Request['oauth_token'])){
			/**
			 * Currently Tumblr does not handle "Deny" response of user
			 * too well - they just redirect back to this url
			 * without any clue that user declined to authorize
			 * our application.
			 */
			$this->step1();
		} else {
			$this->step2();
		}
	}


	/**
	 * Generate oAuth request token
	 * and redirect to linkedin for authentication
	 *
	 * @return object $this
	 *
	 * @throws Exception in case something goes wrong during
	 * this stage
	 */
	protected function step1(){

		$requestUrl = self::REQUEST_TOKEN_URL.$this->callback;

		try {
			// State 0 - Generate request token and redirect user to linkedin to authorize

			d('requestUrl: '.$requestUrl);

			$_SESSION['linkedin_oauth'] = $this->oAuth->getRequestToken($requestUrl);

			d('$_SESSION[\'linkedin_oauth\']: '.print_r($_SESSION['linkedin_oauth'], 1));
			if(!empty($_SESSION['linkedin_oauth']) && !empty($_SESSION['linkedin_oauth']['oauth_token'])){
				d('cp');
				/**
				 * A more advanced way is to NOT use Location header
				 * but instead generate the HTML that contains the onBlur = focus()
				 * and then redirect with javascript
				 * This is to prevent from popup window going out of focus
				 * in case user clicks outsize the popup somehow
				 */
				$this->redirectToSite(self::AUTHORIZE_URL.'?oauth_token='.$_SESSION['linkedin_oauth']['oauth_token']);
			} else {
				/**
				 * Here throw regular Exception, not Lampcms\Exception
				 * so that it will be caught ONLY by the index.php and formatted
				 * on a clean page, without any template
				 */

				throw new \Exception("Failed fetching request token, response was: " . $this->oAuth->getLastResponse());
			}
		} catch(\OAuthException $e) {
			e('OAuthException: '.$e->getMessage().' '.print_r($e, 1));

			throw new \Exception('Something went wrong during authorization. Please try again later'.$e->getMessage());
		}

		return $this;
	}


	/**
	 * Step 2 in oAuth process
	 * this is when linkedin redirected the user back
	 * to our callback url, which calls this controller
	 * @return object $this
	 *
	 * @throws Exception in case something goes wrong with oAuth class
	 */
	protected function step2(){

		try {
			/**
			 * This is a callback (redirected back from linkedin page
			 * after user authorized us)
			 * In this case we must: create account or update account
			 * in USER table
			 * Re-create oViewer object
			 * send cookie to remember user
			 * and then send out HTML with js instruction to close the popup window
			 */
			d('Looks like we are at step 2 of authentication. Request: '.print_r($_REQUEST, 1));

			/**
			 * @todo check first to make sure we do have oauth_token
			 * on REQUEST, else close the window
			 */
			$this->oAuth->setToken($this->Request['oauth_token'], $_SESSION['linkedin_oauth']['oauth_token_secret']);

			$ver = $this->Registry->Request->get('oauth_verifier', 's', '');
			d(' $ver: '.$ver);
			$url = (empty($var)) ? self::ACCESS_TOKEN_URL : self::ACCESS_TOKEN_URL.'?oauth_verifier='.$ver;
			d('url: '.$url);

			$this->aAccessToken = $this->oAuth->getAccessToken($url);
			d('$this->aAccessToken: '.print_r($this->aAccessToken, 1));

			unset($_SESSION['linkedin_oauth']);

			$this->oAuth->setToken($this->aAccessToken['oauth_token'], $this->aAccessToken['oauth_token_secret']);

			$this->oAuth->fetch(self::PROFILE_URL);
			$resp = $this->oAuth->getLastResponse();
			$this->parseXML($resp);

			$this->createOrUpdate();

			if(!$this->bConnect){
				\Lampcms\Cookie::sendLoginCookie($this->Registry->Viewer->getUid(), $this->User->rs);
			} else {
				/**
				 * The b_li flag in Viewer is necessary
				 * for the social checkboxes to set
				 * the checkbox to 'checked' state
				 *
				 */
				$this->Registry->Viewer['b_li'] = true;
			}

			$this->closeWindow();

		} catch(\OAuthException $e) {
			e('OAuthException: '.$e->getMessage().' '.print_r($e, 1));

			$err = 'Something went wrong during authorization. Please try again later'.$e->getMessage();
			throw new \Exception($err);
		}

		return $this;
	}


	protected function createOrUpdate(){

		$aUser = $this->getUserByLinkedInId($this->aData['linkedin_id']);

		if(!empty($this->bConnect)){
			d('this is connect action');

			$this->User = $this->Registry->Viewer;
			$this->updateUser();

		} elseif(!empty($aUser)){
			$this->User = \Lampcms\UserLinkedin::factory($this->Registry, $aUser);
			$this->updateUser(); // only update token, secret, linkedin url
		} else {
			$this->isNewAccount = true;
			$this->createNewUser();
		}


		try{
			$this->processLogin($this->User);
		} catch(\Lampcms\LoginException $e){
			/**
			 * re-throw as regular exception
			 * so that it can be caught and show in popup window
			 */
			e('Unable to process login: '.$e->getMessage());
			throw new \Exception($e->getMessage());
		}

		$this->Registry->Dispatcher->post( $this, 'onLinkedinLogin' );

		return $this;
	}


	/**
	 * Create new record in the USERS collection
	 * also set the $this->User to the newly created
	 * instance of UserLinkedin object
	 *
	 *
	 */
	protected function createNewUser(){

		d('$this->aData: '.print_r($this->aData, 1));

		$ln = (!empty($this->aData['ln'])) ? $this->aData['ln'] : '';

		$oEA = \Lampcms\ExternalAuth::factory($this->Registry);
		$u = $this->aData['fn'].'_'.$ln;
		d('$u: '.$u);

		$username = $oEA->makeUsername($u);
		$sid = \Lampcms\Cookie::getSidCookie();
		d('sid is: '.$sid);

		$this->aData['username'] = $username;
		$this->aData['username_lc'] = \mb_strtolower($username, 'utf-8');

		$this->aData['i_reg_ts'] = time();
		$this->aData['date_reg'] = date('r');
		$this->aData['role'] = 'external_auth';

		$this->aData['rs'] =  (false !== $sid) ? $sid : \Lampcms\String::makeSid();
		$this->aData['i_rep'] = 1;
		$this->aData['lang'] = $this->Registry->getCurrentLang();
		$this->aData['locale'] = $this->Registry->Locale->getLocale();

		if(empty($this->aData['cc']) && empty($this->aData['city'])){
			$this->aData = array_merge($this->Registry->Geo->Location->data, $this->aData);
		}

		$this->User = \Lampcms\UserLinkedin::factory($this->Registry, $this->aData);

		/**
		 * This will mark this userobject is new user
		 * and will be persistent for the duration of this session ONLY
		 * This way we can know it's a newsly registered user
		 * and ask the user to provide email address but only
		 * during the same session
		 */
		$this->User->setNewUser();
		d('isNewUser: '.$this->User->isNewUser());
		$this->User->save();

		\Lampcms\PostRegistration::createReferrerRecord($this->Registry, $this->User);

		$this->Registry->Dispatcher->post($this->User, 'onNewUser');

		return $this;

	}


	/**
	 * Parses the XML returned from LinkedIn API
	 * and creates array of $this->aData from it
	 *
	 * @param string xml xml string received from LinkedIn API
	 *
	 * @return object $this
	 *
	 * @throws \Lampcms\Exception if xml could not
	 * be parsed for any reason
	 */
	protected function parseXML($xml){

		d('xml: '.$xml);

		$oXML = new \Lampcms\Dom\Document();
		if(false === $oXML->loadXML($xml)){
			$err = 'Unexpected Error parsing response XML';

			throw new \Lampcms\DevException($err);
		}

		$lid = $oXML->evaluate('string(/person/id[1])'); // it will be string!
		if(!$lid){
			throw new \Lampcms\Exception('Unable to get LinkedIn ID');
		}

		$this->aData['linkedin_id'] = (string)$lid;

		if('' !==  $industry = $oXML->evaluate('string(/person/industry[1])')){
			$this->aData['industry'] = $industry;
		}

		if('' !== $summary  = $oXML->evaluate('string(/person/summary[1])')){
			$this->aData['description'] = $summary;
		}

		if('' !== $city = $oXML->evaluate('string(/person/location/name[1])')){
			$this->aData['city'] = $city;
		}

		if('' !== $cc  = $oXML->evaluate('string(/person/location/country/code[1])')){
			$this->aData['cc'] = \strtoupper($cc);
		}

		if('' !== $avtr  = $oXML->evaluate('string(/person/picture-url[1])')){
			$this->aData['avatar_external'] = $avtr;
		}

		if('' !== $fn  = $oXML->evaluate('string(/person/first-name[1])')){
			$this->aData['fn'] = $fn;
		}

		if('' !== $ln  = $oXML->evaluate('string(/person/last-name[1])')){
			$this->aData['ln'] = $ln;
		}

		$this->aData['linkedin'] = array(
			'tokens' => $this->aAccessToken
		);

		if('' !== $url = $oXML->evaluate('string(/person/public-profile-url[1])')){
			$this->aData['linkedin']['url'] = $url;
		}
			
		d('$this->aData: '.print_r($this->aData, 1));

		return $this;
	}


	/**
	 *
	 * Adds data from LinkedIn API, including
	 * oauth token, secret to the
	 * User object
	 * avatar from LinkedIn, Contry Code, City
	 * and 'about' are added ONLY if they
	 * don't already exist in User
	 *
	 * @post-condition: $this->User object is updated
	 * with the valued from $this->aData AND $this->aAccessToken
	 * and then saved using save()
	 *
	 * @return $this
	 */
	protected function updateUser(){

		$avtr = $this->User['avatar_external'];
		/**
		 * Special case:
		 * if connecting user and another user
		 * already exists with the same linkedin_id
		 * then we will still allow to add linkedin key
		 * to this Viewer's profile
		 * but will NOT add the linkedin_id to the Viewer object
		 * This is because otherwise we will have 2 users
		 * with the same value of linkedin_id and then
		 * when logging in with LinkedIN we will not know
		 * which user to login. This is why we will enforce uniqueness
		 * of linkedin_id key here
		 */
		if($this->bConnect){
			$a = $this->Registry->Mongo->USERS->findOne(array('linkedin_id' => $this->aData['linkedin_id']), array('_id' => 1));
			if(empty($a)){
				$this->User['linkedin_id'] = $this->aData['linkedin_id'];
			}
		} else {
			$this->User['linkedin_id'] = $this->aData['linkedin_id'];
		}

		/**
		 * Update the following field ONLY
		 * if they DONT already exists in this user's record!
		 *
		 * This means that if record exists and is an empty
		 * string - don't update this because it usually means
		 * that user did have this field before and then removed
		 * the value by editing profile.
		 */
		if(empty($avtr) && !empty($this->aData['avatar_external'])){
			$this->User['avatar_external'] = $this->aData['avatar_external'];
		}

		if(null === $this->User['description'] && !empty($this->aData['description'])){
			$this->User['description'] = $this->aData['description'];
		}

		if(null === $this->User['cc'] && !empty($this->aData['cc'])){
			$this->User['cc'] = $this->aData['cc'];
		}

		if(null === $this->User['city'] && !empty($this->aData['city'])){
			$this->User['city'] = $this->aData['city'];
		}

		/**
		 * Always update the 'linkedin' element
		 * of user record. It contains 2 keys: tokens
		 * with is array holding oauth tokens
		 * and optionally 'url' with linkenin profile url
		 *
		 */
		$this->User['linkedin'] = $this->aData['linkedin'];

		$this->User->save();

		return $this;
	}


	/**
	 * Find user data in USERS collection
	 * using linkedin_id key
	 *
	 * @todo make linkedin_id a unique index
	 * This will add extra protection against
	 * allowing more than one user to have same
	 * linked-in account
	 *
	 * @param string $lid LinkedIn id - from LinkedIn website
	 * It is a string, not an integer!
	 *
	 * @return mixed null | array of user data
	 *
	 */
	protected function getUserByLinkedInId($lid){
		$coll = $this->Registry->Mongo->USERS;
		$coll->ensureIndex(array('linkedin_id' => 1));

		$aUser = $coll->findOne(array('linkedin_id' => (string)$lid));
		d('aUser: '.print_r($aUser, 1));

		return $aUser;
	}



	/**
	 * Return html that contains JS window.close code and nothing else
	 *
	 * @return unknown_type
	 */
	protected function closeWindow(array $a = array()){
		//exit;

		d('cp a: '.print_r($a, 1));
		$js = '';

		$tpl = '
		var myclose = function(){
		window.close();
		}
		if(window.opener){
		%s
		setTimeout(myclose, 100); // give opener window time to process login and cancell intervals
		}else{
			alert("This is not a popup window or opener window gone away");
		}';
		d('cp');

		$script = \sprintf($tpl, $js);

		$s = Responder::PAGE_OPEN. Responder::JS_OPEN.
		$script.
		Responder::JS_CLOSE.
		'<h2>You have successfully connected your LinkedIn account. You should close this window now</h2>'.

		Responder::PAGE_CLOSE;
		d('cp s: '.$s);
		echo $s;
		fastcgi_finish_request();
		exit;
	}


	/**
	 * @todo add YUI Event lib
	 * and some JS to subscribe to blur event
	 * so that onBlur runs not just the first onBlur time
	 * but all the time
	 *
	 * @param string $url of linkedin oauth, including request token
	 * @return void
	 */
	protected function redirectToSite($url){
		d('linkedin redirect url: '.$url);
		/**
		 * @todo translate this string
		 *
		 */
		$s = Responder::PAGE_OPEN. Responder::JS_OPEN.
		'setTZOCookie = (function() {
		getTZO = function() {
		var tzo, nd = new Date();
		tzo = (0 - (nd.getTimezoneOffset() * 60));
		return tzo;
	    }
		var tzo = getTZO();
		document.cookie = "tzo="+tzo+";path=/";
		})();
		
		
		var myredirect = function(){
			window.location.assign("'.$url.'");
		};
			setTimeout(myredirect, 300);
			'.
		Responder::JS_CLOSE.
		'<div class="centered"><a href="'.$url.'">If you are not redirected in 2 seconds, click here to authenticate with linkedin</a></div>'.
		Responder::PAGE_CLOSE;

		d('exiting with this $s: '.$s);

		exit($s);
	}

}
