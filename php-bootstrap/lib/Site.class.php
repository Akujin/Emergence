<?php

namespace Emergence;

class Site
{
	// config properties
	static public $debug = true;
	static public $production = false;
	static public $defaultPage = 'home.php';
	static public $autoCreateSession = true;
	static public $listCollections = false;
	static public $onInitialized;
	static public $onNotFound;
	static public $onRequestMapped;

	// public properties
	//static public $ID;
	static public $Title;
	static public $rootPath;

	static public $webmasterEmail = 'errors@chrisrules.com';

	static public $requestURI;
	static public $requestPath;
	static public $pathStack;

	static public $config;
	static public $time;
	static public $queryBreaker;

	// protected properties
	static protected $_rootCollections;

	
	static public function initialize()
	{
		static::$time = microtime(true);
		
		// resolve details from host name
		
		// get site ID
/*
		if(empty(static::$ID))
		{
			if(!empty($_SERVER['SITE_ID']))
				static::$ID = $_SERVER['SITE_ID'];
			else
				throw new Exception('No Site ID detected');
		}
*/
		// get site root
		if(empty(static::$rootPath))
		{
			if(!empty($_SERVER['SITE_ROOT']))
				static::$rootPath = $_SERVER['SITE_ROOT'];
			else
				throw new Exception('No Site root detected');
		}

		// load config
		if(!(static::$config = apc_fetch($_SERVER['HTTP_HOST'])))
		{
            if(is_readable(static::$rootPath.'/site.json'))
            {
			    static::$config = json_decode(file_get_contents(static::$rootPath.'/site.json'), true);
			    apc_store($_SERVER['HTTP_HOST'], static::$config);
            }
            else if(is_readable(static::$rootPath.'/Site.config.php'))
            {
                include(static::$rootPath.'/Site.config.php');
                apc_store($_SERVER['HTTP_HOST'], static::$config);
            }
		}
		
		
		// get path stack
		$path = $_SERVER['REQUEST_URI'];
		
		if(false !== ($qPos = strpos($path,'?')))
		{
			$path = substr($path, 0, $qPos);
		}

		static::$pathStack = static::$requestPath = static::splitPath($path);

		if(!empty($_COOKIE['debugpath']))
		{
			MICS::dump(static::$pathStack, 'pathStack', true);
		}

		// set useful transaction name for newrelic
		if(extension_loaded('newrelic'))
		{
			newrelic_name_transaction (static::$config['handle'] . '/' . implode('/', site::$requestPath));
		}

		// register class loader
		spl_autoload_register('Emergence\\Site::loadClass');

		// set error handle
		set_error_handler('Emergence\\Site::handleError');
		
		// register exception handler
		set_exception_handler('Emergence\\Site::handleException');

		// check virtual system for site config
		static::loadConfig(__CLASS__);
		
		if(is_callable(static::$onInitialized))
			call_user_func(static::$onInitialized);
	}

	
	static public function handleRequest()
	{
		// handle emergence request
		if(static::$pathStack[0] == 'emergence')
		{
			array_shift(static::$pathStack);
			return Emergence::handleRequest();
		}

		// try to resolve URL in site-root
		$rootNode = static::getRootCollection('site-root');
		$resolvedNode = $rootNode;
		$resolvedPath = array();

		// handle default page request
		if(empty(static::$pathStack[0]) && static::$defaultPage)
		{
			static::$pathStack[0] = static::$defaultPage;
		}

		// crawl down path stack until a handler is found
		while(($handle = array_shift(static::$pathStack)))
		{
			$scriptHandle = (substr($handle,-4)=='.php') ? $handle : $handle.'.php';

			//printf('%s: (%s)/(%s) - %s<br>', $resolvedNode->Handle, $handle, implode('/',static::$pathStack), $scriptHandle);
			if(
				(
					$resolvedNode
					&& method_exists($resolvedNode, 'getChild')
					&& (
						($childNode = $resolvedNode->getChild($handle))
						|| ($scriptHandle && $childNode = $resolvedNode->getChild($scriptHandle))
					)
				)
				|| ($childNode = Emergence::resolveFileFromParent('site-root', array_merge($resolvedPath,array($handle))))
				|| ($scriptHandle && $childNode = Emergence::resolveFileFromParent('site-root', array_merge($resolvedPath,array($scriptHandle))))
			)
			{
				$resolvedNode = $childNode;
				
				if(is_a($resolvedNode, 'Emergence\\SiteFile'))
				{
					break;
				}
			}
			else
			{
				$resolvedNode = false;
				//break;
			}
			
			$resolvedPath[] = $handle;
		}

		
		if($resolvedNode)
		{
			// create session
			if(static::$autoCreateSession && $resolvedNode->MIMEType == 'application/php')
			{
				$GLOBALS['Session'] = \UserSession::getFromRequest();
			}

			if(is_callable(static::$onRequestMapped))
			{
				call_user_func(static::$onRequestMapped, $resolvedNode);
			}

			if($resolvedNode->MIMEType == 'application/php')
			{
				require($resolvedNode->RealPath);
				exit();
			}
			elseif(is_callable(array($resolvedNode, 'outputAsResponse')))
			{
				if(!is_a($resolvedNode, 'Emergence\\SiteFile') && !static::$listCollections)
				{
					static::respondNotFound();
				}
				
				$resolvedNode->outputAsResponse();	
			}
			else
			{
				static::respondNotFound();
			}
		}
		else
		{
			static::respondNotFound();
		}
	}
	
	static public function resolvePath($path, $checkParent = true, $checkCache = true)
	{
		if(is_string($path) && (empty($path) || $path == '/'))
		{
			return new SiteDavDirectory();
		}

		if(!is_array($path))
			$path = static::splitPath($path);

		$cacheKey = ($checkParent ? 'efs' : 'efsi') . ':' . $_SERVER['HTTP_HOST'] . '//' . join('/', $path);

		if($checkCache && Site::$production && false !== ($node = apc_fetch($cacheKey)))
		{
			//MICS::dump($node, 'cache hit: '.$cacheKey, true);
			return $node;
		}
			
		$collectionHandle = array_shift($path);
		
		// get collection
		if(!$collectionHandle || !$collection = static::getRootCollection($collectionHandle))
		{
			throw new Exception('Could not resolve root collection: '.$collectionHandle);
		}

		// get file
		$node = $collection->resolvePath($path);

		// try to get from parent
		if(!$node && $checkParent)
		{
			$node = Emergence::resolveFileFromParent($collectionHandle, $path);
		}
		
		if(!$node)
			$node = null;
			
		if(Site::$production)
			apc_store($cacheKey, $node);

		return $node;
	}
	
	
	static protected $_loadedClasses = array();
	
	static public function loadClass($className)
	{
		//printf("loadClass(%s) -> %s<br/>\n", $className, var_export(class_exists($className, false), true));

		// skip if already loaded
		if(
			class_exists($className, false)
			|| interface_exists($className, false)
			|| in_array($className, static::$_loadedClasses)
		)
		{
			return;
		}
		
		// PSR-0 support
		if ($lastNsPos = strrpos($className, '\\')) {
	        $namespace = substr($className, 0, $lastNsPos);
	        $className = substr($className, $lastNsPos + 1);
	        $fileName  = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
	    }
	    $fileName .= str_replace('_', DIRECTORY_SEPARATOR, $className);
		
		// try to load class via PSR-0
		//print("Trying to resolve php-classes/$fileName.class.php<br/>");
		$classNode = static::resolvePath("php-classes/$fileName.class.php");
		if(!$classNode)
		{
			// try to load class flatly
			//print("Trying to resolve php-classes/$className.class.php<br/>");
			$classNode = static::resolvePath("php-classes/$className.class.php");
		}

		if(!$classNode)
		{
			echo "Unable to load class '$className'<br>\n";	
			if(static::$debug)
			{
				echo '<pre>';
				debug_print_backtrace();
				echo '</pre>';
			}
			exit;
		}
		elseif(!$classNode->MIMEType == 'application/php')
		{
			//die("Class file for '$className' is not application/php");
		}
		
		// add to loaded class queue
		static::$_loadedClasses[] = $className;
		
		//print "...loadClass($className) -> $classNode->RealPath<br/>";
		if(is_readable($classNode->RealPath))
		{
			require($classNode->RealPath);
		}
		else
		{
			trigger_error('Failed to read (' . $classNode->RealPath . ') from called SiteFile node.');
		}

		// try to load config
		static::loadConfig($className);
		
		// invoke __classLoaded
		if(method_exists($className, '__classLoaded'))
		{
			call_user_func(array($className, '__classLoaded'));
		}

		
		//Debug::dump($classNode);
	}
	
	static public function loadConfig($className)
	{
		// PSR-0 support
		if ($lastNsPos = strrpos($className, '\\')) {
	        $namespace = substr($className, 0, $lastNsPos);
	        $className = substr($className, $lastNsPos + 1);
	        $fileName  = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
	    }
	    $fileName .= str_replace('_', DIRECTORY_SEPARATOR, $className);

	    $configNode = static::resolvePath("php-config/$fileName.config.php");
		if(!$configNode)
		{
			if(!$configNode->MIMEType == 'application/php')
			{
				//trigger_error('Config file for "'.$fileName.'" is not application/php',E_USER_NOTICE);
			}
			else
			{
				require($configNode->RealPath);
			}
		}
		else if($configNode = static::resolvePath("php-config/$className.config.php"))
		{
			if(!$configNode->MIMEType == 'application/php')
			{
				//trigger_error('Config file for "'.$className.'" is not application/php',E_USER_NOTICE);
			}
			else
			{
				require($configNode->RealPath);
			}
		}
	}
	
	
	static public function handleError($errno, $errstr, $errfile, $errline)
	{
		if(!(error_reporting() & $errno))
			return;
		
		if(substr($errfile, 0, strlen(static::$rootPath)) == static::$rootPath)
		{
			$fileID = substr(strrchr($errfile, '/'), 1);
			$File = SiteFile::getByID($fileID);

			$errfile .= ' ('.$File->Handle.')';
		}
			
		die("<h1>Error</h1><p>$errstr</p><p><b>Source:</b> $errfile<br /><b>Line:</b> $errline<br /><b>Author:</b> {$File->Author->Username}<br /><b>Timestamp:</b> ".date('Y-m-d h:i:s', $File->Timestamp)."</p>");
	}
	
	static public function handleException($e)
	{
		if(extension_loaded('newrelic'))
		{
			newrelic_notice_error(null, $e);
		}
		
		if(!headers_sent())
		{
			header('Status: 500 Internal Server Error');
		}
		die('<h1>Unhandled Exception</h1><p>'.get_class($e).': '.$e->getMessage().'</p><h1>Backtrace:</h1><pre>'.$e->getTraceAsString().'</pre><h1>Exception Dump</h1><pre>'.print_r($e,true).'</pre>');
	}
	
	static public function respondNotFound($message = 'Page not found')
	{
		if(is_callable(static::$onNotFound))
		{
			call_user_func(static::$onNotFound, $message);
		}
		else
		{
			header('HTTP/1.0 404 Not Found');
			die($message);
		}
	}
	
	static public function respondBadRequest($message = 'Cannot display resource')
	{
		header('HTTP/1.0 400 Bad Request');
		die($message);
	}
	
	static public function respondUnauthorized($message = 'Access denied')
	{
		header('HTTP/1.0 403 Forbidden');
		die($message);
	}
	
	
	static public function getRootCollection($handle)
	{
		if(!empty(static::$_rootCollections[$handle]))
			return static::$_rootCollections[$handle];

		return static::$_rootCollections[$handle] = SiteCollection::getOrCreateRootCollection($handle);
	}


	static public function splitPath($path)
	{
		return explode('/', ltrim($path, '/'));
	}
	
	static public function redirect($path, $get = false, $hash = false)
	{
		if(is_array($path)) $path = implode('/', $path);
		
		if(preg_match('/^https?:\/\//i', $path))
			$url = $path;
		else
			$url = ($_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($path, '/');

		if($get)
		{
			$url .= '?' . (is_array($get) ? http_build_query($get) : $get);
		}
	
		if($hash)
		{
			$url .= '#' . $hash;	
		}
		
		header('Location: ' . $url);
		exit();
	}

	static public function redirectPermanent($path, $get = false, $hash = false)
	{
		header('HTTP/1.1 301 Moved Permanently');
		return static::redirect($path, $get, $hash);
	}

	static public function getPath($index = null)
	{
		if($index === null)
			return static::$requestPath;
		else
			return static::$requestPath[$index];
	}

	static public function matchPath($index, $string)
	{
		return 0==strcasecmp(static::getPath($index), $string);
	}
	
	/*
	 * TODO: Auto-detect calling class/method for default title
	 */
	static public function dump($value, $title = 'Dump', $exit = false, $backtrace = false) {
		printf("<h2>%s:</h2><pre>%s</pre>", $title, htmlspecialchars(var_export($value, true)));
		
		if($backtrace)
		{
			print('<hr><pre>');debug_print_backtrace();print('</pre>');
		}
		
		if ($exit)
			exit();
			
		return $value;
	}
	static public function prepareOptions($value, $defaults = array())
	{
		if(is_string($value))
		{
			$value = json_decode($value, true);
		}
		
		return is_array($value) ? array_merge($defaults, $value) : $defaults;
	}
}
