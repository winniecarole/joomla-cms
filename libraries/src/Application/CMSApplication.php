<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2013 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Application;

\defined('JPATH_PLATFORM') or die;

use Joomla\Application\SessionAwareWebApplicationTrait;
use Joomla\Application\Web\WebClient;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Exception\ExceptionHandler;
use Joomla\CMS\Extension\ExtensionManagerTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Input\Input;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\CMS\Pathway\Pathway;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Profiler\Profiler;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Session\MetadataManager;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\DI\Container;
use Joomla\DI\ContainerAwareInterface;
use Joomla\DI\ContainerAwareTrait;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;

/**
 * Joomla! CMS Application class
 *
 * @since  3.2
 */
abstract class CMSApplication extends WebApplication implements ContainerAwareInterface, CMSWebApplicationInterface
{
	use ContainerAwareTrait, ExtensionManagerTrait, ExtensionNamespaceMapper, SessionAwareWebApplicationTrait;

	/**
	 * Array of options for the \JDocument object
	 *
	 * @var    array
	 * @since  3.2
	 */
	protected $docOptions = array();

	/**
	 * Application instances container.
	 *
	 * @var    CmsApplication[]
	 * @since  3.2
	 */
	protected static $instances = array();

	/**
	 * The scope of the application.
	 *
	 * @var    string
	 * @since  3.2
	 */
	public $scope = null;

	/**
	 * The client identifier.
	 *
	 * @var    integer
	 * @since  4.0.0
	 */
	protected $clientId = null;

	/**
	 * The application message queue.
	 *
	 * @var    array
	 * @since  4.0.0
	 */
	protected $messageQueue = array();

	/**
	 * The name of the application.
	 *
	 * @var    string
	 * @since  4.0.0
	 */
	protected $name = null;

	/**
	 * The profiler instance
	 *
	 * @var    Profiler
	 * @since  3.2
	 */
	protected $profiler = null;

	/**
	 * Currently active template
	 *
	 * @var    object
	 * @since  3.2
	 */
	protected $template = null;

	/**
	 * The pathway object
	 *
	 * @var    Pathway
	 * @since  4.0.0
	 */
	protected $pathway = null;

	/**
	 * The authentication plugin type
	 *
	 * @var   string
	 * @since  4.0.0
	 */
	protected $authenticationPluginType = 'authentication';

	/**
	 * Class constructor.
	 *
	 * @param   Input      $input      An optional argument to provide dependency injection for the application's input
	 *                                 object.  If the argument is a JInput object that object will become the
	 *                                 application's input object, otherwise a default input object is created.
	 * @param   Registry   $config     An optional argument to provide dependency injection for the application's config
	 *                                 object.  If the argument is a Registry object that object will become the
	 *                                 application's config object, otherwise a default config object is created.
	 * @param   WebClient  $client     An optional argument to provide dependency injection for the application's client
	 *                                 object.  If the argument is a WebClient object that object will become the
	 *                                 application's client object, otherwise a default client object is created.
	 * @param   Container  $container  Dependency injection container.
	 *
	 * @since   3.2
	 */
	public function __construct(Input $input = null, Registry $config = null, WebClient $client = null, Container $container = null)
	{
		$container = $container ?: new Container;
		$this->setContainer($container);

		parent::__construct($input, $config, $client);

		// If JDEBUG is defined, load the profiler instance
		if (\defined('JDEBUG') && JDEBUG)
		{
			$this->profiler = Profiler::getInstance('Application');
		}

		// Enable sessions by default.
		if ($this->config->get('session') === null)
		{
			$this->config->set('session', true);
		}

		// Set the session default name.
		if ($this->config->get('session_name') === null)
		{
			$this->config->set('session_name', $this->getName());
		}
	}

	/**
	 * Checks the user session.
	 *
	 * If the session record doesn't exist, initialise it.
	 * If session is new, create session variables
	 *
	 * @return  void
	 *
	 * @since   3.2
	 * @throws  \RuntimeException
	 */
	public function checkSession()
	{
		$this->getContainer()->get(MetadataManager::class)->createOrUpdateRecord($this->getSession(), $this->getIdentity());
	}

	/**
	 * Enqueue a system message.
	 *
	 * @param   string  $msg   The message to enqueue.
	 * @param   string  $type  The message type. Default is message.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	public function enqueueMessage($msg, $type = self::MSG_INFO)
	{
		// Don't add empty messages.
		if (trim($msg) === '')
		{
			return;
		}

		$inputFilter = InputFilter::getInstance(
			[],
			[],
			InputFilter::ONLY_BLOCK_DEFINED_TAGS,
			InputFilter::ONLY_BLOCK_DEFINED_ATTRIBUTES
		);

		// Build the message array and apply the HTML InputFilter with the default blacklist to the message
		$message = array(
			'message' => $inputFilter->clean($msg, 'html'),
			'type'    => $inputFilter->clean(strtolower($type), 'cmd'),
		);

		// For empty queue, if messages exists in the session, enqueue them first.
		$messages = $this->getMessageQueue();

		if (!\in_array($message, $this->messageQueue))
		{
			// Enqueue the message.
			$this->messageQueue[] = $message;
		}
	}

	/**
	 * Ensure several core system input variables are not arrays.
	 *
	 * @return  void
	 *
	 * @since   3.9
	 */
	private function sanityCheckSystemVariables()
	{
		$input = $this->input;

		// Get invalid input variables
		$invalidInputVariables = array_filter(
			array('option', 'view', 'format', 'lang', 'Itemid', 'template', 'templateStyle', 'task'),
			function ($systemVariable) use ($input) {
				return $input->exists($systemVariable) && is_array($input->getRaw($systemVariable));
			}
		);

		// Unset invalid system variables
		foreach ($invalidInputVariables as $systemVariable)
		{
			$input->set($systemVariable, null);
		}

		// Abort when there are invalid variables
		if ($invalidInputVariables)
		{
			throw new \RuntimeException('Invalid input, aborting application.');
		}
	}

	/**
	 * Execute the application.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	public function execute()
	{
		try
		{
			$this->sanityCheckSystemVariables();
			$this->setupLogging();
			$this->createExtensionNamespaceMap();

			// Perform application routines.
			$this->doExecute();

			// If we have an application document object, render it.
			if ($this->document instanceof \Joomla\CMS\Document\Document)
			{
				// Render the application output.
				$this->render();
			}

			// If gzip compression is enabled in configuration and the server is compliant, compress the output.
			if ($this->get('gzip') && !ini_get('zlib.output_compression') && ini_get('output_handler') !== 'ob_gzhandler')
			{
				$this->compress();

				// Trigger the onAfterCompress event.
				$this->triggerEvent('onAfterCompress');
			}
		}
		catch (\Throwable $throwable)
		{
			/** @var ErrorEvent $event */
			$event = AbstractEvent::create(
				'onError',
				[
					'subject'     => $throwable,
					'eventClass'  => ErrorEvent::class,
					'application' => $this,
				]
			);

			// Trigger the onError event.
			$this->triggerEvent('onError', $event);

			ExceptionHandler::handleException($event->getError());
		}

		// Send the application response.
		$this->respond();

		// Trigger the onAfterRespond event.
		$this->triggerEvent('onAfterRespond');
	}

	/**
	 * Check if the user is required to reset their password.
	 *
	 * If the user is required to reset their password will be redirected to the page that manage the password reset.
	 *
	 * @param   string  $option  The option that manage the password reset
	 * @param   string  $view    The view that manage the password reset
	 * @param   string  $layout  The layout of the view that manage the password reset
	 * @param   string  $tasks   Permitted tasks
	 *
	 * @return  void
	 *
	 * @throws  \Exception
	 */
	protected function checkUserRequireReset($option, $view, $layout, $tasks)
	{
		if (Factory::getUser()->get('requireReset', 0))
		{
			$redirect = false;

			/*
			 * By default user profile edit page is used.
			 * That page allows you to change more than just the password and might not be the desired behavior.
			 * This allows a developer to override the page that manage the password reset.
			 * (can be configured using the file: configuration.php, or if extended, through the global configuration form)
			 */
			$name = $this->getName();

			if ($this->get($name . '_reset_password_override', 0))
			{
				$option = $this->get($name . '_reset_password_option', '');
				$view   = $this->get($name . '_reset_password_view', '');
				$layout = $this->get($name . '_reset_password_layout', '');
				$tasks  = $this->get($name . '_reset_password_tasks', '');
			}

			$task = $this->input->getCmd('task', '');

			// Check task or option/view/layout
			if (!empty($task))
			{
				$tasks = explode(',', $tasks);

				// Check full task version "option/task"
				if (array_search($this->input->getCmd('option', '') . '/' . $task, $tasks) === false)
				{
					// Check short task version, must be on the same option of the view
					if ($this->input->getCmd('option', '') !== $option || array_search($task, $tasks) === false)
					{
						// Not permitted task
						$redirect = true;
					}
				}
			}
			else
			{
				if ($this->input->getCmd('option', '') !== $option || $this->input->getCmd('view', '') !== $view
					|| $this->input->getCmd('layout', '') !== $layout)
				{
					// Requested a different option/view/layout
					$redirect = true;
				}
			}

			if ($redirect)
			{
				// Redirect to the profile edit page
				$this->enqueueMessage(Text::_('JGLOBAL_PASSWORD_RESET_REQUIRED'), 'notice');

				$url = Route::_('index.php?option=' . $option . '&view=' . $view . '&layout=' . $layout, false);

				// In the administrator we need a different URL
				if (strtolower($name) === 'administrator')
				{
					$user = Factory::getApplication()->getIdentity();
					$url  = Route::_('index.php?option=' . $option . '&task=' . $view . '.' . $layout . '&id=' . $user->id, false);
				}

				$this->redirect($url);
			}
		}
	}

	/**
	 * Gets a configuration value.
	 *
	 * @param   string  $varname  The name of the value to get.
	 * @param   string  $default  Default value to return
	 *
	 * @return  mixed  The user state.
	 *
	 * @since   3.2
	 * @deprecated  5.0  Use get() instead
	 */
	public function getCfg($varname, $default = null)
	{
		try
		{
			\JLog::add(
				sprintf('%s() is deprecated and will be removed in 5.0. Use JFactory->getApplication()->get() instead.', __METHOD__),
				\JLog::WARNING,
				'deprecated'
			);
		}
		catch (\RuntimeException $exception)
		{
			// Informational log only
		}

		return $this->get($varname, $default);
	}

	/**
	 * Gets the client id of the current running application.
	 *
	 * @return  integer  A client identifier.
	 *
	 * @since   3.2
	 */
	public function getClientId()
	{
		return $this->clientId;
	}

	/**
	 * Returns a reference to the global CmsApplication object, only creating it if it doesn't already exist.
	 *
	 * This method must be invoked as: $web = CmsApplication::getInstance();
	 *
	 * @param   string     $name       The name (optional) of the CmsApplication class to instantiate.
	 * @param   string     $prefix     The class name prefix of the object.
	 * @param   Container  $container  An optional dependency injection container to inject into the application.
	 *
	 * @return  CmsApplication
	 *
	 * @since       3.2
	 * @throws      \RuntimeException
	 * @deprecated  5.0 Use \Joomla\CMS\Factory::getContainer()->get($name) instead
	 */
	public static function getInstance($name = null, $prefix = '\JApplication', Container $container = null)
	{
		if (empty(static::$instances[$name]))
		{
			// Create a CmsApplication object.
			$classname = $prefix . ucfirst($name);

			if (!$container)
			{
				$container = Factory::getContainer();
			}

			if ($container->has($classname))
			{
				static::$instances[$name] = $container->get($classname);
			}
			elseif (class_exists($classname))
			{
				// TODO - This creates an implicit hard requirement on the JApplicationCms constructor
				static::$instances[$name] = new $classname(null, null, null, $container);
			}
			else
			{
				throw new \RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_APPLICATION_LOAD', $name), 500);
			}

			static::$instances[$name]->loadIdentity(Factory::getUser());
		}

		return static::$instances[$name];
	}

	/**
	 * Returns the application \JMenu object.
	 *
	 * @param   string  $name     The name of the application/client.
	 * @param   array   $options  An optional associative array of configuration settings.
	 *
	 * @return  AbstractMenu
	 *
	 * @since   3.2
	 */
	public function getMenu($name = null, $options = array())
	{
		if (!isset($name))
		{
			$name = $this->getName();
		}

		// Inject this application object into the \JMenu tree if one isn't already specified
		if (!isset($options['app']))
		{
			$options['app'] = $this;
		}

		return AbstractMenu::getInstance($name, $options);
	}

	/**
	 * Get the system message queue.
	 *
	 * @param   boolean  $clear  Clear the messages currently attached to the application object
	 *
	 * @return  array  The system message queue.
	 *
	 * @since   3.2
	 */
	public function getMessageQueue($clear = false)
	{
		// For empty queue, if messages exists in the session, enqueue them.
		if (!\count($this->messageQueue))
		{
			$sessionQueue = $this->getSession()->get('application.queue', []);

			if ($sessionQueue)
			{
				$this->messageQueue = $sessionQueue;
				$this->getSession()->set('application.queue', []);
			}
		}

		$messageQueue = $this->messageQueue;

		if ($clear)
		{
			$this->messageQueue = array();
		}

		return $messageQueue;
	}

	/**
	 * Gets the name of the current running application.
	 *
	 * @return  string  The name of the application.
	 *
	 * @since   3.2
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Returns the application Pathway object.
	 *
	 * @return  Pathway
	 *
	 * @since   3.2
	 */
	public function getPathway()
	{
		if (!$this->pathway)
		{
			$resourceName = ucfirst($this->getName()) . 'Pathway';

			if (!$this->getContainer()->has($resourceName))
			{
				throw new \RuntimeException(
					Text::sprintf('JLIB_APPLICATION_ERROR_PATHWAY_LOAD', $this->getName()),
					500
				);
			}

			$this->pathway = $this->getContainer()->get($resourceName);
		}

		return $this->pathway;
	}

	/**
	 * Returns the application Router object.
	 *
	 * @param   string  $name     The name of the application.
	 * @param   array   $options  An optional associative array of configuration settings.
	 *
	 * @return  Router
	 *
	 * @since   3.2
	 */
	public static function getRouter($name = null, array $options = array())
	{
		$app = Factory::getApplication();

		if (!isset($name))
		{
			$name = $app->getName();
		}

		$options['mode'] = $app->get('sef');

		return Router::getInstance($name, $options);
	}

	/**
	 * Gets the name of the current template.
	 *
	 * @param   boolean  $params  An optional associative array of configuration settings
	 *
	 * @return  mixed  System is the fallback.
	 *
	 * @since   3.2
	 */
	public function getTemplate($params = false)
	{
		if ($params)
		{
			$template = new \stdClass;

			$template->template    = 'system';
			$template->params      = new Registry;
			$template->inheritable = 0;
			$template->parent      = '';

			return $template;
		}

		return 'system';
	}

	/**
	 * Gets a user state.
	 *
	 * @param   string  $key      The path of the state.
	 * @param   mixed   $default  Optional default value, returned if the internal value is null.
	 *
	 * @return  mixed  The user state or null.
	 *
	 * @since   3.2
	 */
	public function getUserState($key, $default = null)
	{
		$registry = $this->getSession()->get('registry');

		if ($registry !== null)
		{
			return $registry->get($key, $default);
		}

		return $default;
	}

	/**
	 * Gets the value of a user state variable.
	 *
	 * @param   string  $key      The key of the user state variable.
	 * @param   string  $request  The name of the variable passed in a request.
	 * @param   string  $default  The default value for the variable if not found. Optional.
	 * @param   string  $type     Filter for the variable, for valid values see {@link InputFilter::clean()}. Optional.
	 *
	 * @return  mixed  The request user state.
	 *
	 * @since   3.2
	 */
	public function getUserStateFromRequest($key, $request, $default = null, $type = 'none')
	{
		$cur_state = $this->getUserState($key, $default);
		$new_state = $this->input->get($request, null, $type);

		if ($new_state === null)
		{
			return $cur_state;
		}

		// Save the new value only if it was set in this request.
		$this->setUserState($key, $new_state);

		return $new_state;
	}

	/**
	 * Initialise the application.
	 *
	 * @param   array  $options  An optional associative array of configuration settings.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	protected function initialiseApp($options = array())
	{
		// Check that we were given a language in the array (since by default may be blank).
		if (isset($options['language']))
		{
			$this->set('language', $options['language']);
		}

		// Build our language object
		$lang = Language::getInstance($this->get('language'), $this->get('debug_lang'));

		// Load the language to the API
		$this->loadLanguage($lang);

		// Register the language object with Factory
		Factory::$language = $this->getLanguage();

		// Load the library language files
		$this->loadLibraryLanguage();

		// Set user specific editor.
		$user = Factory::getUser();
		$editor = $user->getParam('editor', $this->get('editor'));

		if (!PluginHelper::isEnabled('editors', $editor))
		{
			$editor = $this->get('editor');

			if (!PluginHelper::isEnabled('editors', $editor))
			{
				$editor = 'none';
			}
		}

		$this->set('editor', $editor);

		// Load the behaviour plugins
		PluginHelper::importPlugin('behaviour');

		// Trigger the onAfterInitialise event.
		PluginHelper::importPlugin('system');
		$this->triggerEvent('onAfterInitialise');
	}

	/**
	 * Checks if HTTPS is forced in the client configuration.
	 *
	 * @param   integer  $clientId  An optional client id (defaults to current application client).
	 *
	 * @return  boolean  True if is forced for the client, false otherwise.
	 *
	 * @since   3.7.3
	 */
	public function isHttpsForced($clientId = null)
	{
		$clientId = (int) ($clientId !== null ? $clientId : $this->getClientId());
		$forceSsl = (int) $this->get('force_ssl');

		if ($clientId === 0 && $forceSsl === 2)
		{
			return true;
		}

		if ($clientId === 1 && $forceSsl >= 1)
		{
			return true;
		}

		return false;
	}

	/**
	 * Check the client interface by name.
	 *
	 * @param   string  $identifier  String identifier for the application interface
	 *
	 * @return  boolean  True if this application is of the given type client interface.
	 *
	 * @since   3.7.0
	 */
	public function isClient($identifier)
	{
		return $this->getName() === $identifier;
	}

	/**
	 * Load the library language files for the application
	 *
	 * @return  void
	 *
	 * @since   3.6.3
	 */
	protected function loadLibraryLanguage()
	{
		$this->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR);
	}

	/**
	 * Login authentication function.
	 *
	 * Username and encoded password are passed the onUserLogin event which
	 * is responsible for the user validation. A successful validation updates
	 * the current session record with the user's details.
	 *
	 * Username and encoded password are sent as credentials (along with other
	 * possibilities) to each observer (authentication plugin) for user
	 * validation.  Successful validation will update the current session with
	 * the user details.
	 *
	 * @param   array  $credentials  Array('username' => string, 'password' => string)
	 * @param   array  $options      Array('remember' => boolean)
	 *
	 * @return  boolean|\Exception  True on success, false if failed or silent handling is configured, or a \Exception object on authentication error.
	 *
	 * @since   3.2
	 */
	public function login($credentials, $options = array())
	{
		// Get the global Authentication object.
		$authenticate = Authentication::getInstance($this->authenticationPluginType);
		$response = $authenticate->authenticate($credentials, $options);

		// Import the user plugin group.
		PluginHelper::importPlugin('user');

		if ($response->status === Authentication::STATUS_SUCCESS)
		{
			/*
			 * Validate that the user should be able to login (different to being authenticated).
			 * This permits authentication plugins blocking the user.
			 */
			$authorisations = $authenticate->authorise($response, $options);
			$denied_states = Authentication::STATUS_EXPIRED | Authentication::STATUS_DENIED;

			foreach ($authorisations as $authorisation)
			{
				if ((int) $authorisation->status & $denied_states)
				{
					// Trigger onUserAuthorisationFailure Event.
					$this->triggerEvent('onUserAuthorisationFailure', array((array) $authorisation));

					// If silent is set, just return false.
					if (isset($options['silent']) && $options['silent'])
					{
						return false;
					}

					// Return the error.
					switch ($authorisation->status)
					{
						case Authentication::STATUS_EXPIRED:
							Factory::getApplication()->enqueueMessage(Text::_('JLIB_LOGIN_EXPIRED'), 'error');

							return false;

						case Authentication::STATUS_DENIED:
							Factory::getApplication()->enqueueMessage(Text::_('JLIB_LOGIN_DENIED'), 'error');

							return false;

						default:
							Factory::getApplication()->enqueueMessage(Text::_('JLIB_LOGIN_AUTHORISATION'), 'error');

							return false;
					}
				}
			}

			// OK, the credentials are authenticated and user is authorised.  Let's fire the onLogin event.
			$results = $this->triggerEvent('onUserLogin', array((array) $response, $options));

			/*
			 * If any of the user plugins did not successfully complete the login routine
			 * then the whole method fails.
			 *
			 * Any errors raised should be done in the plugin as this provides the ability
			 * to provide much more information about why the routine may have failed.
			 */
			$user = Factory::getUser();

			if ($response->type === 'Cookie')
			{
				$user->set('cookieLogin', true);
			}

			if (\in_array(false, $results, true) == false)
			{
				$options['user'] = $user;
				$options['responseType'] = $response->type;

				// The user is successfully logged in. Run the after login events
				$this->triggerEvent('onUserAfterLogin', array($options));

				return true;
			}
		}

		// Trigger onUserLoginFailure Event.
		$this->triggerEvent('onUserLoginFailure', array((array) $response));

		// If silent is set, just return false.
		if (isset($options['silent']) && $options['silent'])
		{
			return false;
		}

		// If status is success, any error will have been raised by the user plugin
		if ($response->status !== Authentication::STATUS_SUCCESS)
		{
			$this->getLogger()->warning($response->error_message, array('category' => 'jerror'));
		}

		return false;
	}

	/**
	 * Logout authentication function.
	 *
	 * Passed the current user information to the onUserLogout event and reverts the current
	 * session record back to 'anonymous' parameters.
	 * If any of the authentication plugins did not successfully complete
	 * the logout routine then the whole method fails. Any errors raised
	 * should be done in the plugin as this provides the ability to give
	 * much more information about why the routine may have failed.
	 *
	 * @param   integer  $userid   The user to load - Can be an integer or string - If string, it is converted to ID automatically
	 * @param   array    $options  Array('clientid' => array of client id's)
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.2
	 */
	public function logout($userid = null, $options = array())
	{
		// Get a user object from the \JApplication.
		$user = Factory::getUser($userid);

		// Build the credentials array.
		$parameters['username'] = $user->get('username');
		$parameters['id'] = $user->get('id');

		// Set clientid in the options array if it hasn't been set already and shared sessions are not enabled.
		if (!$this->get('shared_session', '0') && !isset($options['clientid']))
		{
			$options['clientid'] = $this->getClientId();
		}

		// Import the user plugin group.
		PluginHelper::importPlugin('user');

		// OK, the credentials are built. Lets fire the onLogout event.
		$results = $this->triggerEvent('onUserLogout', array($parameters, $options));

		// Check if any of the plugins failed. If none did, success.
		if (!\in_array(false, $results, true))
		{
			$options['username'] = $user->get('username');
			$this->triggerEvent('onUserAfterLogout', array($options));

			return true;
		}

		// Trigger onUserLogoutFailure Event.
		$this->triggerEvent('onUserLogoutFailure', array($parameters));

		return false;
	}

	/**
	 * Redirect to another URL.
	 *
	 * If the headers have not been sent the redirect will be accomplished using a "301 Moved Permanently"
	 * or "303 See Other" code in the header pointing to the new location. If the headers have already been
	 * sent this will be accomplished using a JavaScript statement.
	 *
	 * @param   string   $url     The URL to redirect to. Can only be http/https URL
	 * @param   integer  $status  The HTTP 1.1 status code to be provided. 303 is assumed by default.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	public function redirect($url, $status = 303)
	{
		// Persist messages if they exist.
		if (\count($this->messageQueue))
		{
			$this->getSession()->set('application.queue', $this->messageQueue);
		}

		// Hand over processing to the parent now
		parent::redirect($url, $status);
	}

	/**
	 * Rendering is the process of pushing the document buffers into the template
	 * placeholders, retrieving data from the document and pushing it into
	 * the application response buffer.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	protected function render()
	{
		// Setup the document options.
		$this->docOptions['template']         = $this->get('theme');
		$this->docOptions['file']             = $this->get('themeFile', 'index.php');
		$this->docOptions['params']           = $this->get('themeParams');
		$this->docOptions['csp_nonce']        = $this->get('csp_nonce');
		$this->docOptions['templateInherits'] = $this->get('themeInherits');

		if ($this->get('themes.base'))
		{
			$this->docOptions['directory'] = $this->get('themes.base');
		}
		// Fall back to constants.
		else
		{
			$this->docOptions['directory'] = \defined('JPATH_THEMES') ? JPATH_THEMES : (\defined('JPATH_BASE') ? JPATH_BASE : __DIR__) . '/themes';
		}

		// Parse the document.
		$this->document->parse($this->docOptions);

		// Trigger the onBeforeRender event.
		PluginHelper::importPlugin('system');
		$this->triggerEvent('onBeforeRender');

		$caching = false;

		if ($this->isClient('site') && $this->get('caching') && $this->get('caching', 2) == 2 && !Factory::getUser()->get('id'))
		{
			$caching = true;
		}

		// Render the document.
		$data = $this->document->render($caching, $this->docOptions);

		// Set the application output data.
		$this->setBody($data);

		// Trigger the onAfterRender event.
		$this->triggerEvent('onAfterRender');

		// Mark afterRender in the profiler.
		JDEBUG ? $this->profiler->mark('afterRender') : null;
	}

	/**
	 * Route the application.
	 *
	 * Routing is the process of examining the request environment to determine which
	 * component should receive the request. The component optional parameters
	 * are then set in the request object to be processed when the application is being
	 * dispatched.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	protected function route()
	{
		// Get the full request URI.
		$uri = clone Uri::getInstance();

		$router = static::getRouter();
		$result = $router->parse($uri, true);

		$active = $this->getMenu()->getActive();

		if ($active !== null
			&& $active->type === 'alias'
			&& $active->getParams()->get('alias_redirect')
			&& \in_array($this->input->getMethod(), array('GET', 'HEAD'), true))
		{
			$item = $this->getMenu()->getItem($active->getParams()->get('aliasoptions'));

			if ($item !== null)
			{
				$oldUri = clone Uri::getInstance();

				if ($oldUri->getVar('Itemid') == $active->id)
				{
					$oldUri->setVar('Itemid', $item->id);
				}

				$base = Uri::base(true);
				$oldPath = StringHelper::strtolower(substr($oldUri->getPath(), \strlen($base) + 1));
				$activePathPrefix = StringHelper::strtolower($active->route);

				$position = strpos($oldPath, $activePathPrefix);

				if ($position !== false)
				{
					$oldUri->setPath($base . '/' . substr_replace($oldPath, $item->route, $position, \strlen($activePathPrefix)));

					$this->setHeader('Expires', 'Wed, 17 Aug 2005 00:00:00 GMT', true);
					$this->setHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT', true);
					$this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0', false);
					$this->setHeader('Pragma', 'no-cache');
					$this->sendHeaders();

					$this->redirect((string) $oldUri, 301);
				}
			}
		}

		foreach ($result as $key => $value)
		{
			$this->input->def($key, $value);
		}

		if ($this->isTwoFactorAuthenticationRequired())
		{
			$this->redirectIfTwoFactorAuthenticationRequired();
		}

		// Trigger the onAfterRoute event.
		PluginHelper::importPlugin('system');
		$this->triggerEvent('onAfterRoute');
	}

	/**
	 * Sets the value of a user state variable.
	 *
	 * @param   string  $key    The path of the state.
	 * @param   mixed   $value  The value of the variable.
	 *
	 * @return  mixed|void  The previous state, if one existed.
	 *
	 * @since   3.2
	 */
	public function setUserState($key, $value)
	{
		$session = Factory::getSession();
		$registry = $session->get('registry');

		if ($registry !== null)
		{
			return $registry->set($key, $value);
		}

		return;
	}

	/**
	 * Sends all headers prior to returning the string
	 *
	 * @param   boolean  $compress  If true, compress the data
	 *
	 * @return  string
	 *
	 * @since   3.2
	 */
	public function toString($compress = false)
	{
		// Don't compress something if the server is going to do it anyway. Waste of time.
		if ($compress && !ini_get('zlib.output_compression') && ini_get('output_handler') !== 'ob_gzhandler')
		{
			$this->compress();
		}

		if ($this->allowCache() === false)
		{
			$this->setHeader('Cache-Control', 'no-cache', false);

			// HTTP 1.0
			$this->setHeader('Pragma', 'no-cache');
		}

		$this->sendHeaders();

		return $this->getBody();
	}

	/**
	 * Method to determine a hash for anti-spoofing variable names
	 *
	 * @param   boolean  $forceNew  If true, force a new token to be created
	 *
	 * @return  string  Hashed var name
	 *
	 * @since   4.0.0
	 */
	public function getFormToken($forceNew = false)
	{
		/** @var Session $session */
		$session = $this->getSession();

		return $session->getFormToken($forceNew);
	}

	/**
	 * Checks for a form token in the request.
	 *
	 * Use in conjunction with getFormToken.
	 *
	 * @param   string  $method  The request method in which to look for the token key.
	 *
	 * @return  boolean  True if found and valid, false otherwise.
	 *
	 * @since   4.0.0
	 */
	public function checkToken($method = 'post')
	{
		/** @var Session $session */
		$session = $this->getSession();

		return $session->checkToken($method);
	}

	/**
	 * Flag if the application instance is a CLI or web based application.
	 *
	 * Helper function, you should use the native PHP functions to detect if it is a CLI application.
	 *
	 * @return  boolean
	 *
	 * @since       4.0.0
	 * @deprecated  5.0  Will be removed without replacements
	 */
	public function isCli()
	{
		return false;
	}

	/**
	 * Checks if 2fa needs to be enforced
	 * if so returns true, else returns false
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 *
	 * @throws \Exception
	 */
	protected function isTwoFactorAuthenticationRequired(): bool
	{
		$userId = $this->getIdentity()->id;

		if (!$userId)
		{
			return false;
		}

		// Check session if user has set up 2fa
		if ($this->getSession()->has('has2fa'))
		{
			return false;
		}

		$enforce2faOptions = ComponentHelper::getComponent('com_users')->getParams()->get('enforce_2fa_options', 0);

		if ($enforce2faOptions == 0 || !$enforce2faOptions)
		{
			return false;
		}

		if (!PluginHelper::isEnabled('twofactorauth'))
		{
			return false;
		}

		$pluginsSiteEnable          = false;
		$pluginsAdministratorEnable = false;
		$pluginOptions              = PluginHelper::getPlugin('twofactorauth');

		// Sets and checks pluginOptions for Site and Administrator view depending on if any 2fa plugin is enabled for that view
		array_walk($pluginOptions,
			static function ($pluginOption) use (&$pluginsSiteEnable, &$pluginsAdministratorEnable)
			{
				$option  = new Registry($pluginOption->params);
				$section = $option->get('section', 3);

				switch ($section)
				{
					case 1:
						$pluginsSiteEnable = true;
						break;
					case 2:
						$pluginsAdministratorEnable = true;
						break;
					case 3:
					default:
						$pluginsAdministratorEnable = true;
						$pluginsSiteEnable          = true;
				}
			}
		);

		if ($pluginsSiteEnable && $this->isClient('site'))
		{
			if (\in_array($enforce2faOptions, [1, 3]))
			{
				return !$this->hasUserConfiguredTwoFactorAuthentication();
			}
		}

		if ($pluginsAdministratorEnable && $this->isClient('administrator'))
		{
			if (\in_array($enforce2faOptions, [2, 3]))
			{
				return !$this->hasUserConfiguredTwoFactorAuthentication();
			}
		}

		return false;
	}

	/**
	 * Redirects user to his Two Factor Authentication setup page
	 *
	 * @return void
	 *
	 * @since  4.0.0
	 */
	protected function redirectIfTwoFactorAuthenticationRequired(): void
	{
		$option = $this->input->get('option');
		$task   = $this->input->get('task');
		$view   = $this->input->get('view', null, 'STRING');
		$layout = $this->input->get('layout', null, 'STRING');

		if ($this->isClient('site'))
		{
			// If user is already on edit profile screen or press update/apply button, do nothing to avoid infinite redirect
			if (($option === 'com_users' && \in_array($task, ['profile.edit', 'profile.save', 'profile.apply', 'user.logout', 'user.menulogout'], true))
				|| $option === 'com_users' && $view === 'profile' && $layout === 'edit')
			{
				return;
			}

			// Redirect to com_users profile edit
			$this->enqueueMessage(Text::_('JENFORCE_2FA_REDIRECT_MESSAGE'), 'notice');
			$this->redirect('index.php?option=com_users&view=profile&layout=edit');
		}

		if (($option === 'com_users' && \in_array($task, ['user.save', 'user.edit', 'user.apply', 'user.logout', 'user.menulogout'], true))
			|| ($option === 'com_users' && $view === 'user' && $layout === 'edit')
			|| ($option === 'com_login' && \in_array($task, ['save', 'edit', 'apply', 'logout', 'menulogout'], true)))
		{
			return;
		}

		// Redirect to com_admin profile edit
		$this->enqueueMessage(Text::_('JENFORCE_2FA_REDIRECT_MESSAGE'), 'notice');
		$this->redirect('index.php?option=com_users&task=user.edit&id=' . $this->getIdentity()->id);
	}

	/**
	 * Checks if otpKey and otep for the user are not empty
	 * if any one is empty returns false, else returns true
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 *
	 * @throws \Exception
	 */
	private function hasUserConfiguredTwoFactorAuthentication(): bool
	{
		$user = $this->getIdentity();

		if (empty($user->otpKey) || empty($user->otep))
		{
			return false;
		}

		// Set session to user has configured 2fa
		$this->getSession()->set('has2fa', 1);

		return true;
	}

	/**
	 * Setup logging functionality.
	 *
	 * @return void
	 *
	 * @since   4.0.0
	 */
	private function setupLogging(): void
	{
		// Add InMemory logger that will collect all log entries to allow to display them later by extensions
		if ($this->get('debug'))
		{
			Log::addLogger(['logger' => 'inmemory']);
		}

		// Log the deprecated API.
		if ($this->get('log_deprecated'))
		{
			Log::addLogger(['text_file' => 'deprecated.php'], Log::ALL, ['deprecated']);
		}

		// We only log errors unless Site Debug is enabled
		$logLevels = Log::ERROR | Log::CRITICAL | Log::ALERT | Log::EMERGENCY;

		if ($this->get('debug'))
		{
			$logLevels = Log::ALL;
		}

		Log::addLogger(['text_file' => 'joomla_core_errors.php'], $logLevels, ['system']);

		// Log everything (except deprecated APIs, these are logged separately with the option above).
		if ($this->get('log_everything'))
		{
			Log::addLogger(['text_file' => 'everything.php'], Log::ALL, ['deprecated', 'deprecation-notes', 'databasequery'], true);
		}

		if ($this->get('log_categories'))
		{
			$priority = 0;

			foreach ($this->get('log_priorities', ['all']) as $p)
			{
				$const = '\\Joomla\\CMS\\Log\\Log::' . strtoupper($p);

				if (defined($const))
				{
					$priority |= constant($const);
				}
			}

			// Split into an array at any character other than alphabet, numbers, _, ., or -
			$categories = preg_split('/[^\w.-]+/', $this->get('log_categories', ''), -1, PREG_SPLIT_NO_EMPTY);
			$mode       = (bool) $this->get('log_category_mode', false);

			if (!$categories)
			{
				return;
			}

			Log::addLogger(['text_file' => 'custom-logging.php'], $priority, $categories, $mode);
		}
	}
}
